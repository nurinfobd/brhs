<?php
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit;
}

require __DIR__ . '/_lib/config.php';
require __DIR__ . '/_lib/db.php';
require __DIR__ . '/_lib/store.php';
require __DIR__ . '/_lib/mikrotik.php';

try {
    db_migrate();
} catch (Throwable $e) {
    $cfg = app_config();
    $db = is_array($cfg['db'] ?? null) ? $cfg['db'] : [];
    $host = (string)($db['host'] ?? '');
    $port = (string)($db['port'] ?? '');
    $name = (string)($db['name'] ?? '');
    $user = (string)($db['user'] ?? '');
    fwrite(STDERR, "DB migrate failed\n");
    fwrite(STDERR, "DB: host={$host} port={$port} name={$name} user={$user}\n");
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Hint: If your MySQL is on 3306, either edit admin/_lib/config.php or set env CITYU_DB_PORT=3306\n");
    exit(1);
}

function radius_parse_packet(string $buf): ?array
{
    if (strlen($buf) < 20) {
        return null;
    }
    $code = ord($buf[0]);
    $id = ord($buf[1]);
    $len = unpack('n', substr($buf, 2, 2))[1] ?? 0;
    if ($len < 20 || $len > strlen($buf)) {
        return null;
    }
    $auth = substr($buf, 4, 16);
    $attrData = substr($buf, 20, $len - 20);
    $attrs = [];
    $p = 0;
    $attrLen = strlen($attrData);
    while ($p + 2 <= $attrLen) {
        $t = ord($attrData[$p]);
        $l = ord($attrData[$p + 1]);
        if ($l < 2 || $p + $l > $attrLen) {
            break;
        }
        $v = substr($attrData, $p + 2, $l - 2);
        if (!isset($attrs[$t])) {
            $attrs[$t] = [];
        }
        $attrs[$t][] = $v;
        $p += $l;
    }
    return ['code' => $code, 'id' => $id, 'len' => $len, 'auth' => $auth, 'attrs' => $attrs];
}

function radius_attr_first(array $attrs, int $type): ?string
{
    $v = $attrs[$type] ?? null;
    if (!is_array($v) || count($v) === 0) {
        return null;
    }
    $first = $v[0] ?? null;
    return is_string($first) ? $first : null;
}

function radius_pap_decrypt(string $cipher, string $secret, string $requestAuth): string
{
    $out = '';
    $prev = $requestAuth;
    $n = strlen($cipher);
    $blocks = (int)ceil($n / 16);
    for ($i = 0; $i < $blocks; $i++) {
        $c = substr($cipher, $i * 16, 16);
        $c = str_pad($c, 16, "\0", STR_PAD_RIGHT);
        $h = md5($secret . $prev, true);
        $p = $c ^ $h;
        $out .= $p;
        $prev = $c;
    }
    return rtrim($out, "\0");
}

function radius_attr(int $type, string $value): string
{
    $len = 2 + strlen($value);
    if ($len > 255) {
        $value = substr($value, 0, 253);
        $len = 255;
    }
    return chr($type) . chr($len) . $value;
}

function radius_vsa(int $vendorId, int $vendorType, string $vendorValue): string
{
    $innerLen = 2 + strlen($vendorValue);
    if ($innerLen > 255) {
        $vendorValue = substr($vendorValue, 0, 253);
        $innerLen = 255;
    }
    $v = pack('N', $vendorId) . chr($vendorType) . chr($innerLen) . $vendorValue;
    return radius_attr(26, $v);
}

function radius_vsa_int(int $vendorId, int $vendorType, int $value): string
{
    $v = pack('N', $value & 0xFFFFFFFF);
    return radius_vsa($vendorId, $vendorType, $v);
}

function u32_from_bin(?string $b): int
{
    if (!is_string($b) || strlen($b) !== 4) {
        return 0;
    }
    $n = unpack('N', $b)[1] ?? 0;
    return (int)$n;
}

function u64_from_octets(int $octets32, int $gigawords): int
{
    $octets32 = $octets32 & 0xFFFFFFFF;
    $gigawords = $gigawords & 0xFFFFFFFF;
    $base = 4294967296;
    return (int)($gigawords * $base + $octets32);
}

function radius_build_response(int $code, int $id, string $requestAuth, string $attrsBin, string $secret): string
{
    $len = 20 + strlen($attrsBin);
    $hdr = chr($code) . chr($id) . pack('n', $len);
    $toHash = $hdr . $requestAuth . $attrsBin . $secret;
    $auth = md5($toHash, true);
    return $hdr . $auth . $attrsBin;
}

function radius_attrs_to_json(array $attrs): string
{
    $out = [];
    foreach ($attrs as $k => $vals) {
        if (!is_array($vals)) {
            continue;
        }
        $arr = [];
        foreach ($vals as $v) {
            if (!is_string($v)) {
                continue;
            }
            $arr[] = bin2hex($v);
        }
        $out[(string)$k] = $arr;
    }
    return json_encode($out, JSON_UNESCAPED_SLASHES) ?: '';
}

function radius_norm_mac(string $s): string
{
    $t = strtoupper(trim($s));
    if ($t === '') {
        return '';
    }
    $hex = preg_replace('/[^0-9A-F]/', '', $t);
    if (!is_string($hex) || strlen($hex) !== 12) {
        return trim($s);
    }
    return substr($hex, 0, 2) . ':' . substr($hex, 2, 2) . ':' . substr($hex, 4, 2) . ':' . substr($hex, 6, 2) . ':' . substr($hex, 8, 2) . ':' . substr($hex, 10, 2);
}

function radius_decode_ipv4(?string $b): string
{
    if (!is_string($b) || strlen($b) !== 4) {
        return '';
    }
    $u = unpack('C4', $b);
    if (!is_array($u)) {
        return '';
    }
    $a = (int)($u[1] ?? -1);
    $c = (int)($u[2] ?? -1);
    $d = (int)($u[3] ?? -1);
    $e = (int)($u[4] ?? -1);
    if ($a < 0 || $a > 255 || $c < 0 || $c > 255 || $d < 0 || $d > 255 || $e < 0 || $e > 255) {
        return '';
    }
    return $a . '.' . $c . '.' . $d . '.' . $e;
}

function radius_dbg(string $msg): void
{
    $v = getenv('CITYU_RADIUS_DEBUG');
    if (!is_string($v) || $v === '' || $v === '0') {
        return;
    }
    $ts = gmdate('Y-m-d H:i:s');
    fwrite(STDOUT, "[{$ts}] {$msg}\n");
}

$bindIp = '0.0.0.0';
$authPort = 1812;
$acctPort = 1813;

$sockAuth = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
$sockAcct = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
if ($sockAuth === false || $sockAcct === false) {
    fwrite(STDERR, "socket_create failed\n");
    exit(1);
}

@socket_set_option($sockAuth, SOL_SOCKET, SO_REUSEADDR, 1);
@socket_set_option($sockAcct, SOL_SOCKET, SO_REUSEADDR, 1);

if (!@socket_bind($sockAuth, $bindIp, $authPort)) {
    fwrite(STDERR, "bind failed (auth)\n");
    exit(1);
}
if (!@socket_bind($sockAcct, $bindIp, $acctPort)) {
    fwrite(STDERR, "bind failed (acct)\n");
    exit(1);
}

fwrite(STDOUT, "RADIUS started on {$bindIp}:{$authPort}/{$acctPort}\n");

while (true) {
    $read = [$sockAuth, $sockAcct];
    $write = [];
    $except = [];
    $n = @socket_select($read, $write, $except, 1);
    if ($n === false || $n === 0) {
        continue;
    }

    foreach ($read as $sock) {
        $buf = '';
        $from = '';
        $fromPort = 0;
        $recv = @socket_recvfrom($sock, $buf, 4096, 0, $from, $fromPort);
        if ($recv === false || !is_string($buf) || $buf === '') {
            continue;
        }

        $packet = radius_parse_packet($buf);
        if (!is_array($packet)) {
            continue;
        }

        $code = (int)($packet['code'] ?? 0);
        $id = (int)($packet['id'] ?? 0);
        $reqAuth = (string)($packet['auth'] ?? '');
        $attrs = (array)($packet['attrs'] ?? []);

        $peerIp = $from;
        $nasIp = radius_decode_ipv4(radius_attr_first($attrs, 4));
        $routerIp = $peerIp;
        $router = store_find_router_by_ip($routerIp);
        if (!is_array($router) && $nasIp !== '' && $nasIp !== $peerIp) {
            $routerIp = $nasIp;
            $router = store_find_router_by_ip($routerIp);
        }
        if (!is_array($router)) {
            $nasDbg = $nasIp !== '' ? $nasIp : '-';
            radius_dbg("drop: unknown router peer_ip={$peerIp} nas_ip={$nasDbg}");
            store_insert_app_log('warning', 'radius', 'drop unknown router', ['peer_ip' => $peerIp, 'nas_ip' => $nasDbg, 'code' => $code]);
            if ($code === 4) {
                store_insert_radius_accounting_error([
                    'error_type' => 'warning',
                    'router_ip' => '',
                    'peer_ip' => $peerIp,
                    'nas_ip' => $nasDbg !== '-' ? $nasDbg : '',
                    'message' => 'Accounting dropped: unknown router',
                    'raw_attrs' => radius_attrs_to_json($attrs),
                ]);
            }
            continue;
        }
        $router = router_normalize($router);
        if ((int)($router['radius_enabled'] ?? 0) !== 1) {
            radius_dbg("drop: router_ip={$routerIp} peer_ip={$peerIp} radius_disabled=1");
            store_insert_app_log('warning', 'radius', 'drop router disabled', ['router_ip' => $routerIp, 'peer_ip' => $peerIp, 'code' => $code]);
            if ($code === 4) {
                store_insert_radius_accounting_error([
                    'error_type' => 'warning',
                    'router_ip' => $routerIp,
                    'peer_ip' => $peerIp,
                    'nas_ip' => $routerIp,
                    'message' => 'Accounting dropped: router RADIUS disabled',
                    'raw_attrs' => radius_attrs_to_json($attrs),
                ]);
            }
            continue;
        }
        $secret = (string)($router['radius_secret'] ?? '');
        if ($secret === '') {
            radius_dbg("drop: router_ip={$routerIp} peer_ip={$peerIp} missing_secret");
            store_insert_app_log('warning', 'radius', 'drop router missing secret', ['router_ip' => $routerIp, 'peer_ip' => $peerIp, 'code' => $code]);
            if ($code === 4) {
                store_insert_radius_accounting_error([
                    'error_type' => 'warning',
                    'router_ip' => $routerIp,
                    'peer_ip' => $peerIp,
                    'nas_ip' => $routerIp,
                    'message' => 'Accounting dropped: router secret missing',
                    'raw_attrs' => radius_attrs_to_json($attrs),
                ]);
            }
            continue;
        }

        if ($code === 1) {
            $user = radius_attr_first($attrs, 1);
            $passEnc = radius_attr_first($attrs, 2);
            if (!is_string($user) || $user === '') {
                radius_dbg("reject: router_ip={$routerIp} peer_ip={$peerIp} reason=missing_username");
                store_insert_app_log('warning', 'radius', 'reject missing username', ['router_ip' => $routerIp, 'peer_ip' => $peerIp]);
                $resp = radius_build_response(3, $id, $reqAuth, radius_attr(18, 'Invalid username'), $secret);
                @socket_sendto($sockAuth, $resp, strlen($resp), 0, $peerIp, $fromPort);
                continue;
            }

            if (!is_string($passEnc) || $passEnc === '') {
                $chapPass = radius_attr_first($attrs, 3);
                if (is_string($chapPass) && $chapPass !== '') {
                    radius_dbg("reject: router_ip={$routerIp} peer_ip={$peerIp} user=" . trim($user) . " reason=chap_not_supported");
                    store_insert_app_log('warning', 'radius', 'reject chap not supported', ['router_ip' => $routerIp, 'peer_ip' => $peerIp, 'user' => trim((string)$user)]);
                    $resp = radius_build_response(3, $id, $reqAuth, radius_attr(18, 'CHAP not supported. Enable Hotspot HTTP PAP.'), $secret);
                    @socket_sendto($sockAuth, $resp, strlen($resp), 0, $peerIp, $fromPort);
                    continue;
                }
                radius_dbg("reject: router_ip={$routerIp} peer_ip={$peerIp} user=" . trim($user) . " reason=password_missing");
                store_insert_app_log('warning', 'radius', 'reject password missing', ['router_ip' => $routerIp, 'peer_ip' => $peerIp, 'user' => trim((string)$user)]);
                $resp = radius_build_response(3, $id, $reqAuth, radius_attr(18, 'Password missing'), $secret);
                @socket_sendto($sockAuth, $resp, strlen($resp), 0, $peerIp, $fromPort);
                continue;
            }

            $userRaw = trim($user);
            $userNorm = radius_norm_mac($userRaw);
            $pass = radius_pap_decrypt($passEnc, $secret, $reqAuth);
            $passRaw = (string)$pass;
            $passNorm = radius_norm_mac($passRaw);

            $u = store_find_radius_user_by_username($userNorm);
            if (!is_array($u) && $userNorm !== $userRaw) {
                $u = store_find_radius_user_by_username($userRaw);
            }
            $ok = false;
            $profile = '';
            $rateLimit = '';
            $quotaBytes = 0;
            $replyMsg = 'Invalid username/password';
            if (is_array($u)) {
                $dbUser = trim((string)($u['username'] ?? ''));
                if ($dbUser === '') {
                    $dbUser = $userNorm !== '' ? $userNorm : $userRaw;
                }
                if ((int)($u['disabled'] ?? 0) === 1) {
                    $replyMsg = 'User disabled';
                } else {
                    $hash = (string)($u['password_hash'] ?? '');
                    $passOk = false;
                    if ($hash !== '' && password_verify($passRaw, $hash)) {
                        $passOk = true;
                    } elseif ($hash !== '' && $passNorm !== $passRaw && password_verify($passNorm, $hash)) {
                        $passOk = true;
                    }
                    if ($passOk) {
                        $ok = true;
                        $profile = trim((string)($u['profile'] ?? ''));
                        $userQuota = (int)($u['quota_bytes'] ?? 0);
                        $quotaBytes = $userQuota > 0 ? $userQuota : 0;

                        $routerId = (string)($router['id'] ?? '');
                        if ($routerId !== '' && $profile !== '') {
                            $pl = store_get_hotspot_profile_limit($routerId, $profile);
                            if (is_array($pl)) {
                                if ($rateLimit === '') {
                                    $rateLimit = trim((string)($pl['rate_limit'] ?? ''));
                                }
                                if ($quotaBytes <= 0) {
                                    $pq = (int)($pl['quota_bytes'] ?? 0);
                                    $quotaBytes = $pq > 0 ? $pq : 0;
                                }
                            }
                        }

                    }
                }
            }

            if ($ok && $quotaBytes > 0) {
                $used = store_get_radius_user_usage_bytes($dbUser);
                if ($used >= $quotaBytes) {
                    $ok = false;
                    $replyMsg = 'Quota exceeded';
                }
            }

            if ($ok) {
                radius_dbg("accept: router_ip={$routerIp} peer_ip={$peerIp} user={$userRaw} db_user={$dbUser} profile={$profile} quota={$quotaBytes}");
                store_insert_app_log('info', 'radius', 'accept', ['router_ip' => $routerIp, 'peer_ip' => $peerIp, 'user' => $userRaw, 'db_user' => $dbUser, 'profile' => $profile, 'quota_bytes' => $quotaBytes]);
            } else {
                $uState = is_array($u) ? 'found' : 'not_found';
                radius_dbg("reject: router_ip={$routerIp} peer_ip={$peerIp} user={$userRaw} norm={$userNorm} user_state={$uState} reason=" . str_replace(' ', '_', $replyMsg));
                store_insert_app_log('warning', 'radius', 'reject', ['router_ip' => $routerIp, 'peer_ip' => $peerIp, 'user' => $userRaw, 'user_state' => $uState, 'reason' => $replyMsg]);
            }

            $respAttrs = '';
            if ($ok && $profile !== '') {
                $respAttrs .= radius_vsa(14988, 3, $profile);
            }
            if ($ok && $rateLimit !== '') {
                $respAttrs .= radius_vsa(14988, 8, $rateLimit);
            }
            if ($ok && $quotaBytes > 0) {
                $used = store_get_radius_user_usage_bytes($dbUser);
                $remain = $quotaBytes - $used;
                if ($remain < 0) {
                    $remain = 0;
                }
                $low = $remain & 0xFFFFFFFF;
                $high = (int)floor($remain / 4294967296);
                $respAttrs .= radius_vsa_int(14988, 17, $low);
                $respAttrs .= radius_vsa_int(14988, 18, $high);

                $respAttrs .= radius_vsa_int(14988, 1, $low);
                $respAttrs .= radius_vsa_int(14988, 14, $high);
                $respAttrs .= radius_vsa_int(14988, 2, $low);
                $respAttrs .= radius_vsa_int(14988, 15, $high);
            }
            if (!$ok) {
                $respAttrs .= radius_attr(18, $replyMsg);
            }
            $resp = radius_build_response($ok ? 2 : 3, $id, $reqAuth, $respAttrs, $secret);
            @socket_sendto($sockAuth, $resp, strlen($resp), 0, $peerIp, $fromPort);
            continue;
        }

        if ($code === 4) {
            $user = radius_attr_first($attrs, 1) ?? '';
            $statusType = '';
            $st = radius_attr_first($attrs, 40);
            if (is_string($st) && strlen($st) === 4) {
                $statusTypeNum = unpack('N', $st)[1] ?? 0;
                $map = [1 => 'Start', 2 => 'Stop', 3 => 'Interim-Update', 7 => 'Accounting-On', 8 => 'Accounting-Off'];
                $statusType = (string)($map[(int)$statusTypeNum] ?? (string)$statusTypeNum);
            }
            $sid = radius_attr_first($attrs, 44) ?? '';
            $inOct = 0;
            $outOct = 0;
            $inGw = 0;
            $outGw = 0;
            $sessTime = 0;
            $io = radius_attr_first($attrs, 42);
            if (is_string($io) && strlen($io) === 4) {
                $inOct = (int)(unpack('N', $io)[1] ?? 0);
            }
            $oo = radius_attr_first($attrs, 43);
            if (is_string($oo) && strlen($oo) === 4) {
                $outOct = (int)(unpack('N', $oo)[1] ?? 0);
            }
            $ig = radius_attr_first($attrs, 52);
            if (is_string($ig) && strlen($ig) === 4) {
                $inGw = (int)(unpack('N', $ig)[1] ?? 0);
            }
            $og = radius_attr_first($attrs, 53);
            if (is_string($og) && strlen($og) === 4) {
                $outGw = (int)(unpack('N', $og)[1] ?? 0);
            }
            $stt = radius_attr_first($attrs, 46);
            if (is_string($stt) && strlen($stt) === 4) {
                $sessTime = (int)(unpack('N', $stt)[1] ?? 0);
            }

            $uNameRaw = is_string($user) ? trim($user) : '';
            $uNameNorm = radius_norm_mac($uNameRaw);
            $sessId = is_string($sid) ? $sid : '';
            $inTotal = u64_from_octets($inOct, $inGw);
            $outTotal = u64_from_octets($outOct, $outGw);
            $deltaTotal = 0;
            $uNameStore = $uNameNorm !== '' ? $uNameNorm : $uNameRaw;
            if ($uNameStore !== '') {
                $uRow = store_find_radius_user_by_username($uNameStore);
                if (!is_array($uRow) && $uNameStore !== $uNameRaw && $uNameRaw !== '') {
                    $uRow = store_find_radius_user_by_username($uNameRaw);
                }
                if (is_array($uRow) && (string)($uRow['username'] ?? '') !== '') {
                    $uNameStore = (string)$uRow['username'];
                }
            }

            if ($sessId !== '' && $uNameStore !== '') {
                $prev = store_get_radius_session_usage($sessId);
                $prevIn = is_array($prev) ? (int)($prev['last_in'] ?? 0) : 0;
                $prevOut = is_array($prev) ? (int)($prev['last_out'] ?? 0) : 0;
                $dIn = $inTotal - $prevIn;
                $dOut = $outTotal - $prevOut;
                if ($dIn < 0) {
                    $dIn = 0;
                }
                if ($dOut < 0) {
                    $dOut = 0;
                }
                $deltaTotal = (int)($dIn + $dOut);
                store_upsert_radius_session_usage($sessId, $uNameStore, $inTotal, $outTotal);
                if ($deltaTotal > 0) {
                    store_add_radius_user_usage_bytes($uNameStore, $deltaTotal);
                }
                if ($statusType === 'Stop') {
                    store_delete_radius_session_usage($sessId);
                }
            }

            try {
                store_insert_radius_accounting([
                    'ts' => time(),
                    'nas_ip' => $routerIp,
                    'username' => $uNameStore,
                    'session_id' => $sessId,
                    'status_type' => $statusType,
                    'input_octets' => $inTotal,
                    'output_octets' => $outTotal,
                    'session_time' => $sessTime,
                    'raw_attrs' => radius_attrs_to_json($attrs),
                ]);
            } catch (Throwable $e) {
                store_insert_radius_accounting_error([
                    'error_type' => 'error',
                    'router_ip' => $routerIp,
                    'peer_ip' => $peerIp,
                    'nas_ip' => $routerIp,
                    'username' => $uNameStore,
                    'session_id' => $sessId,
                    'status_type' => $statusType,
                    'message' => 'Accounting insert failed: ' . $e->getMessage(),
                    'raw_attrs' => radius_attrs_to_json($attrs),
                ]);
            }
            if ($statusType !== '' && $statusType !== 'Interim-Update') {
                store_insert_app_log('info', 'radius', 'accounting ' . $statusType, ['router_ip' => $routerIp, 'peer_ip' => $peerIp, 'user' => $uNameStore, 'session_id' => $sessId]);
            }

            $resp = radius_build_response(5, $id, $reqAuth, '', $secret);
            @socket_sendto($sockAcct, $resp, strlen($resp), 0, $peerIp, $fromPort);
            continue;
        }
    }
}
