<?php
declare(strict_types=1);

function router_normalize(array $router): array
{
    $router['id'] = (string)($router['id'] ?? '');
    $router['name'] = (string)($router['name'] ?? '');
    $router['ip'] = (string)($router['ip'] ?? '');
    $router['api_port'] = (int)($router['api_port'] ?? 8728);
    $router['snmp_port'] = (int)($router['snmp_port'] ?? 161);
    $router['snmp_version'] = (string)($router['snmp_version'] ?? '2c');
    $router['username'] = (string)($router['username'] ?? '');
    $router['password'] = (string)($router['password'] ?? '');
    $router['snmp_community'] = (string)($router['snmp_community'] ?? 'public');
    $router['monitor_interface'] = (string)($router['monitor_interface'] ?? 'ether1');
    $router['monitor_capacity_mbps'] = (int)($router['monitor_capacity_mbps'] ?? 100);
    $router['radius_secret'] = (string)($router['radius_secret'] ?? '');
    $router['radius_enabled'] = (int)($router['radius_enabled'] ?? 0);
    if (!in_array($router['snmp_version'], ['1', '2c'], true)) {
        $router['snmp_version'] = '2c';
    }
    if ($router['monitor_capacity_mbps'] <= 0) {
        $router['monitor_capacity_mbps'] = 100;
    }
    return $router;
}

function router_check_tcp(string $ip, int $port, int $timeoutSeconds = 2): bool
{
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($ip, $port, $errno, $errstr, $timeoutSeconds);
    if ($fp === false) {
        return false;
    }
    fclose($fp);
    return true;
}

function router_status(array $router): string
{
    $router = router_normalize($router);
    if ($router['ip'] === '') {
        return 'offline';
    }
    $res = mikrotik_api_test($router);
    return ($res['ok'] ?? false) ? 'online' : 'offline';
}

function mikrotik_api_test(array $router): array
{
    $router = router_normalize($router);
    if ($router['ip'] === '' || $router['api_port'] < 1 || $router['api_port'] > 65535) {
        return ['ok' => false, 'error' => 'Invalid API address/port.'];
    }
    if ($router['username'] === '' || $router['password'] === '') {
        return ['ok' => false, 'error' => 'API user/password is required.'];
    }
    try {
        $api = new RouterOSAPI();
        $ok = $api->connect($router['ip'], $router['username'], $router['password'], (int)$router['api_port'], 3);
        $api->disconnect();
        return $ok ? ['ok' => true, 'error' => ''] : ['ok' => false, 'error' => 'API connection failed.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'API error.'];
    }
}

function mikrotik_snmp_available(): bool
{
    return function_exists('snmp2_get') || function_exists('snmpget') || function_exists('socket_create');
}

function snmp_ber_length(int $len): string
{
    if ($len < 0x80) {
        return chr($len);
    }
    $out = '';
    while ($len > 0) {
        $out = chr($len & 0xFF) . $out;
        $len >>= 8;
    }
    return chr(0x80 | strlen($out)) . $out;
}

function snmp_ber_tlv(int $tag, string $value): string
{
    return chr($tag) . snmp_ber_length(strlen($value)) . $value;
}

function snmp_ber_integer(int $value): string
{
    $v = $value;
    $bin = '';
    while ($v > 0) {
        $bin = chr($v & 0xFF) . $bin;
        $v >>= 8;
    }
    if ($bin === '') {
        $bin = "\0";
    }
    if ((ord($bin[0]) & 0x80) !== 0) {
        $bin = "\0" . $bin;
    }
    return snmp_ber_tlv(0x02, $bin);
}

function snmp_ber_string(string $value): string
{
    return snmp_ber_tlv(0x04, $value);
}

function snmp_ber_null(): string
{
    return snmp_ber_tlv(0x05, '');
}

function snmp_ber_oid(string $oid): string
{
    $parts = array_map('intval', explode('.', trim($oid)));
    if (count($parts) < 2) {
        return snmp_ber_tlv(0x06, '');
    }
    $first = (40 * $parts[0]) + $parts[1];
    $out = chr($first);
    for ($i = 2; $i < count($parts); $i++) {
        $n = $parts[$i];
        $stack = [];
        do {
            $stack[] = $n & 0x7F;
            $n >>= 7;
        } while ($n > 0);
        for ($j = count($stack) - 1; $j >= 0; $j--) {
            $byte = $stack[$j];
            if ($j !== 0) {
                $byte |= 0x80;
            }
            $out .= chr($byte);
        }
    }
    return snmp_ber_tlv(0x06, $out);
}

function snmp_ber_sequence(string $value): string
{
    return snmp_ber_tlv(0x30, $value);
}

function snmp_ber_get_request(int $requestId, string $oid): string
{
    $varBind = snmp_ber_sequence(snmp_ber_oid($oid) . snmp_ber_null());
    $varBindList = snmp_ber_sequence($varBind);
    $pdu = snmp_ber_integer($requestId) . snmp_ber_integer(0) . snmp_ber_integer(0) . $varBindList;
    return snmp_ber_tlv(0xA0, $pdu);
}

function snmp_ber_read_tlv(string $data, int &$pos): ?array
{
    $lenData = strlen($data);
    if ($pos >= $lenData) {
        return null;
    }
    $tag = ord($data[$pos]);
    $pos++;
    if ($pos >= $lenData) {
        return null;
    }
    $lenByte = ord($data[$pos]);
    $pos++;
    $len = 0;
    if (($lenByte & 0x80) === 0) {
        $len = $lenByte;
    } else {
        $n = $lenByte & 0x7F;
        if ($pos + $n > $lenData) {
            return null;
        }
        for ($i = 0; $i < $n; $i++) {
            $len = ($len << 8) + ord($data[$pos + $i]);
        }
        $pos += $n;
    }
    if ($pos + $len > $lenData) {
        return null;
    }
    $value = substr($data, $pos, $len);
    $pos += $len;
    return ['tag' => $tag, 'value' => $value];
}

function snmp_ber_int_value(string $value): int
{
    $n = 0;
    $len = strlen($value);
    for ($i = 0; $i < $len; $i++) {
        $n = ($n << 8) + ord($value[$i]);
    }
    return $n;
}

function snmp_raw_get_sysname(string $ip, int $port, string $community, string $version): ?string
{
    if (!function_exists('socket_create')) {
        return null;
    }
    $verInt = $version === '2c' ? 1 : 0;
    $requestId = random_int(1, 0x7fffffff);
    $message = snmp_ber_sequence(
        snmp_ber_integer($verInt) .
        snmp_ber_string($community) .
        snmp_ber_get_request($requestId, '1.3.6.1.2.1.1.5.0')
    );

    $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if ($sock === false) {
        return null;
    }
    @socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 1, 'usec' => 0]);
    @socket_set_option($sock, SOL_SOCKET, SO_SNDTIMEO, ['sec' => 1, 'usec' => 0]);

    $sent = @socket_sendto($sock, $message, strlen($message), 0, $ip, $port);
    if ($sent === false) {
        @socket_close($sock);
        return null;
    }

    $buf = '';
    $from = '';
    $fromPort = 0;
    $recv = @socket_recvfrom($sock, $buf, 4096, 0, $from, $fromPort);
    @socket_close($sock);
    if ($recv === false || !is_string($buf) || $buf === '') {
        return null;
    }

    $pos = 0;
    $msg = snmp_ber_read_tlv($buf, $pos);
    if (!$msg || ($msg['tag'] ?? null) !== 0x30) {
        return null;
    }
    $inner = (string)$msg['value'];
    $p = 0;
    $v = snmp_ber_read_tlv($inner, $p);
    $c = snmp_ber_read_tlv($inner, $p);
    $pdu = snmp_ber_read_tlv($inner, $p);
    if (!$v || !$c || !$pdu || (int)($pdu['tag'] ?? 0) !== 0xA2) {
        return null;
    }
    $pduInner = (string)$pdu['value'];
    $pp = 0;
    $rid = snmp_ber_read_tlv($pduInner, $pp);
    $errStatus = snmp_ber_read_tlv($pduInner, $pp);
    $errIndex = snmp_ber_read_tlv($pduInner, $pp);
    $varList = snmp_ber_read_tlv($pduInner, $pp);
    if (!$rid || !$errStatus || !$errIndex || !$varList || (int)($varList['tag'] ?? 0) !== 0x30) {
        return null;
    }
    if ((int)($errStatus['tag'] ?? 0) !== 0x02) {
        return null;
    }
    if (snmp_ber_int_value((string)$errStatus['value']) !== 0) {
        return null;
    }
    $vbInner = (string)$varList['value'];
    $vp = 0;
    $vb = snmp_ber_read_tlv($vbInner, $vp);
    if (!$vb || (int)($vb['tag'] ?? 0) !== 0x30) {
        return null;
    }
    $one = (string)$vb['value'];
    $op = 0;
    $oidTlv = snmp_ber_read_tlv($one, $op);
    $valTlv = snmp_ber_read_tlv($one, $op);
    if (!$oidTlv || !$valTlv) {
        return null;
    }
    if ((int)$valTlv['tag'] === 0x04) {
        $name = trim((string)$valTlv['value']);
        return $name === '' ? null : $name;
    }
    if ((int)$valTlv['tag'] === 0x06) {
        return 'OID';
    }
    return null;
}

function snmp_ber_uint_value(string $value): float
{
    $n = 0.0;
    $len = strlen($value);
    for ($i = 0; $i < $len; $i++) {
        $n = ($n * 256.0) + (float)ord($value[$i]);
    }
    return $n;
}

function snmp_dec_str_mul_add(string $dec, int $mul, int $add): string
{
    $dec = preg_replace('/[^0-9]+/', '', $dec);
    if (!is_string($dec) || $dec === '') {
        $dec = '0';
    }
    $carry = $add;
    $out = '';
    for ($i = strlen($dec) - 1; $i >= 0; $i--) {
        $d = (int)$dec[$i];
        $v = ($d * $mul) + $carry;
        $out = (string)($v % 10) . $out;
        $carry = intdiv($v, 10);
    }
    while ($carry > 0) {
        $out = (string)($carry % 10) . $out;
        $carry = intdiv($carry, 10);
    }
    $out = ltrim($out, '0');
    return $out === '' ? '0' : $out;
}

function snmp_ber_uint_value_str(string $value): string
{
    $n = '0';
    $len = strlen($value);
    for ($i = 0; $i < $len; $i++) {
        $n = snmp_dec_str_mul_add($n, 256, ord($value[$i]));
    }
    return $n;
}

function snmp_raw_get_tlv(string $ip, int $port, string $community, string $version, string $oid): ?array
{
    if (!function_exists('socket_create')) {
        return null;
    }
    $verInt = $version === '2c' ? 1 : 0;
    $requestId = random_int(1, 0x7fffffff);
    $message = snmp_ber_sequence(
        snmp_ber_integer($verInt) .
        snmp_ber_string($community) .
        snmp_ber_get_request($requestId, $oid)
    );

    $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if ($sock === false) {
        return null;
    }
    @socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 1, 'usec' => 0]);
    @socket_set_option($sock, SOL_SOCKET, SO_SNDTIMEO, ['sec' => 1, 'usec' => 0]);

    $sent = @socket_sendto($sock, $message, strlen($message), 0, $ip, $port);
    if ($sent === false) {
        @socket_close($sock);
        return null;
    }

    $buf = '';
    $from = '';
    $fromPort = 0;
    $recv = @socket_recvfrom($sock, $buf, 8192, 0, $from, $fromPort);
    @socket_close($sock);
    if ($recv === false || !is_string($buf) || $buf === '') {
        return null;
    }

    $pos = 0;
    $msg = snmp_ber_read_tlv($buf, $pos);
    if (!$msg || ($msg['tag'] ?? null) !== 0x30) {
        return null;
    }
    $inner = (string)$msg['value'];
    $p = 0;
    $v = snmp_ber_read_tlv($inner, $p);
    $c = snmp_ber_read_tlv($inner, $p);
    $pdu = snmp_ber_read_tlv($inner, $p);
    if (!$v || !$c || !$pdu || (int)($pdu['tag'] ?? 0) !== 0xA2) {
        return null;
    }
    $pduInner = (string)$pdu['value'];
    $pp = 0;
    $rid = snmp_ber_read_tlv($pduInner, $pp);
    $errStatus = snmp_ber_read_tlv($pduInner, $pp);
    $errIndex = snmp_ber_read_tlv($pduInner, $pp);
    $varList = snmp_ber_read_tlv($pduInner, $pp);
    if (!$rid || !$errStatus || !$errIndex || !$varList || (int)($varList['tag'] ?? 0) !== 0x30) {
        return null;
    }
    if ((int)($errStatus['tag'] ?? 0) !== 0x02) {
        return null;
    }
    if (snmp_ber_int_value((string)$errStatus['value']) !== 0) {
        return null;
    }
    $vbInner = (string)$varList['value'];
    $vp = 0;
    $vb = snmp_ber_read_tlv($vbInner, $vp);
    if (!$vb || (int)($vb['tag'] ?? 0) !== 0x30) {
        return null;
    }
    $one = (string)$vb['value'];
    $op = 0;
    $oidTlv = snmp_ber_read_tlv($one, $op);
    $valTlv = snmp_ber_read_tlv($one, $op);
    if (!$oidTlv || !$valTlv) {
        return null;
    }
    return ['tag' => (int)$valTlv['tag'], 'value' => (string)$valTlv['value']];
}

function mikrotik_snmp_get_number(array $router, string $oid): ?float
{
    $router = router_normalize($router);
    if ($router['ip'] === '' || $router['snmp_community'] === '') {
        return null;
    }

    $host = $router['ip'] . ':' . (string)$router['snmp_port'];
    $timeout = 1000000;
    $retries = 1;

    $val = false;
    if ($router['snmp_version'] === '2c' && function_exists('snmp2_get')) {
        $val = @snmp2_get($host, $router['snmp_community'], $oid, $timeout, $retries);
    } elseif ($router['snmp_version'] === '1' && function_exists('snmpget')) {
        $val = @snmpget($host, $router['snmp_community'], $oid, $timeout, $retries);
    }
    if (is_string($val) && $val !== '') {
        $digits = preg_replace('/[^0-9.]+/', '', $val);
        if (is_string($digits) && $digits !== '') {
            return (float)$digits;
        }
    }

    $tlv = snmp_raw_get_tlv($router['ip'], (int)$router['snmp_port'], $router['snmp_community'], $router['snmp_version'], $oid);
    if (!is_array($tlv)) {
        return null;
    }
    $tag = (int)($tlv['tag'] ?? 0);
    $raw = (string)($tlv['value'] ?? '');
    if (in_array($tag, [0x02, 0x41, 0x42, 0x43, 0x46], true)) {
        return snmp_ber_uint_value($raw);
    }
    return null;
}

function mikrotik_snmp_get_counter_str(array $router, string $oid): ?string
{
    $router = router_normalize($router);
    if ($router['ip'] === '' || $router['snmp_community'] === '') {
        return null;
    }

    if (function_exists('snmp_set_valueretrieval') && defined('SNMP_VALUE_PLAIN')) {
        @snmp_set_valueretrieval(SNMP_VALUE_PLAIN);
    }

    $host = $router['ip'] . ':' . (string)$router['snmp_port'];
    $timeout = 1000000;
    $retries = 1;

    $val = false;
    if ($router['snmp_version'] === '2c' && function_exists('snmp2_get')) {
        $val = @snmp2_get($host, $router['snmp_community'], $oid, $timeout, $retries);
    } elseif ($router['snmp_version'] === '1' && function_exists('snmpget')) {
        $val = @snmpget($host, $router['snmp_community'], $oid, $timeout, $retries);
    }

    if (is_string($val) && $val !== '') {
        $digits = preg_replace('/[^0-9]+/', '', $val);
        if (is_string($digits) && $digits !== '') {
            $digits = ltrim($digits, '0');
            return $digits === '' ? '0' : $digits;
        }
    }

    $tlv = snmp_raw_get_tlv($router['ip'], (int)$router['snmp_port'], $router['snmp_community'], $router['snmp_version'], $oid);
    if (!is_array($tlv)) {
        return null;
    }

    $tag = (int)($tlv['tag'] ?? 0);
    $raw = (string)($tlv['value'] ?? '');
    if (in_array($tag, [0x02, 0x41, 0x42, 0x43, 0x46], true)) {
        return snmp_ber_uint_value_str($raw);
    }
    return null;
}

function mikrotik_snmp_sysname(array $router): ?string
{
    $router = router_normalize($router);
    if ($router['ip'] === '' || $router['snmp_community'] === '') {
        return null;
    }

    if (function_exists('snmp_set_valueretrieval') && defined('SNMP_VALUE_PLAIN')) {
        @snmp_set_valueretrieval(SNMP_VALUE_PLAIN);
    }

    $host = $router['ip'] . ':' . (string)$router['snmp_port'];
    $oid = '1.3.6.1.2.1.1.5.0';
    $timeout = 1000000;
    $retries = 1;

    $val = false;
    if ($router['snmp_version'] === '2c' && function_exists('snmp2_get')) {
        $val = @snmp2_get($host, $router['snmp_community'], $oid, $timeout, $retries);
    } elseif ($router['snmp_version'] === '1' && function_exists('snmpget')) {
        $val = @snmpget($host, $router['snmp_community'], $oid, $timeout, $retries);
    } elseif (function_exists('snmp2_get')) {
        $val = @snmp2_get($host, $router['snmp_community'], $oid, $timeout, $retries);
    } elseif (function_exists('snmpget')) {
        $val = @snmpget($host, $router['snmp_community'], $oid, $timeout, $retries);
    }

    if (is_string($val) && trim($val) !== '') {
        $v = trim($val);
        $v = trim($v, "\" \t\n\r\0\x0B");
        return $v === '' ? null : $v;
    }

    $raw = snmp_raw_get_sysname($router['ip'], (int)$router['snmp_port'], $router['snmp_community'], $router['snmp_version']);
    return $raw;
}

function mikrotik_snmp_test(array $router): array
{
    $router = router_normalize($router);
    if ($router['ip'] === '' || $router['snmp_port'] < 1 || $router['snmp_port'] > 65535) {
        return ['ok' => false, 'error' => 'Invalid SNMP address/port.'];
    }
    if ($router['snmp_community'] === '') {
        return ['ok' => false, 'error' => 'SNMP community is required.'];
    }
    if (!mikrotik_snmp_available()) {
        return ['ok' => false, 'error' => 'SNMP is not available in PHP (enable php_snmp or sockets).'];
    }
    $name = mikrotik_snmp_sysname($router);
    if ($name !== null) {
        return ['ok' => true, 'error' => ''];
    }
    return ['ok' => false, 'error' => 'No SNMP response. Enable SNMP and check version/community/port.'];
}

function mikrotik_api_connect(array $router): ?RouterOSAPI
{
    $router = router_normalize($router);
    if ($router['ip'] === '' || $router['username'] === '' || $router['password'] === '') {
        return null;
    }
    $api = new RouterOSAPI();
    if (!$api->connect($router['ip'], $router['username'], $router['password'], $router['api_port'], 6)) {
        store_insert_app_log('error', 'mikrotik', 'api connect failed', ['router_ip' => $router['ip'], 'api_port' => $router['api_port']]);
        return null;
    }
    store_insert_app_log('info', 'mikrotik', 'api connect ok', ['router_ip' => $router['ip'], 'api_port' => $router['api_port']]);
    return $api;
}

function mikrotik_router_metrics(array $router): array
{
    $router = router_normalize($router);
    $api = mikrotik_api_connect($router);
    if ($api === null) {
        return ['cpu_load' => null, 'uptime' => null, 'temperature_c' => null];
    }

    try {
        $res = $api->commOne('/system/resource/print');
        if (!is_array($res)) {
            $res = [];
        }

        $cpu = null;
        if (isset($res['cpu-load']) && is_numeric($res['cpu-load'])) {
            $cpu = (float)$res['cpu-load'];
        }

        $uptime = null;
        $u = trim((string)($res['uptime'] ?? ''));
        if ($u !== '') {
            $uptime = $u;
        }

        $tempRaw = trim((string)($res['cpu-temperature'] ?? $res['temperature'] ?? ''));
        if ($tempRaw === '') {
            $health = $api->comm('/system/health/print');
            $best = '';
            foreach ($health as $h) {
                if (!is_array($h)) {
                    continue;
                }
                $name = strtolower(trim((string)($h['name'] ?? '')));
                $val = trim((string)($h['value'] ?? ''));
                if ($name === '' || $val === '') {
                    continue;
                }
                if (str_contains($name, 'temperature') && str_contains($name, 'cpu')) {
                    $best = $val;
                    break;
                }
                if ($best === '' && str_contains($name, 'temperature')) {
                    $best = $val;
                }
            }
            $tempRaw = $best;
        }

        $tempC = null;
        if ($tempRaw !== '' && preg_match('/-?\d+(?:\.\d+)?/', $tempRaw, $m)) {
            $tempC = (float)$m[0];
        }

        return ['cpu_load' => $cpu, 'uptime' => $uptime, 'temperature_c' => $tempC];
    } catch (Throwable $e) {
        return ['cpu_load' => null, 'uptime' => null, 'temperature_c' => null];
    } finally {
        $api->disconnect();
    }
}

function mikrotik_hotspot_active(array $router): array
{
    $api = mikrotik_api_connect($router);
    if ($api === null) {
        return [];
    }
    try {
        $rows = $api->comm('/ip/hotspot/active/print', ['.proplist' => '.id,user,address,uptime,bytes-in,bytes-out,tx-rate,rx-rate']);
        return array_map(
            function (array $row): array {
                $user = (string)($row['user'] ?? $row['login'] ?? $row['name'] ?? '');
                $addr = (string)($row['address'] ?? $row['ip'] ?? $row['client-address'] ?? '');
                return [
                    'id' => (string)($row['.id'] ?? ''),
                    'session_id' => (string)($row['session-id'] ?? ''),
                    'user' => $user,
                    'address' => $addr,
                    'uptime' => (string)($row['uptime'] ?? ''),
                    'bytes_in' => (int)($row['bytes-in'] ?? 0),
                    'bytes_out' => (int)($row['bytes-out'] ?? 0),
                    'tx_rate' => (string)($row['tx-rate'] ?? ''),
                    'rx_rate' => (string)($row['rx-rate'] ?? ''),
                ];
            },
            $rows
        );
    } catch (Throwable $e) {
        return [];
    } finally {
        $api->disconnect();
    }
}

function mikrotik_count_hotspot_users(array $router): int
{
    $api = mikrotik_api_connect($router);
    if ($api === null) {
        return 0;
    }
    try {
        $rows = $api->comm('/ip/hotspot/user/print', ['.proplist' => 'name']);
        return count($rows);
    } catch (Throwable $e) {
        return 0;
    } finally {
        $api->disconnect();
    }
}

function mikrotik_monitor_traffic_mbps(array $router): array
{
    $router = router_normalize($router);
    return mikrotik_monitor_traffic_interface_mbps($router, (string)$router['monitor_interface']);
}

function mikrotik_monitor_traffic_interface_mbps(array $router, string $interface): array
{
    $router = router_normalize($router);
    $interface = trim($interface);
    if ($interface === '') {
        return ['tx_mbps' => 0.0, 'rx_mbps' => 0.0];
    }
    $api = mikrotik_api_connect($router);
    if ($api === null) {
        return ['tx_mbps' => 0.0, 'rx_mbps' => 0.0];
    }
    try {
        $row = $api->commOne('/interface/monitor-traffic', [
            'interface' => $interface,
            'once' => '',
        ]);
        if (!is_array($row)) {
            return ['tx_mbps' => 0.0, 'rx_mbps' => 0.0];
        }
        $tx = (float)($row['tx-bits-per-second'] ?? 0);
        $rx = (float)($row['rx-bits-per-second'] ?? 0);
        return [
            'tx_mbps' => $tx / 1_000_000,
            'rx_mbps' => $rx / 1_000_000,
        ];
    } finally {
        $api->disconnect();
    }
}

function mikrotik_monitor_traffic_interfaces_mbps(array $router, array $interfaces): array
{
    $router = router_normalize($router);
    $interfaces = array_values(array_filter(array_map(fn($i) => trim((string)$i), $interfaces), fn($i) => $i !== ''));
    if (count($interfaces) === 0) {
        return [];
    }

    $api = mikrotik_api_connect($router);
    if ($api === null) {
        return [];
    }

    try {
        $out = [];
        foreach ($interfaces as $iface) {
            $row = $api->commOne('/interface/monitor-traffic', [
                'interface' => $iface,
                'once' => '',
            ]);
            if (!is_array($row)) {
                $out[$iface] = ['tx_mbps' => 0.0, 'rx_mbps' => 0.0];
                continue;
            }
            $tx = (float)($row['tx-bits-per-second'] ?? 0);
            $rx = (float)($row['rx-bits-per-second'] ?? 0);
            $out[$iface] = [
                'tx_mbps' => $tx / 1_000_000,
                'rx_mbps' => $rx / 1_000_000,
            ];
        }
        return $out;
    } finally {
        $api->disconnect();
    }
}

function mikrotik_interface_ifindex_map(array $router): array
{
    $router = router_normalize($router);

    $api = mikrotik_api_connect($router);
    if ($api !== null) {
        try {
            $rows = $api->comm('/interface/print', ['.proplist' => 'name,ifindex']);
            $map = [];
            foreach ($rows as $r) {
                $name = (string)($r['name'] ?? '');
                $idx = (int)($r['ifindex'] ?? $r['if-index'] ?? 0);
                if ($name !== '' && $idx > 0) {
                    $map[$name] = $idx;
                }
            }
            if (count($map) > 0) {
                return $map;
            }

            $rows = $api->comm('/interface/print');
            $map = [];
            foreach ($rows as $r) {
                $name = (string)($r['name'] ?? '');
                $idx = (int)($r['ifindex'] ?? $r['if-index'] ?? 0);
                if ($name !== '' && $idx > 0) {
                    $map[$name] = $idx;
                }
            }
            if (count($map) > 0) {
                return $map;
            }
        } catch (Throwable $e) {
        } finally {
            $api->disconnect();
        }
    }

    if ($router['ip'] === '' || $router['snmp_community'] === '') {
        return [];
    }
    if (!function_exists('snmp2_real_walk')) {
        return [];
    }

    $host = $router['ip'] . ':' . (string)$router['snmp_port'];
    $timeout = 1000000;
    $retries = 1;
    $walk = @snmp2_real_walk($host, $router['snmp_community'], '1.3.6.1.2.1.31.1.1.1.1', $timeout, $retries);
    if (!is_array($walk)) {
        return [];
    }

    $map = [];
    foreach ($walk as $k => $v) {
        $key = (string)$k;
        $pos = strrpos($key, '.');
        if ($pos === false) {
            continue;
        }
        $idx = (int)substr($key, $pos + 1);
        if ($idx <= 0) {
            continue;
        }
        $name = trim((string)$v);
        $colon = strpos($name, ':');
        if ($colon !== false) {
            $name = trim(substr($name, $colon + 1));
        }
        $name = trim($name, "\" \t\n\r\0\x0B");
        if ($name === '') {
            continue;
        }
        $map[$name] = $idx;
    }
    return $map;
}

function mikrotik_snmp_interface_octets(array $router, array $interfaces): array
{
    $router = router_normalize($router);
    $interfaces = array_values(array_filter(array_map(fn($i) => trim((string)$i), $interfaces), fn($i) => $i !== ''));
    if (count($interfaces) === 0) {
        return [];
    }

    $ifIndexMap = mikrotik_interface_ifindex_map($router);
    $out = [];
    foreach ($interfaces as $iface) {
        $idx = (int)($ifIndexMap[$iface] ?? 0);
        if ($idx <= 0) {
            continue;
        }

        $inOid = '1.3.6.1.2.1.31.1.1.1.6.' . $idx;
        $outOid = '1.3.6.1.2.1.31.1.1.1.10.' . $idx;
        $in = mikrotik_snmp_get_counter_str($router, $inOid);
        $outb = mikrotik_snmp_get_counter_str($router, $outOid);

        if ($in === null || $outb === null) {
            $in = mikrotik_snmp_get_counter_str($router, '1.3.6.1.2.1.2.2.1.10.' . $idx);
            $outb = mikrotik_snmp_get_counter_str($router, '1.3.6.1.2.1.2.2.1.16.' . $idx);
        }

        if ($in === null || $outb === null) {
            continue;
        }

        $out[$iface] = [
            'in_octets' => $in,
            'out_octets' => $outb,
        ];
    }
    return $out;
}

function bytes_to_gb(int $bytes): float
{
    if ($bytes <= 0) {
        return 0.0;
    }
    return $bytes / 1024 / 1024 / 1024;
}
