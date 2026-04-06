<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $cfg = app_config()['db'] ?? [];
    $host = (string)($cfg['host'] ?? '127.0.0.1');
    $port = (int)($cfg['port'] ?? 3306);
    $dbName = (string)($cfg['name'] ?? 'cityuniversity');
    $user = (string)($cfg['user'] ?? 'root');
    $pass = (string)($cfg['pass'] ?? '');
    $charset = (string)($cfg['charset'] ?? 'utf8mb4');

    if (!preg_match('/^[a-zA-Z0-9_]+$/', $dbName)) {
        throw new RuntimeException('Invalid database name.');
    }

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    $rootDsn = "mysql:host={$host};port={$port};charset={$charset}";
    $root = new PDO($rootDsn, $user, $pass, $options);
    $root->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET {$charset} COLLATE {$charset}_unicode_ci");

    $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset={$charset}";
    $pdo = new PDO($dsn, $user, $pass, $options);
    return $pdo;
}

function db_migrate(): void
{
    $pdo = db();

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admin_users (
            id CHAR(32) PRIMARY KEY,
            username VARCHAR(64) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            full_name VARCHAR(128) NULL,
            email VARCHAR(190) NULL,
            phone VARCHAR(32) NULL,
            image_path VARCHAR(255) NULL,
            role ENUM('superadmin','admin') NOT NULL DEFAULT 'admin',
            theme ENUM('light','dark') NOT NULL DEFAULT 'light',
            must_change_password TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $cols = $pdo->query("SHOW COLUMNS FROM admin_users")->fetchAll();
    $existingCols = [];
    foreach ($cols as $c) {
        if (is_array($c) && isset($c['Field'])) {
            $existingCols[] = (string)$c['Field'];
        }
    }
    if (!in_array('full_name', $existingCols, true)) {
        $pdo->exec("ALTER TABLE admin_users ADD COLUMN full_name VARCHAR(128) NULL AFTER password_hash");
    }
    if (!in_array('email', $existingCols, true)) {
        $pdo->exec("ALTER TABLE admin_users ADD COLUMN email VARCHAR(190) NULL AFTER full_name");
    }
    if (!in_array('phone', $existingCols, true)) {
        $pdo->exec("ALTER TABLE admin_users ADD COLUMN phone VARCHAR(32) NULL AFTER email");
    }
    if (!in_array('image_path', $existingCols, true)) {
        $pdo->exec("ALTER TABLE admin_users ADD COLUMN image_path VARCHAR(255) NULL AFTER phone");
    }
    if (!in_array('role', $existingCols, true)) {
        $pdo->exec("ALTER TABLE admin_users ADD COLUMN role ENUM('superadmin','admin') NOT NULL DEFAULT 'admin' AFTER image_path");
    }
    if (!in_array('must_change_password', $existingCols, true)) {
        $pdo->exec("ALTER TABLE admin_users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER theme");
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS routers (
            id CHAR(32) PRIMARY KEY,
            name VARCHAR(128) NOT NULL,
            ip VARCHAR(45) NOT NULL,
            api_port INT NOT NULL DEFAULT 8728,
            snmp_port INT NOT NULL DEFAULT 161,
            snmp_version ENUM('1','2c') NOT NULL DEFAULT '2c',
            username VARCHAR(64) NOT NULL,
            password VARCHAR(255) NOT NULL,
            snmp_community VARCHAR(64) NOT NULL DEFAULT 'public',
            monitor_interface VARCHAR(64) NOT NULL DEFAULT 'ether1',
            monitor_capacity_mbps INT NOT NULL DEFAULT 100,
            radius_secret VARCHAR(128) NOT NULL DEFAULT '',
            radius_enabled TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $routerCols = $pdo->query("SHOW COLUMNS FROM routers")->fetchAll();
    $routerExisting = [];
    foreach ($routerCols as $c) {
        if (is_array($c) && isset($c['Field'])) {
            $routerExisting[] = (string)$c['Field'];
        }
    }
    if (!in_array('snmp_version', $routerExisting, true)) {
        $pdo->exec("ALTER TABLE routers ADD COLUMN snmp_version ENUM('1','2c') NOT NULL DEFAULT '2c' AFTER snmp_port");
    }
    if (!in_array('monitor_capacity_mbps', $routerExisting, true)) {
        $pdo->exec("ALTER TABLE routers ADD COLUMN monitor_capacity_mbps INT NOT NULL DEFAULT 100 AFTER monitor_interface");
    }
    if (!in_array('radius_secret', $routerExisting, true)) {
        $pdo->exec("ALTER TABLE routers ADD COLUMN radius_secret VARCHAR(128) NOT NULL DEFAULT '' AFTER monitor_capacity_mbps");
    }
    if (!in_array('radius_enabled', $routerExisting, true)) {
        $pdo->exec("ALTER TABLE routers ADD COLUMN radius_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER radius_secret");
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS router_stats (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            router_id CHAR(32) NOT NULL,
            ts INT UNSIGNED NOT NULL,
            active_users INT UNSIGNED NOT NULL,
            INDEX idx_router_ts (router_id, ts),
            CONSTRAINT fk_router_stats_router FOREIGN KEY (router_id) REFERENCES routers(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS router_monitor_interfaces (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            router_id CHAR(32) NOT NULL,
            interface_name VARCHAR(64) NOT NULL,
            capacity_mbps INT NOT NULL DEFAULT 100,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_router_interface (router_id, interface_name),
            CONSTRAINT fk_router_monitor_interfaces_router FOREIGN KEY (router_id) REFERENCES routers(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS router_interface_traffic_max (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            router_id CHAR(32) NOT NULL,
            interface_name VARCHAR(64) NOT NULL,
            max_tx_mbps DOUBLE NOT NULL DEFAULT 0,
            max_rx_mbps DOUBLE NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_router_iface (router_id, interface_name),
            CONSTRAINT fk_router_iface_max_router FOREIGN KEY (router_id) REFERENCES routers(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS router_interface_snmp_cache (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            router_id CHAR(32) NOT NULL,
            interface_name VARCHAR(64) NOT NULL,
            last_in_octets DOUBLE NOT NULL DEFAULT 0,
            last_out_octets DOUBLE NOT NULL DEFAULT 0,
            last_ts INT UNSIGNED NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_router_iface_cache (router_id, interface_name),
            CONSTRAINT fk_router_iface_cache_router FOREIGN KEY (router_id) REFERENCES routers(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS radius_users (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(64) NOT NULL,
            profile VARCHAR(64) NOT NULL DEFAULT '',
            package_id BIGINT UNSIGNED NULL,
            quota_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            password_hash VARCHAR(255) NOT NULL,
            disabled TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_radius_username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $ruCols = $pdo->query("SHOW COLUMNS FROM radius_users")->fetchAll();
    $ruExisting = [];
    foreach ($ruCols as $c) {
        if (is_array($c) && isset($c['Field'])) {
            $ruExisting[] = (string)$c['Field'];
        }
    }
    if (!in_array('profile', $ruExisting, true)) {
        $pdo->exec("ALTER TABLE radius_users ADD COLUMN profile VARCHAR(64) NOT NULL DEFAULT '' AFTER username");
    }
    if (!in_array('package_id', $ruExisting, true)) {
        $pdo->exec("ALTER TABLE radius_users ADD COLUMN package_id BIGINT UNSIGNED NULL AFTER profile");
    }
    if (!in_array('quota_bytes', $ruExisting, true)) {
        $pdo->exec("ALTER TABLE radius_users ADD COLUMN quota_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER package_id");
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS radius_packages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(64) NOT NULL,
            rate_limit VARCHAR(64) NOT NULL DEFAULT '',
            quota_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_radius_pkg_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS radius_user_usage (
            username VARCHAR(64) PRIMARY KEY,
            used_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS radius_session_usage (
            session_id VARCHAR(128) PRIMARY KEY,
            username VARCHAR(64) NOT NULL DEFAULT '',
            last_in BIGINT UNSIGNED NOT NULL DEFAULT 0,
            last_out BIGINT UNSIGNED NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL,
            INDEX idx_radius_sess_user (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS hotspot_profile_limits (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            router_id CHAR(32) NOT NULL,
            profile_name VARCHAR(64) NOT NULL,
            rate_limit VARCHAR(64) NOT NULL DEFAULT '',
            quota_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_router_profile (router_id, profile_name),
            CONSTRAINT fk_hotspot_profile_limits_router FOREIGN KEY (router_id) REFERENCES routers(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS radius_accounting (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ts INT UNSIGNED NOT NULL,
            nas_ip VARCHAR(45) NOT NULL,
            username VARCHAR(64) NOT NULL DEFAULT '',
            session_id VARCHAR(128) NOT NULL DEFAULT '',
            status_type VARCHAR(32) NOT NULL DEFAULT '',
            input_octets BIGINT UNSIGNED NOT NULL DEFAULT 0,
            output_octets BIGINT UNSIGNED NOT NULL DEFAULT 0,
            session_time INT UNSIGNED NOT NULL DEFAULT 0,
            raw_attrs MEDIUMTEXT NULL,
            INDEX idx_radius_ts (ts),
            INDEX idx_radius_user (username),
            INDEX idx_radius_session (session_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS app_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ts INT UNSIGNED NOT NULL,
            level VARCHAR(16) NOT NULL DEFAULT 'info',
            category VARCHAR(32) NOT NULL DEFAULT 'app',
            message TEXT NOT NULL,
            context_json MEDIUMTEXT NULL,
            INDEX idx_app_logs_ts (ts),
            INDEX idx_app_logs_cat_ts (category, ts),
            INDEX idx_app_logs_level_ts (level, ts)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $cacheCols = $pdo->query("SHOW COLUMNS FROM router_interface_snmp_cache")->fetchAll();
    $cacheTypes = [];
    foreach ($cacheCols as $c) {
        if (is_array($c) && isset($c['Field'], $c['Type'])) {
            $cacheTypes[(string)$c['Field']] = strtolower((string)$c['Type']);
        }
    }
    if (isset($cacheTypes['last_in_octets']) && str_starts_with($cacheTypes['last_in_octets'], 'double')) {
        $pdo->exec("ALTER TABLE router_interface_snmp_cache MODIFY COLUMN last_in_octets DECIMAL(20,0) NOT NULL DEFAULT 0");
    }
    if (isset($cacheTypes['last_out_octets']) && str_starts_with($cacheTypes['last_out_octets'], 'double')) {
        $pdo->exec("ALTER TABLE router_interface_snmp_cache MODIFY COLUMN last_out_octets DECIMAL(20,0) NOT NULL DEFAULT 0");
    }

    $count = (int)$pdo->query("SELECT COUNT(*) AS c FROM admin_users")->fetchColumn();
    if ($count === 0) {
        $stmt = $pdo->prepare("INSERT INTO admin_users (id, username, password_hash, role, theme, must_change_password, created_at) VALUES (:id, :u, :p, 'superadmin', 'light', 0, :c)");
        $stmt->execute([
            ':id' => bin2hex(random_bytes(16)),
            ':u' => 'admin',
            ':p' => password_hash('admin123', PASSWORD_DEFAULT),
            ':c' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    $pdo->exec("UPDATE admin_users SET role = 'superadmin' WHERE username = 'admin' AND (role IS NULL OR role = '' OR role = 'admin')");
}
