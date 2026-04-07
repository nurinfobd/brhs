<?php
declare(strict_types=1);

function store_load(): array
{
    $pdo = db();
    $routers = $pdo->query("SELECT * FROM routers ORDER BY name ASC")->fetchAll();
    $users = $pdo->query("SELECT * FROM admin_users ORDER BY username ASC")->fetchAll();
    return [
        'routers' => is_array($routers) ? $routers : [],
        'users' => is_array($users) ? $users : [],
    ];
}

function store_insert_app_log(string $level, string $category, string $message, array $context = []): void
{
    $level = strtolower(trim($level));
    if ($level === '') {
        $level = 'info';
    }
    if (!in_array($level, ['debug', 'info', 'warning', 'error'], true)) {
        $level = 'info';
    }
    $category = strtolower(trim($category));
    if ($category === '') {
        $category = 'app';
    }
    if (strlen($category) > 32) {
        $category = substr($category, 0, 32);
    }
    $message = trim($message);
    if ($message === '') {
        return;
    }
    if (strlen($message) > 5000) {
        $message = substr($message, 0, 5000);
    }

    $ctxJson = null;
    if (count($context) > 0) {
        $ctxJson = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($ctxJson)) {
            $ctxJson = null;
        } elseif (strlen($ctxJson) > 20000) {
            $ctxJson = substr($ctxJson, 0, 20000);
        }
    }

    try {
        $pdo = db();
        $stmt = $pdo->prepare(
            "INSERT INTO app_logs (ts, level, category, message, context_json)
             VALUES (:ts, :lvl, :cat, :msg, :ctx)"
        );
        $stmt->execute([
            ':ts' => time(),
            ':lvl' => $level,
            ':cat' => $category,
            ':msg' => $message,
            ':ctx' => $ctxJson,
        ]);

        $pdo->exec(
            "DELETE FROM app_logs
             WHERE id < (
                 SELECT IFNULL(MIN(id), 0)
                 FROM (
                     SELECT id FROM app_logs ORDER BY id DESC LIMIT 20000
                 ) t
             )"
        );
    } catch (Throwable $e) {
        return;
    }
}

function store_secret_key(): string
{
    $b64 = getenv('CITYU_APP_KEY');
    if (!is_string($b64) || $b64 === '') {
        return '';
    }
    $raw = base64_decode($b64, true);
    if (!is_string($raw) || $raw === '' || strlen($raw) < 32) {
        return '';
    }
    return substr($raw, 0, 32);
}

function store_encrypt_password(string $plain): ?string
{
    $plain = (string)$plain;
    if ($plain === '') {
        return null;
    }
    $key = store_secret_key();
    if ($key === '') {
        return null;
    }
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if (!is_string($cipher) || $cipher === '' || !is_string($tag) || strlen($tag) !== 16) {
        return null;
    }
    return base64_encode($iv . $tag . $cipher) ?: null;
}

function store_decrypt_password(?string $enc): string
{
    if (!is_string($enc) || $enc === '') {
        return '';
    }
    $key = store_secret_key();
    if ($key === '') {
        return '';
    }
    $raw = base64_decode($enc, true);
    if (!is_string($raw) || strlen($raw) < 12 + 16 + 1) {
        return '';
    }
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return is_string($plain) ? $plain : '';
}

function store_get_router(string $routerId): ?array
{
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM routers WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $routerId]);
    $row = $stmt->fetch();
    if (is_array($row)) {
        return $row;
    }
    return null;
}

function store_upsert_router(array $router): void
{
    $pdo = db();
    $now = gmdate('Y-m-d H:i:s');
    $created = (string)($router['created_at'] ?? $now);
    $stmt = $pdo->prepare(
        "INSERT INTO routers (id, name, ip, api_port, snmp_port, snmp_version, username, password, snmp_community, monitor_interface, monitor_capacity_mbps, radius_secret, radius_enabled, created_at, updated_at)
         VALUES (:id, :name, :ip, :api_port, :snmp_port, :snmp_version, :username, :password, :snmp_community, :monitor_interface, :monitor_capacity_mbps, :radius_secret, :radius_enabled, :created_at, :updated_at)
         ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            ip = VALUES(ip),
            api_port = VALUES(api_port),
            snmp_port = VALUES(snmp_port),
            snmp_version = VALUES(snmp_version),
            username = VALUES(username),
            password = VALUES(password),
            snmp_community = VALUES(snmp_community),
            monitor_interface = VALUES(monitor_interface),
            monitor_capacity_mbps = VALUES(monitor_capacity_mbps),
            radius_secret = VALUES(radius_secret),
            radius_enabled = VALUES(radius_enabled),
            updated_at = VALUES(updated_at)"
    );
    $stmt->execute([
        ':id' => (string)($router['id'] ?? ''),
        ':name' => (string)($router['name'] ?? ''),
        ':ip' => (string)($router['ip'] ?? ''),
        ':api_port' => (int)($router['api_port'] ?? 8728),
        ':snmp_port' => (int)($router['snmp_port'] ?? 161),
        ':snmp_version' => (string)($router['snmp_version'] ?? '2c'),
        ':username' => (string)($router['username'] ?? ''),
        ':password' => (string)($router['password'] ?? ''),
        ':snmp_community' => (string)($router['snmp_community'] ?? 'public'),
        ':monitor_interface' => (string)($router['monitor_interface'] ?? 'ether1'),
        ':monitor_capacity_mbps' => (int)($router['monitor_capacity_mbps'] ?? 100),
        ':radius_secret' => (string)($router['radius_secret'] ?? ''),
        ':radius_enabled' => (int)($router['radius_enabled'] ?? 0),
        ':created_at' => $created,
        ':updated_at' => $now,
    ]);
}

function store_delete_router(string $routerId): void
{
    $pdo = db();
    $stmt = $pdo->prepare("DELETE FROM routers WHERE id = :id");
    $stmt->execute([':id' => $routerId]);
}

function store_get_router_monitor_interfaces(string $routerId): array
{
    $pdo = db();
    $stmt = $pdo->prepare("SELECT interface_name, capacity_mbps FROM router_monitor_interfaces WHERE router_id = :id ORDER BY interface_name ASC");
    $stmt->execute([':id' => $routerId]);
    $rows = $stmt->fetchAll();
    if (!is_array($rows)) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        if (!is_array($r)) {
            continue;
        }
        $name = trim((string)($r['interface_name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $cap = (int)($r['capacity_mbps'] ?? 100);
        if ($cap <= 0) {
            $cap = 100;
        }
        $out[] = ['interface' => $name, 'capacity_mbps' => $cap];
    }
    return $out;
}

function store_replace_router_monitor_interfaces(string $routerId, array $items): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $del = $pdo->prepare("DELETE FROM router_monitor_interfaces WHERE router_id = :id");
        $del->execute([':id' => $routerId]);

        $now = gmdate('Y-m-d H:i:s');
        $ins = $pdo->prepare(
            "INSERT INTO router_monitor_interfaces (router_id, interface_name, capacity_mbps, created_at, updated_at)
             VALUES (:rid, :name, :cap, :c, :u)"
        );

        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $name = trim((string)($it['interface'] ?? $it['interface_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $cap = (int)($it['capacity_mbps'] ?? 100);
            if ($cap <= 0) {
                $cap = 100;
            }
            $ins->execute([
                ':rid' => $routerId,
                ':name' => $name,
                ':cap' => $cap,
                ':c' => $now,
                ':u' => $now,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function store_get_router_interface_traffic_max(string $routerId): array
{
    $pdo = db();
    $stmt = $pdo->prepare(
        "SELECT interface_name, max_tx_mbps, max_rx_mbps
         FROM router_interface_traffic_max
         WHERE router_id = :id"
    );
    $stmt->execute([':id' => $routerId]);
    $rows = $stmt->fetchAll();
    if (!is_array($rows)) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        if (!is_array($r)) {
            continue;
        }
        $iface = (string)($r['interface_name'] ?? '');
        if ($iface === '') {
            continue;
        }
        $out[$iface] = [
            'max_tx_mbps' => (float)($r['max_tx_mbps'] ?? 0),
            'max_rx_mbps' => (float)($r['max_rx_mbps'] ?? 0),
        ];
    }
    return $out;
}

function store_update_router_interface_traffic_max(string $routerId, string $iface, float $txMbps, float $rxMbps): void
{
    $pdo = db();
    $now = gmdate('Y-m-d H:i:s');
    $stmt = $pdo->prepare(
        "INSERT INTO router_interface_traffic_max (router_id, interface_name, max_tx_mbps, max_rx_mbps, created_at, updated_at)
         VALUES (:rid, :iface, :tx, :rx, :c, :u)
         ON DUPLICATE KEY UPDATE
            max_tx_mbps = GREATEST(max_tx_mbps, VALUES(max_tx_mbps)),
            max_rx_mbps = GREATEST(max_rx_mbps, VALUES(max_rx_mbps)),
            updated_at = VALUES(updated_at)"
    );
    $stmt->execute([
        ':rid' => $routerId,
        ':iface' => $iface,
        ':tx' => $txMbps,
        ':rx' => $rxMbps,
        ':c' => $now,
        ':u' => $now,
    ]);
}

function store_get_router_interface_snmp_cache(string $routerId): array
{
    $pdo = db();
    $stmt = $pdo->prepare(
        "SELECT interface_name, last_in_octets, last_out_octets, last_ts
         FROM router_interface_snmp_cache
         WHERE router_id = :id"
    );
    $stmt->execute([':id' => $routerId]);
    $rows = $stmt->fetchAll();
    if (!is_array($rows)) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        if (!is_array($r)) {
            continue;
        }
        $iface = (string)($r['interface_name'] ?? '');
        if ($iface === '') {
            continue;
        }
        $in = (string)($r['last_in_octets'] ?? '0');
        $outb = (string)($r['last_out_octets'] ?? '0');
        if (stripos($in, 'e') !== false) {
            $in = '0';
        }
        if (stripos($outb, 'e') !== false) {
            $outb = '0';
        }
        $out[$iface] = [
            'last_in_octets' => $in,
            'last_out_octets' => $outb,
            'last_ts' => (int)($r['last_ts'] ?? 0),
        ];
    }
    return $out;
}

function store_upsert_router_interface_snmp_cache(string $routerId, string $iface, string $inOctets, string $outOctets, int $ts): void
{
    $pdo = db();
    $now = gmdate('Y-m-d H:i:s');
    $stmt = $pdo->prepare(
        "INSERT INTO router_interface_snmp_cache (router_id, interface_name, last_in_octets, last_out_octets, last_ts, updated_at)
         VALUES (:rid, :iface, :inb, :outb, :ts, :u)
         ON DUPLICATE KEY UPDATE
            last_in_octets = VALUES(last_in_octets),
            last_out_octets = VALUES(last_out_octets),
            last_ts = VALUES(last_ts),
            updated_at = VALUES(updated_at)"
    );
    $stmt->execute([
        ':rid' => $routerId,
        ':iface' => $iface,
        ':inb' => $inOctets,
        ':outb' => $outOctets,
        ':ts' => $ts,
        ':u' => $now,
    ]);
}

function store_find_router_by_ip(string $ip): ?array
{
    $ip = trim($ip);
    if ($ip === '') {
        return null;
    }
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM routers WHERE ip = :ip LIMIT 1");
    $stmt->execute([':ip' => $ip]);
    $row = $stmt->fetch();
    if (is_array($row)) {
        return $row;
    }
    return null;
}

function store_list_radius_users(): array
{
    $pdo = db();
    $rows = $pdo->query(
        "SELECT u.*, COALESCE(uu.used_bytes, 0) AS used_bytes
         FROM radius_users u
         LEFT JOIN radius_user_usage uu ON uu.username = u.username
         ORDER BY u.username ASC"
    )->fetchAll();
    return is_array($rows) ? $rows : [];
}

function store_get_radius_user(string $id): ?array
{
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM radius_users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (is_array($row)) {
        return $row;
    }
    return null;
}

function store_find_radius_user_by_username(string $username): ?array
{
    $username = trim($username);
    if ($username === '') {
        return null;
    }
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM radius_users WHERE LOWER(username) = LOWER(:u) LIMIT 1");
    $stmt->execute([':u' => $username]);
    $row = $stmt->fetch();
    if (is_array($row)) {
        return $row;
    }
    return null;
}

function store_create_radius_user(string $username, string $profile, ?int $packageId, int $quotaBytes, string $passwordPlain, int $disabled = 0): void
{
    $username = trim($username);
    if ($username === '' || $passwordPlain === '') {
        throw new RuntimeException('Username and password are required.');
    }
    $profile = trim($profile);
    $quotaBytes = max(0, $quotaBytes);
    $pdo = db();
    $now = gmdate('Y-m-d H:i:s');
    $enc = store_encrypt_password($passwordPlain);
    $stmt = $pdo->prepare(
        "INSERT INTO radius_users (username, profile, package_id, quota_bytes, password_hash, password_enc, disabled, created_at, updated_at)
         VALUES (:u, :profile, :pkg, :quota, :p, :pe, :d, :c, :u2)"
    );
    $stmt->execute([
        ':u' => $username,
        ':profile' => $profile,
        ':pkg' => $packageId,
        ':quota' => $quotaBytes,
        ':p' => password_hash($passwordPlain, PASSWORD_DEFAULT),
        ':pe' => $enc,
        ':d' => $disabled ? 1 : 0,
        ':c' => $now,
        ':u2' => $now,
    ]);
}

function store_update_radius_user(string $id, string $username, string $profile, ?int $packageId, int $quotaBytes, ?string $newPasswordPlain, int $disabled = 0): void
{
    $username = trim($username);
    if ($id === '' || $username === '') {
        throw new RuntimeException('Invalid user.');
    }
    $profile = trim($profile);
    $quotaBytes = max(0, $quotaBytes);
    $pdo = db();
    $now = gmdate('Y-m-d H:i:s');

    if ($newPasswordPlain !== null && $newPasswordPlain !== '') {
        $enc = store_encrypt_password($newPasswordPlain);
        $stmt = $pdo->prepare(
            "UPDATE radius_users
             SET username = :u, profile = :profile, package_id = :pkg, quota_bytes = :quota, password_hash = :p, password_enc = :pe, disabled = :d, updated_at = :t
             WHERE id = :id"
        );
        $stmt->execute([
            ':u' => $username,
            ':profile' => $profile,
            ':pkg' => $packageId,
            ':quota' => $quotaBytes,
            ':p' => password_hash($newPasswordPlain, PASSWORD_DEFAULT),
            ':pe' => $enc,
            ':d' => $disabled ? 1 : 0,
            ':t' => $now,
            ':id' => $id,
        ]);
        return;
    }

    $stmt = $pdo->prepare(
        "UPDATE radius_users
         SET username = :u, profile = :profile, package_id = :pkg, quota_bytes = :quota, disabled = :d, updated_at = :t
         WHERE id = :id"
    );
    $stmt->execute([
        ':u' => $username,
        ':profile' => $profile,
        ':pkg' => $packageId,
        ':quota' => $quotaBytes,
        ':d' => $disabled ? 1 : 0,
        ':t' => $now,
        ':id' => $id,
    ]);
}

function store_delete_radius_user(string $id): void
{
    if ($id === '') {
        return;
    }
    $pdo = db();
    $stmt = $pdo->prepare("DELETE FROM radius_users WHERE id = :id");
    $stmt->execute([':id' => $id]);
}

function store_list_radius_packages(): array
{
    $pdo = db();
    $rows = $pdo->query("SELECT * FROM radius_packages ORDER BY name ASC")->fetchAll();
    return is_array($rows) ? $rows : [];
}

function store_get_radius_package(int $id): ?array
{
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM radius_packages WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (is_array($row)) {
        return $row;
    }
    return null;
}

function store_create_radius_package(string $name, string $rateLimit, int $quotaBytes): void
{
    $name = trim($name);
    $rateLimit = trim($rateLimit);
    $quotaBytes = max(0, $quotaBytes);
    if ($name === '') {
        throw new RuntimeException('Package name is required.');
    }
    $pdo = db();
    $now = gmdate('Y-m-d H:i:s');
    $stmt = $pdo->prepare(
        "INSERT INTO radius_packages (name, rate_limit, quota_bytes, created_at, updated_at)
         VALUES (:n, :r, :q, :c, :u)"
    );
    $stmt->execute([
        ':n' => $name,
        ':r' => $rateLimit,
        ':q' => $quotaBytes,
        ':c' => $now,
        ':u' => $now,
    ]);
}

function store_update_radius_package(int $id, string $name, string $rateLimit, int $quotaBytes): void
{
    $name = trim($name);
    $rateLimit = trim($rateLimit);
    $quotaBytes = max(0, $quotaBytes);
    if ($id <= 0 || $name === '') {
        throw new RuntimeException('Invalid package.');
    }
    $pdo = db();
    $now = gmdate('Y-m-d H:i:s');
    $stmt = $pdo->prepare(
        "UPDATE radius_packages
         SET name = :n, rate_limit = :r, quota_bytes = :q, updated_at = :u
         WHERE id = :id"
    );
    $stmt->execute([
        ':n' => $name,
        ':r' => $rateLimit,
        ':q' => $quotaBytes,
        ':u' => $now,
        ':id' => $id,
    ]);
}

function store_delete_radius_package(int $id): void
{
    if ($id <= 0) {
        return;
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE radius_users SET package_id = NULL WHERE package_id = :id")->execute([':id' => $id]);
        $pdo->prepare("DELETE FROM radius_packages WHERE id = :id")->execute([':id' => $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function store_get_hotspot_profile_limit(string $routerId, string $profileName): ?array
{
    $routerId = trim($routerId);
    $profileName = trim($profileName);
    if ($routerId === '' || $profileName === '') {
        return null;
    }
    $pdo = db();
    $stmt = $pdo->prepare(
        "SELECT * FROM hotspot_profile_limits WHERE router_id = :rid AND profile_name = :p LIMIT 1"
    );
    $stmt->execute([':rid' => $routerId, ':p' => $profileName]);
    $row = $stmt->fetch();
    if (is_array($row)) {
        return $row;
    }
    return null;
}

function store_list_hotspot_profile_limits(string $routerId): array
{
    $routerId = trim($routerId);
    if ($routerId === '') {
        return [];
    }
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM hotspot_profile_limits WHERE router_id = :rid ORDER BY profile_name ASC");
    $stmt->execute([':rid' => $routerId]);
    $rows = $stmt->fetchAll();
    return is_array($rows) ? $rows : [];
}

function store_upsert_hotspot_profile_limit(string $routerId, string $profileName, string $rateLimit, int $quotaBytes): void
{
    $routerId = trim($routerId);
    $profileName = trim($profileName);
    $rateLimit = trim($rateLimit);
    $quotaBytes = max(0, $quotaBytes);
    if ($routerId === '' || $profileName === '') {
        throw new RuntimeException('Invalid profile.');
    }
    $pdo = db();
    $now = gmdate('Y-m-d H:i:s');
    $stmt = $pdo->prepare(
        "INSERT INTO hotspot_profile_limits (router_id, profile_name, rate_limit, quota_bytes, created_at, updated_at)
         VALUES (:rid, :p, :r, :q, :c, :u)
         ON DUPLICATE KEY UPDATE
            rate_limit = VALUES(rate_limit),
            quota_bytes = VALUES(quota_bytes),
            updated_at = VALUES(updated_at)"
    );
    $stmt->execute([
        ':rid' => $routerId,
        ':p' => $profileName,
        ':r' => $rateLimit,
        ':q' => $quotaBytes,
        ':c' => $now,
        ':u' => $now,
    ]);
}

function store_delete_hotspot_profile_limit(string $routerId, string $profileName): void
{
    $routerId = trim($routerId);
    $profileName = trim($profileName);
    if ($routerId === '' || $profileName === '') {
        return;
    }
    $pdo = db();
    $stmt = $pdo->prepare("DELETE FROM hotspot_profile_limits WHERE router_id = :rid AND profile_name = :p");
    $stmt->execute([':rid' => $routerId, ':p' => $profileName]);
}

function store_get_radius_user_usage_bytes(string $username): int
{
    $username = trim($username);
    if ($username === '') {
        return 0;
    }
    $pdo = db();
    $stmt = $pdo->prepare("SELECT used_bytes FROM radius_user_usage WHERE username = :u LIMIT 1");
    $stmt->execute([':u' => $username]);
    $v = $stmt->fetchColumn();
    return $v !== false ? (int)$v : 0;
}

function store_add_radius_user_usage_bytes(string $username, int $deltaBytes): void
{
    $username = trim($username);
    if ($username === '' || $deltaBytes <= 0) {
        return;
    }
    $pdo = db();
    $now = gmdate('Y-m-d H:i:s');
    $stmt = $pdo->prepare(
        "INSERT INTO radius_user_usage (username, used_bytes, updated_at)
         VALUES (:u, :b, :t)
         ON DUPLICATE KEY UPDATE
            used_bytes = used_bytes + VALUES(used_bytes),
            updated_at = VALUES(updated_at)"
    );
    $stmt->execute([
        ':u' => $username,
        ':b' => $deltaBytes,
        ':t' => $now,
    ]);
}

function store_get_radius_session_usage(string $sessionId): ?array
{
    $sessionId = trim($sessionId);
    if ($sessionId === '') {
        return null;
    }
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM radius_session_usage WHERE session_id = :s LIMIT 1");
    $stmt->execute([':s' => $sessionId]);
    $row = $stmt->fetch();
    if (is_array($row)) {
        return $row;
    }
    return null;
}

function store_upsert_radius_session_usage(string $sessionId, string $username, int $lastIn, int $lastOut): void
{
    $sessionId = trim($sessionId);
    if ($sessionId === '') {
        return;
    }
    $pdo = db();
    $now = gmdate('Y-m-d H:i:s');
    $stmt = $pdo->prepare(
        "INSERT INTO radius_session_usage (session_id, username, last_in, last_out, updated_at)
         VALUES (:s, :u, :i, :o, :t)
         ON DUPLICATE KEY UPDATE
            username = VALUES(username),
            last_in = VALUES(last_in),
            last_out = VALUES(last_out),
            updated_at = VALUES(updated_at)"
    );
    $stmt->execute([
        ':s' => $sessionId,
        ':u' => $username,
        ':i' => max(0, $lastIn),
        ':o' => max(0, $lastOut),
        ':t' => $now,
    ]);
}

function store_delete_radius_session_usage(string $sessionId): void
{
    $sessionId = trim($sessionId);
    if ($sessionId === '') {
        return;
    }
    $pdo = db();
    $stmt = $pdo->prepare("DELETE FROM radius_session_usage WHERE session_id = :s");
    $stmt->execute([':s' => $sessionId]);
}

function store_insert_radius_accounting(array $row): void
{
    $pdo = db();
    $stmt = $pdo->prepare(
        "INSERT INTO radius_accounting (ts, nas_ip, username, session_id, status_type, input_octets, output_octets, session_time, raw_attrs)
         VALUES (:ts, :nas, :u, :sid, :st, :inb, :outb, :stime, :raw)"
    );
    $stmt->execute([
        ':ts' => (int)($row['ts'] ?? time()),
        ':nas' => (string)($row['nas_ip'] ?? ''),
        ':u' => (string)($row['username'] ?? ''),
        ':sid' => (string)($row['session_id'] ?? ''),
        ':st' => (string)($row['status_type'] ?? ''),
        ':inb' => (int)($row['input_octets'] ?? 0),
        ':outb' => (int)($row['output_octets'] ?? 0),
        ':stime' => (int)($row['session_time'] ?? 0),
        ':raw' => (string)($row['raw_attrs'] ?? ''),
    ]);
}

function store_insert_radius_accounting_error(array $row): void
{
    $msg = trim((string)($row['message'] ?? ''));
    if ($msg === '') {
        return;
    }
    if (strlen($msg) > 5000) {
        $msg = substr($msg, 0, 5000);
    }
    $raw = (string)($row['raw_attrs'] ?? '');
    if ($raw !== '' && strlen($raw) > 20000) {
        $raw = substr($raw, 0, 20000);
    }
    $type = strtolower(trim((string)($row['error_type'] ?? 'error')));
    if ($type === '') {
        $type = 'error';
    }
    if (!in_array($type, ['error', 'warning'], true)) {
        $type = 'error';
    }

    try {
        $pdo = db();
        $stmt = $pdo->prepare(
            "INSERT INTO radius_accounting_errors
                (ts, router_ip, peer_ip, nas_ip, username, session_id, status_type, error_type, message, raw_attrs)
             VALUES
                (:ts, :rip, :pip, :nas, :u, :sid, :st, :typ, :msg, :raw)"
        );
        $stmt->execute([
            ':ts' => (int)($row['ts'] ?? time()),
            ':rip' => (string)($row['router_ip'] ?? ''),
            ':pip' => (string)($row['peer_ip'] ?? ''),
            ':nas' => (string)($row['nas_ip'] ?? ''),
            ':u' => (string)($row['username'] ?? ''),
            ':sid' => (string)($row['session_id'] ?? ''),
            ':st' => (string)($row['status_type'] ?? ''),
            ':typ' => $type,
            ':msg' => $msg,
            ':raw' => $raw !== '' ? $raw : null,
        ]);

        $pdo->exec(
            "DELETE FROM radius_accounting_errors
             WHERE id < (
                 SELECT IFNULL(MIN(id), 0)
                 FROM (
                     SELECT id FROM radius_accounting_errors ORDER BY id DESC LIMIT 20000
                 ) t
             )"
        );
    } catch (Throwable $e) {
        return;
    }
}

function store_find_user_by_username(string $username): ?array
{
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM admin_users WHERE LOWER(username) = LOWER(:u) LIMIT 1");
    $stmt->execute([':u' => $username]);
    $row = $stmt->fetch();
    if (is_array($row)) {
        return $row;
    }
    return null;
}

function store_get_user(string $userId): ?array
{
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM admin_users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch();
    if (is_array($row)) {
        return $row;
    }
    return null;
}

function store_upsert_user(array $user): void
{
    $pdo = db();
    $now = gmdate('Y-m-d H:i:s');
    $created = (string)($user['created_at'] ?? $now);
    $stmt = $pdo->prepare(
        "INSERT INTO admin_users (id, username, password_hash, full_name, email, phone, image_path, role, theme, must_change_password, created_at)
         VALUES (:id, :username, :password_hash, :full_name, :email, :phone, :image_path, :role, :theme, :must_change_password, :created_at)
         ON DUPLICATE KEY UPDATE
            username = VALUES(username),
            password_hash = VALUES(password_hash),
            full_name = VALUES(full_name),
            email = VALUES(email),
            phone = VALUES(phone),
            image_path = VALUES(image_path),
            role = VALUES(role),
            theme = VALUES(theme),
            must_change_password = VALUES(must_change_password)"
    );
    $stmt->execute([
        ':id' => (string)($user['id'] ?? ''),
        ':username' => (string)($user['username'] ?? ''),
        ':password_hash' => (string)($user['password_hash'] ?? ''),
        ':full_name' => ($user['full_name'] ?? null) !== null ? (string)$user['full_name'] : null,
        ':email' => ($user['email'] ?? null) !== null ? (string)$user['email'] : null,
        ':phone' => ($user['phone'] ?? null) !== null ? (string)$user['phone'] : null,
        ':image_path' => ($user['image_path'] ?? null) !== null ? (string)$user['image_path'] : null,
        ':role' => (string)($user['role'] ?? 'admin'),
        ':theme' => (string)($user['theme'] ?? 'light'),
        ':must_change_password' => (int)($user['must_change_password'] ?? 0),
        ':created_at' => $created,
    ]);
}

function store_delete_user(string $userId): void
{
    $pdo = db();
    $stmt = $pdo->prepare("DELETE FROM admin_users WHERE id = :id");
    $stmt->execute([':id' => $userId]);
}

function store_update_user_password(string $userId, string $newPasswordHash, int $mustChangePassword): void
{
    $pdo = db();
    $stmt = $pdo->prepare("UPDATE admin_users SET password_hash = :h, must_change_password = :m WHERE id = :id");
    $stmt->execute([
        ':h' => $newPasswordHash,
        ':m' => $mustChangePassword,
        ':id' => $userId,
    ]);
}

function store_count_superadmins(): int
{
    $pdo = db();
    $stmt = $pdo->query("SELECT COUNT(*) FROM admin_users WHERE role = 'superadmin'");
    return (int)$stmt->fetchColumn();
}
