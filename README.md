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
   `c:\xampp\htdocs\cityuniversity\`
3. ডাটাবেস অটো-মাইগ্রেশন চালু থাকে (প্রথমবার পেজ লোড করলেই টেবিল তৈরি হবে)
4. ডাটাবেস কনফিগ:  
   `c:\xampp\htdocs\cityuniversity\admin\_lib\config.php`

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
```

### 2.1.2) প্রজেক্ট কোড ডেপলয়

```bash
cd /var/www
sudo git clone https://github.com/nurinfobd/brhs.git cityuniversity
sudo chown -R www-data:www-data /var/www/cityuniversity
```

### 2.1.3) Apache VirtualHost সেটআপ

Example (HTTP only):

```bash
sudo a2enmod rewrite
sudo nano /etc/apache2/sites-available/cityuniversity.conf
```

`cityuniversity.conf` এ উদাহরণ:

```apache
<VirtualHost *:80>
    ServerName your-domain.com
    DocumentRoot /var/www/cityuniversity

    <Directory /var/www/cityuniversity>
        AllowOverride All
        Require all granted
    </Directory>

    SetEnv CITYU_DB_HOST 127.0.0.1
    SetEnv CITYU_DB_PORT 3306
    SetEnv CITYU_DB_NAME cityuniversity
    SetEnv CITYU_DB_USER cityu_user
    SetEnv CITYU_DB_PASS your_password
</VirtualHost>
```

Enable করে restart দিন:

```bash
sudo a2ensite cityuniversity.conf
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
CREATE DATABASE cityuniversity CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'cityu_user'@'localhost' IDENTIFIED BY 'your_password';
GRANT ALL PRIVILEGES ON cityuniversity.* TO 'cityu_user'@'localhost';
FLUSH PRIVILEGES;
```

Port যদি 3306 না হয় (যেমন 3307), তাহলে Apache `SetEnv CITYU_DB_PORT` এ সেট করুন।

### 2.1.5) Web Installer রান (First Admin)

Browser থেকে ওপেন করুন:

- `http://your-domain.com/setupdb.php` (optional: শুধু DB create helper)
- `http://your-domain.com/install.php` (Super Admin create + migration verify)

### 2.1.6) RADIUS daemon Always-On (systemd)

RADIUS daemon সবসময় চালু রাখতে systemd service তৈরি করুন:

```bash
sudo nano /etc/systemd/system/cityu-radiusd.service
```

Example:

```ini
[Unit]
Description=CityUniversity RADIUS Daemon
After=network.target mariadb.service

[Service]
Type=simple
WorkingDirectory=/var/www/cityuniversity
ExecStart=/usr/bin/php /var/www/cityuniversity/admin/radiusd.php
Restart=always
RestartSec=2

Environment=CITYU_DB_HOST=127.0.0.1
Environment=CITYU_DB_PORT=3306
Environment=CITYU_DB_NAME=cityuniversity
Environment=CITYU_DB_USER=cityu_user
Environment=CITYU_DB_PASS=your_password

[Install]
WantedBy=multi-user.target
```

Enable + start:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now cityu-radiusd.service
sudo systemctl status cityu-radiusd.service
```

Logs দেখতে:

```bash
sudo journalctl -u cityu-radiusd.service -f
```

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
`c:\xampp\htdocs\cityuniversity\admin\radiusd.php`

### Run Command (Windows)

1. PowerShell / CMD খুলুন  
2. এই ডিরেক্টরিতে যান:
   - `cd c:\xampp\htdocs\cityuniversity`
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
