# Ubuntu Local Setup (Portal + RADIUS)

This guide sets up the portal and the PHP RADIUS daemon (UDP 1812/1813) on a single Ubuntu server, including database creation and auto migration.

## Requirements
- Ubuntu 22.04/24.04
- sudo access
- UDP 1812 (Auth) and 1813 (Accounting) inbound allowed to the server

## One‑Shot Install Script
Run this on Ubuntu to install everything at once:

```bash
sudo bash -lc '
set -euo pipefail

APP_DIR="/var/www/html/brhs"
DB_NAME="brhsDB"
DB_USER="brhs_user"
DB_PASS="asdf123"
DB_HOST="127.0.0.1"
DB_PORT="3306"
SERVER_NAME="_"

apt update
apt install -y apache2 mariadb-server git unzip
apt install -y php php-cli php-mysql php-curl php-xml php-mbstring php-zip
php -m | grep -qi sockets

mkdir -p /var/www/html
if [ ! -d "$APP_DIR/.git" ]; then
  git clone https://github.com/nurinfobd/brhs.git "$APP_DIR"
else
  cd "$APP_DIR"
  git stash push -m "local before pull" admin/_lib/config.php admin/_partials/layout.php || true
  git pull
fi
chown -R www-data:www-data "$APP_DIR"

mysql -uroot <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS "$DB_USER"@"localhost" IDENTIFIED BY "$DB_PASS";
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO "$DB_USER"@"localhost";
FLUSH PRIVILEGES;
SQL

cat >/etc/apache2/sites-available/brhs.conf <<EOF
<VirtualHost *:80>
    ServerName ${SERVER_NAME}
    DocumentRoot ${APP_DIR}
    <Directory ${APP_DIR}>
        AllowOverride All
        Require all granted
    </Directory>
    SetEnv CITYU_DB_HOST ${DB_HOST}
    SetEnv CITYU_DB_PORT ${DB_PORT}
    SetEnv CITYU_DB_NAME ${DB_NAME}
    SetEnv CITYU_DB_USER ${DB_USER}
    SetEnv CITYU_DB_PASS ${DB_PASS}
</VirtualHost>
EOF
a2enmod rewrite
a2ensite brhs.conf
a2dissite 000-default.conf || true
systemctl reload apache2

cat >/etc/systemd/system/brhs-radiusd.service <<EOF
[Unit]
Description=Brhs RADIUS Daemon
After=network.target mariadb.service
[Service]
Type=simple
WorkingDirectory=${APP_DIR}
ExecStart=/usr/bin/php ${APP_DIR}/admin/radiusd.php
Restart=always
RestartSec=2
Environment=CITYU_DB_HOST=${DB_HOST}
Environment=CITYU_DB_PORT=${DB_PORT}
Environment=CITYU_DB_NAME=${DB_NAME}
Environment=CITYU_DB_USER=${DB_USER}
Environment=CITYU_DB_PASS=${DB_PASS}
# Environment=CITYU_RADIUS_DEBUG=1
[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable --now brhs-radiusd.service

ufw allow 80/tcp || true
ufw allow 1812/udp || true
ufw allow 1813/udp || true
systemctl status brhs-radiusd.service --no-pager || true
ss -lunp | egrep ":1812|:1813" || true
'
```

## Step‑By‑Step Breakdown
1) Install packages  
```bash
sudo apt update
sudo apt install -y apache2 mariadb-server git unzip
sudo apt install -y php php-cli php-mysql php-curl php-xml php-mbstring php-zip
```

2) Verify PHP sockets  
```bash
php -m | grep -i sockets
```

3) Deploy project  
```bash
cd /var/www/html
sudo git clone https://github.com/nurinfobd/brhs.git brhs
sudo chown -R www-data:www-data /var/www/html/brhs
```
If already present:
```bash
cd /var/www/html/brhs
sudo git stash push -m "local before pull" admin/_lib/config.php admin/_partials/layout.php || true
sudo git pull
sudo chown -R www-data:www-data /var/www/html/brhs
```

4) Create DB + user  
```bash
sudo mysql
```
```sql
CREATE DATABASE brhsDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'brhs_user'@'localhost' IDENTIFIED BY 'asdf123';
GRANT ALL PRIVILEGES ON brhsDB.* TO 'brhs_user'@'localhost';
FLUSH PRIVILEGES;
exit
```
Test:
```bash
mysql -h 127.0.0.1 -P 3306 -u brhs_user -p
```

5) Apache VirtualHost  
```apache
<VirtualHost *:80>
    ServerName _
    DocumentRoot /var/www/html/brhs
    <Directory /var/www/html/brhs>
        AllowOverride All
        Require all granted
    </Directory>
    SetEnv CITYU_DB_HOST 127.0.0.1
    SetEnv CITYU_DB_PORT 3306
    SetEnv CITYU_DB_NAME brhsDB
    SetEnv CITYU_DB_USER brhs_user
    SetEnv CITYU_DB_PASS asdf123
</VirtualHost>
```
Enable + reload:
```bash
sudo a2enmod rewrite
sudo a2ensite brhs.conf
sudo a2dissite 000-default.conf || true
sudo systemctl reload apache2
```

6) RADIUS daemon service  
```ini
[Unit]
Description=Brhs RADIUS Daemon
After=network.target mariadb.service
[Service]
Type=simple
WorkingDirectory=/var/www/html/brhs
ExecStart=/usr/bin/php /var/www/html/brhs/admin/radiusd.php
Restart=always
RestartSec=2
Environment=CITYU_DB_HOST=127.0.0.1
Environment=CITYU_DB_PORT=3306
Environment=CITYU_DB_NAME=brhsDB
Environment=CITYU_DB_USER=brhs_user
Environment=CITYU_DB_PASS=asdf123
# Environment=CITYU_RADIUS_DEBUG=1
[Install]
WantedBy=multi-user.target
```
Enable + start:
```bash
sudo systemctl daemon-reload
sudo systemctl enable --now brhs-radiusd.service
sudo systemctl status brhs-radiusd.service --no-pager
sudo ss -lunp | egrep ":1812|:1813"
```

7) Firewall (UFW)  
```bash
sudo ufw allow 80/tcp
sudo ufw allow 1812/udp
sudo ufw allow 1813/udp
sudo ufw status verbose
```

8) Install + Migration  
- Open `http://<SERVER_IP>/install.php` to create Super Admin  
- Open `http://<SERVER_IP>/admin/login.php` once to run migration

9) Logs and debug  
```bash
sudo journalctl -u brhs-radiusd.service -f
sudo tcpdump -ni any udp port 1812 or udp port 1813
```
Enable daemon debug (optional): set `Environment=CITYU_RADIUS_DEBUG=1` in the service and restart.

10) MikroTik (Hotspot)  
```routeros
/radius add address=<SERVER_IP> service=hotspot secret=<SAME_SECRET_AS_PORTAL> authentication-port=1812 accounting-port=1813 timeout=2s
/ip hotspot profile set [find default=yes] use-radius=yes
/radius set [find where service~"hotspot"] accounting=yes
```

## Status & Errors
- Status page: `admin/status.php`  
- Accounting errors: `admin/radius-accounting-errors.php`
