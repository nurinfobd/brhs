# Mikrotik Hotspot Admin Portal (RADIUS) — Setup Guide (Bangla)

এই প্রজেক্টটি MikroTik Hotspot ইউজার Authentication এর জন্য **RADIUS Server** হিসেবে কাজ করতে পারে এবং একই সাথে Admin Portal থেকে Router / SNMP Traffic / Hotspot User Management দেখা যায়।

---

## 1) প্রি-রিকুইজিট (Prerequisites)

- Windows + XAMPP (Apache + MySQL চালু থাকতে হবে)
- PHP CLI (XAMPP এর PHP)
- MikroTik RouterOS এ Hotspot কনফিগার করা থাকতে হবে
- Portal host থেকে UDP **1812 (Auth)** এবং **1813 (Accounting)** পোর্টে ট্রাফিক Allow করতে হবে

---

## 2) ডাটাবেস / XAMPP সেটআপ

1. XAMPP Control Panel থেকে **Apache** এবং **MySQL** Start করুন  
2. প্রজেক্টের ফোল্ডার:  
   `c:\xampp\htdocs\brhsniversity\`
3. ডাটাবেস অটো-মাইগ্রেশন চালু থাকে (প্রথমবার পেজ লোড করলেই টেবিল তৈরি হবে)
4. ডাটাবেস কনফিগ:  
   `c:\xampp\htdocs\brhsniversity\admin\_lib\config.php`

---

## 2.1) Ubuntu 24.04 Server Deploy (Apache + PHP + MariaDB)

এই সেকশনে `dbname / user / password / port` যেকোনো সার্ভারে পরিবর্তন করে কীভাবে deploy করবেন তা দেখানো হলো।

### 2.1.1) প্রয়োজনীয় প্যাকেজ ইনস্টল

```bash
sudo apt update
sudo apt install -y apache2 mariadb-server git unzip
sudo apt install -y php php-cli php-mysql php-curl php-xml php-mbstring php-zip
```

RADIUS daemon এর জন্য PHP sockets module দরকার। চেক করুন:

```bash
php -m | grep -i sockets
---------------------------------------------
root@railway-srv:~# php -m | grep -i sockets
sockets
```

### 2.1.2) প্রজেক্ট কোড ডেপলয়

```bash
cd /var/www/html
sudo git clone https://github.com/nurinfobd/brhs.git brhs
sudo chown -R www-data:www-data /var/www/html/brhs
```

### 2.1.3) Apache VirtualHost সেটআপ

Example (HTTP only):

```bash
systemctl restart apache2
sudo a2enmod rewrite
sudo nano /etc/apache2/sites-available/brhs.conf
```

`brhs.conf` এ উদাহরণ:

```apache
<VirtualHost *:80>
    ServerName your-domain.com
    DocumentRoot /var/www/html/brhs

    <Directory /var/www/html/brhs>
        AllowOverride All
        Require all granted
    </Directory>

    SetEnv CITYU_DB_HOST 127.0.0.1
    SetEnv CITYU_DB_PORT 3306
    SetEnv CITYU_DB_NAME brhsDB
    SetEnv CITYU_DB_USER brhs_user
    SetEnv CITYU_DB_PASS asdf@123
</VirtualHost>
```

Enable করে restart দিন:

```bash
systemctl reload apache2
sudo a2ensite brhs.conf
systemctl reload apache2
sudo a2dissite 000-default.conf
sudo systemctl reload apache2
```

### 2.1.4) Database Create (DB Name / User / Password / Port)

MariaDB তে DB + user তৈরি করুন:

```bash
sudo mysql
```

তারপর:

```sql
CREATE DATABASE brhsDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'brhs_user'@'localhost' IDENTIFIED BY 'asdf@123';
GRANT ALL PRIVILEGES ON brhsDB.* TO 'brhs_user'@'localhost';
FLUSH PRIVILEGES;
quit;
```

Port যদি 3306 না হয় (যেমন 3307), তাহলে Apache `SetEnv brhs_DB_PORT` এ সেট করুন।

### 2.1.5) Web Installer রান (First Admin)

Browser থেকে ওপেন করুন:

- `http://your-domain.com/setupdb.php` (optional: শুধু DB create helper)
- `http://your-domain.com/install.php` (Super Admin create + migration verify)

### 2.1.6) RADIUS daemon Always-On (systemd)

RADIUS daemon সবসময় চালু রাখতে systemd service তৈরি করুন:

```bash
sudo nano /etc/systemd/system/brhs-radiusd.service
```

Example:

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
Environment=CITYU_DB_PASS=asdf@123

[Install]
WantedBy=multi-user.target
```

Enable + start:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now brhs-radiusd.service
sudo systemctl status brhs-radiusd.service
```

Logs দেখতে:

```bash
sudo journalctl -u brhs-radiusd.service -f   
```
root@railway-srv:/var/www/html# sudo journalctl -u brhs-radiusd.service -f   
Apr 06 11:41:51 railway-srv systemd[1]: Started brhs-radiusd.service - Brhs RADIUS Daemon.
Apr 06 11:41:51 railway-srv php[39569]: RADIUS started on 0.0.0.0:1812/1813

### 2.1.7) Firewall (Important)

Ubuntu UFW ব্যবহার করলে:

```bash
sudo ufw allow 80/tcp
sudo ufw allow 1812/udp
sudo ufw allow 1813/udp
sudo ufw enable
sudo ufw status
```

---

## 3) Portal এ Router যোগ করা (Router Config in Portal)

1. Portal → **Router** মেনুতে যান  
2. **Add Router** এ ক্লিক করুন  
3. প্রয়োজনীয় ইনফো দিন:
   - Name, Address (Router IP)
   - API Port / User / Password
   - SNMP Version / Port / Community
4. (Optional) **Check Connection** দিয়ে API/SNMP টেস্ট করুন  
5. Router Save করুন

### RADIUS Secret সেট করা (Portal Side)

1. Portal → **Router** → Edit Router  
2. **RADIUS** সেকশনে:
   - **RADIUS Secret** দিন (এটা MikroTik এর secret এর সাথে একদম মিল থাকতে হবে)
   - **Enable** টিক দিন
3. Save করুন

---

## 4) Portal এ Hotspot User (RADIUS Users) যোগ করা

1. Sidebar → **Hotspot User**  
2. **Add User**  
3. Username / Password দিন  
4. Disabled থাকলে টিক দিন (Disabled হলে login reject হবে)

---

## 5) RADIUS Server চালু করা (Auth/Accounting)

এই প্রজেক্টে RADIUS Server আলাদা CLI daemon হিসেবে রান করে:

ফাইল:  
`c:\xampp\htdocs\brhsniversity\admin\radiusd.php`

### Run Command (Windows)

1. PowerShell / CMD খুলুন  
2. এই ডিরেক্টরিতে যান:
   - `cd c:\xampp\htdocs\brhsniversity`
3. RADIUS daemon চালু করুন:
   - `php admin\radiusd.php`

সফল হলে এরকম দেখাবে:
- `RADIUS started on 0.0.0.0:1812/1813`

### Windows Firewall (Important)

Portal server PC তে UDP 1812/1813 Allow না থাকলে MikroTik থেকে RADIUS Request আসবে না।

- UDP 1812 (Authentication)
- UDP 1813 (Accounting)

---

## 6) MikroTik RouterOS এ RADIUS কনফিগ (Hotspot Authentication)

ধরা যাক:
- Portal Server IP = `<PORTAL_IP>`
- RADIUS Secret = `<RADIUS_SECRET>`

### 6.1 RADIUS Server Add (Hotspot Service)

টার্মিনাল/Winbox থেকে:

```routeros
/radius
add address=<PORTAL_IP> service=hotspot secret=<RADIUS_SECRET> authentication-port=1812 accounting-port=1813 timeout=2s
```

### 6.2 Hotspot Profile এ Use-RADIUS Enable

```routeros
/ip hotspot profile
set [find default=yes] use-radius=yes
```

আপনার যদি custom hotspot profile থাকে, তাহলে default=yes এর জায়গায় সেই profile সিলেক্ট করুন।

### 6.3 Accounting Enable (Recommended)

```routeros
/radius
set [find where service~"hotspot"] accounting=yes
```

---

## 7) গুরুত্বপূর্ণ নোট (Very Important Notes)

### 7.1 Router IP Match

এই প্রজেক্টে RADIUS request আসলে **source IP (NAS IP)** দিয়ে Router খোঁজা হয়।  
তাই Portal এ যে Router IP দিয়েছিলেন সেটাই MikroTik থেকে request এর source IP হিসেবে আসা উচিত।

যদি NAT/Multiple IP থাকেঃ  
- Router এর যে IP দিয়ে Portal এ Add করেছেন, MikroTik যেন সেই IP থেকেই request পাঠায়।

### 7.2 Authentication Method

এখনকার implementation এ **PAP (User-Password attribute)** দিয়ে Auth কাজ করে।  
MikroTik Hotspot সাধারণত PAP সাপোর্ট করে।

---

## 8) Test / Verify

1. Portal → **Hotspot User** এ ১টা user add করুন  
2. Portal → **Router** এ RADIUS Enable + Secret Save করুন  
3. `php admin\radiusd.php` রান করুন  
4. MikroTik Hotspot login page থেকে ওই user দিয়ে login চেষ্টা করুন  
5. Portal → **RADIUS Accounting** এ log আসছে কিনা দেখুন

---

## 9) Common Troubleshooting

- **Login হচ্ছে না**
  - MikroTik এ secret এবং Portal এ secret এক কিনা দেখুন
  - Firewall এ UDP 1812/1813 allow আছে কিনা দেখুন
  - RADIUS daemon (radiusd.php) চালু আছে কিনা দেখুন
  - Portal এ Router এ RADIUS Enable করা আছে কিনা দেখুন

- **Accounting log আসছে না**
  - MikroTik `/radius` এ accounting=yes আছে কিনা দেখুন
  - UDP 1813 allow আছে কিনা দেখুন

---

## 10) Update / Upgrade (আগে deploy করা সার্ভারে আপডেট)

এই প্রজেক্টে DB migration অটো রান হয়:
- Admin portal page load হলে (যেমন `admin/login.php`)
- এবং RADIUS daemon start হলে (`php admin/radiusd.php`)

তাই update করার পর সাধারণত শুধু **code update + daemon restart** করলেই হবে।

### 10.1) Ubuntu 24.04 (Git deploy)

1) Code update:

```bash
cd /var/www/html/brhs
sudo git pull
sudo chown -R www-data:www-data /var/www/html/brhs
sudo systemctl reload apache2
```

2) Migration trigger:
- Browser থেকে একবার ওপেন করুন: `http://your-domain.com/admin/login.php`

3) RADIUS daemon restart (systemd হলে):

```bash
sudo systemctl restart brhs-radiusd.service
sudo systemctl status brhs-radiusd.service
```

Logs:

```bash
sudo journalctl -u brhs-radiusd.service -f
```

### 10.2) Windows + XAMPP

1) XAMPP Control Panel থেকে Apache + MySQL Start করুন  
2) যদি git ব্যবহার করেন:

```powershell
cd C:\xampp\htdocs\brhsniversity
git pull
```

3) RADIUS daemon restart:
- যে window এ `php admin\radiusd.php` চলছে, সেখানে Ctrl + C চাপুন
- তারপর আবার চালান:

```powershell
cd C:\xampp\htdocs\brhsniversity
php admin\radiusd.php
```

### 10.3) Non‑Git deploy (zip/copy deploy)

1) Backup নিন:
- Database backup (phpMyAdmin export)
- Project folder backup

2) নতুন code কপি করে পুরানো folder এর উপর overwrite করুন, কিন্তু এগুলো preserve করুন:
- `admin/_lib/config.php` (DB config)
- `admin/uploads/` (যদি image upload ব্যবহার করেন)

3) এরপর:
- Admin login page একবার open করুন (migration auto হবে)
- RADIUS daemon restart করুন
=====================================================

## 11) Current Setup + DB Migration (Important)

এই প্রজেক্টে DB schema update আলাদা migration tool না—`admin/_lib/db.php` এর `db_migrate()` **auto run** হয়:
- Admin panel এর যেকোনো page open করলে (যেমন `admin/login.php`)
- RADIUS daemon start হলে (`php admin/radiusd.php`)

### 11.1) New tables (recent updates)

Auto-create হবে:
- `app_logs` → Login / MikroTik API / RADIUS accept-reject-drop / errors
- `radius_accounting_errors` → Accounting packet drop / DB insert error diagnostics

### 11.2) Upgrade steps (Ubuntu)

1) Code update (git)

যদি `git pull` এ “local changes overwritten” আসে (সাধারণত `admin/_lib/config.php` বা `admin/_partials/layout.php`):

```bash
cd /var/www/html/brhs
sudo git stash push -m "local changes before pull" admin/_lib/config.php admin/_partials/layout.php
sudo git pull
sudo git stash pop
```

2) DB migration run
- Browser দিয়ে `http://your-domain.com/admin/login.php` একবার open করুন

3) RADIUS daemon restart

```bash
sudo systemctl restart brhs-radiusd.service
sudo systemctl status brhs-radiusd.service --no-pager
```

4) Live logs

```bash
sudo journalctl -u brhs-radiusd.service -f
```

### 11.3) DB config (recommended)

Ubuntu deploy এ DB config ফাইল edit না করে Environment variables ব্যবহার করুন:
- `CITYU_DB_HOST`
- `CITYU_DB_PORT`
- `CITYU_DB_NAME`
- `CITYU_DB_USER`
- `CITYU_DB_PASS`

systemd example: `/etc/systemd/system/brhs-radiusd.service`

### 11.4) Debug / Status pages

- Status page: `admin/status.php`
- Accounting errors page: `admin/radius-accounting-errors.php`

RADIUS daemon debug (systemd):

```ini
Environment=CITYU_RADIUS_DEBUG=1
```

### 11.5) If accounting logs are empty

1) Server এ UDP 1813 packet আসছে কি না দেখুন:

```bash
sudo tcpdump -ni any udp port 1813
```

2) MikroTik এ accounting enable আছে কি না:

```routeros
/radius print detail
/radius set [find where service~"hotspot"] accounting=yes
```

3) Firewall allow:
- UDP 1812 (Auth)
- UDP 1813 (Accounting)
