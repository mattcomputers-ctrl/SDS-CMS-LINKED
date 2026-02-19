# SDS System -- TurnKey LAMP Deployment Guide

Complete deployment guide for the Safety Data Sheet (SDS) Authoring & Generation System on a TurnKey Linux LAMP stack running in a Proxmox virtual environment.

**Target stack:** TurnKey LAMP (Debian-based) on Proxmox VE
**Application:** PHP 8.x with MySQL/MariaDB, Apache 2.4, Composer
**Repository:** `sds-system/sds-system`

---

## Table of Contents

1. [VM Sizing and TurnKey LAMP Installation](#1-vm-sizing-and-turnkey-lamp-installation)
2. [System Updates and Required Packages](#2-system-updates-and-required-packages)
3. [Apache Virtual Host Configuration](#3-apache-virtual-host-configuration)
4. [Database Creation](#4-database-creation)
5. [Application Deployment](#5-application-deployment)
6. [Running Migrations and Seeding](#6-running-migrations-and-seeding)
7. [File Permissions](#7-file-permissions)
8. [Cron Job Configuration](#8-cron-job-configuration)
9. [SSL/HTTPS Setup with Let's Encrypt](#9-sslhttps-setup-with-lets-encrypt)
10. [Verification Steps](#10-verification-steps)
11. [Troubleshooting Common Issues](#11-troubleshooting-common-issues)
12. [Backup and Restore](#12-backup-and-restore)
13. [Updating the Application](#13-updating-the-application)

---

## 1. VM Sizing and TurnKey LAMP Installation

### Recommended VM Specifications

| Resource   | Minimum     | Recommended  | Notes                                              |
|------------|-------------|--------------|-----------------------------------------------------|
| CPU Cores  | 2           | 4            | PubChem API calls and PDF generation are CPU-bound  |
| RAM        | 2 GB        | 4 GB         | MariaDB + PHP workers + TCPDF rendering             |
| Disk       | 20 GB       | 40 GB        | Uploaded supplier SDS PDFs and generated PDFs grow over time |
| Network    | 1 NIC (vmbr0) | 1 NIC      | Outbound HTTPS needed for PubChem, NIOSH, EPA APIs  |

### Creating the VM in Proxmox

1. Download the TurnKey LAMP template from the Proxmox storage or fetch it from the TurnKey repository:

```bash
# On the Proxmox host, download the TurnKey LAMP template
pveam update
pveam available --section turnkeylinux | grep lamp
pveam download local turnkey-lamp-18.0-bookworm-amd64.tar.gz
```

2. Create a new container (LXC) or VM. LXC is recommended for lower overhead:

```bash
# LXC container creation (from the Proxmox host)
pct create 200 local:vztmpl/turnkey-lamp-18.0-bookworm-amd64.tar.gz \
  --hostname sds-system \
  --memory 4096 \
  --swap 1024 \
  --cores 4 \
  --rootfs local-lvm:40 \
  --net0 name=eth0,bridge=vmbr0,ip=dhcp \
  --unprivileged 1 \
  --features nesting=1 \
  --start 1
```

Alternatively, use the Proxmox web UI:

- Navigate to **Datacenter > Node > Create CT**
- Select the TurnKey LAMP template
- Set hostname to `sds-system`
- Allocate resources per the table above
- Configure networking (static IP recommended for production)

3. Start the container and complete the TurnKey first-boot wizard:
   - Set the root password
   - Set the MySQL/MariaDB root password (save this -- you will need it in step 4)
   - Skip or configure the TurnKey Hub API key
   - Note the assigned IP address displayed at the end of initialization

4. Log in via SSH:

```bash
ssh root@<container-ip>
```

---

## 2. System Updates and Required Packages

### Update the Base System

```bash
apt update && apt upgrade -y
```

### Install Required Packages

TurnKey LAMP ships with Apache, MySQL/MariaDB, and PHP pre-installed. You need to install additional PHP extensions and tools:

```bash
apt install -y \
  php8.2-mysql \
  php8.2-mbstring \
  php8.2-json \
  php8.2-fileinfo \
  php8.2-curl \
  php8.2-intl \
  php8.2-xml \
  php8.2-zip \
  php8.2-gd \
  unzip \
  git \
  curl \
  cron
```

> **Note:** Replace `8.2` with your actual PHP version if TurnKey ships a different release. Check with `php -v`.

### Install Composer

```bash
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
```

Verify the installations:

```bash
php -v
php -m | grep -E 'pdo_mysql|mbstring|json|fileinfo|curl|intl'
composer --version
apache2 -v
mysql --version
```

Expected output should confirm PHP 8.x, all six extensions listed, Composer 2.x, Apache 2.4.x, and MariaDB/MySQL 10.x or 8.x.

### Configure PHP Settings

Edit the PHP configuration for production use:

```bash
nano /etc/php/8.2/apache2/php.ini
```

Set or verify these values:

```ini
upload_max_filesize = 25M
post_max_size = 30M
memory_limit = 256M
max_execution_time = 120
date.timezone = America/New_York
```

Restart Apache to apply changes:

```bash
systemctl restart apache2
```

---

## 3. Apache Virtual Host Configuration

### Enable Required Apache Modules

```bash
a2enmod rewrite
a2enmod ssl
a2enmod headers
systemctl restart apache2
```

### Create the Virtual Host

Create the vhost configuration file:

```bash
nano /etc/apache2/sites-available/sds-system.conf
```

Paste the following configuration:

```apache
<VirtualHost *:80>
    ServerName sds.yourcompany.com
    ServerAlias sds-system.local
    DocumentRoot /var/www/sds-system/public

    <Directory /var/www/sds-system/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Deny access to sensitive directories
    <DirectoryMatch "/var/www/sds-system/(config|migrations|seeds|src|storage|vendor)">
        Require all denied
    </DirectoryMatch>

    # Logging
    ErrorLog ${APACHE_LOG_DIR}/sds-system-error.log
    CustomLog ${APACHE_LOG_DIR}/sds-system-access.log combined

    # Security headers
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-XSS-Protection "1; mode=block"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
</VirtualHost>
```

### Enable the Site and Disable the Default

```bash
a2ensite sds-system.conf
a2dissite 000-default.conf
apache2ctl configtest
systemctl reload apache2
```

The `apache2ctl configtest` command must output `Syntax OK` before reloading.

### Verify the .htaccess

The application ships with a `.htaccess` file at `public/.htaccess` that handles URL rewriting:

```apache
RewriteEngine On
RewriteBase /

# Protect hidden files
RewriteRule (^\.|/\.) - [F]

# Allow direct access to existing files and directories
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d

# Route everything else through front controller
RewriteRule ^(.*)$ index.php [QSA,L]
```

This file is included in the repository and should not need modification. Apache's `AllowOverride All` directive (set above) is required for this to function.

---

## 4. Database Creation

Log in to MySQL/MariaDB as root using the password you set during TurnKey initialization:

```bash
mysql -u root -p
```

Execute the following SQL statements:

```sql
-- Create the database
CREATE DATABASE IF NOT EXISTS sds_system
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

-- Create the application user
CREATE USER IF NOT EXISTS 'sds_user'@'localhost'
  IDENTIFIED BY 'sds_password';

-- Grant privileges
GRANT ALL PRIVILEGES ON sds_system.* TO 'sds_user'@'localhost';

-- Apply privilege changes
FLUSH PRIVILEGES;

-- Verify
SHOW DATABASES LIKE 'sds_system';
SELECT User, Host FROM mysql.user WHERE User = 'sds_user';

EXIT;
```

> **Security note:** In production, replace `sds_password` with a strong, randomly generated password. Update `config/config.php` accordingly.

Test the connection:

```bash
mysql -u sds_user -p'sds_password' -e "SELECT 1;" sds_system
```

You should see a simple result set with `1`, confirming access is working.

---

## 5. Application Deployment

### Deploy the Application Files

**Option A: Git clone (recommended)**

```bash
cd /var/www
git clone https://your-git-server.com/sds-system/sds-system.git sds-system
```

**Option B: Copy from archive**

```bash
# From your workstation
scp sds-system.tar.gz root@<container-ip>:/var/www/

# On the server
cd /var/www
tar xzf sds-system.tar.gz
mv sds-system-main sds-system   # rename if needed
rm sds-system.tar.gz
```

### Install PHP Dependencies via Composer

```bash
cd /var/www/sds-system
composer install --no-dev --optimize-autoloader
```

This installs TCPDF (`tecnickcom/tcpdf ^6.6`) and sets up the PSR-4 autoloader for the `SDS\` namespace.

Verify TCPDF is installed:

```bash
ls vendor/tecnickcom/tcpdf/tcpdf.php && echo "TCPDF installed successfully"
```

### Configure the Application

Copy the example configuration and edit it:

```bash
cp config/config.example.php config/config.php
nano config/config.php
```

Update these values at a minimum:

```php
return [
    'app' => [
        'name'      => 'SDS System',
        'url'       => 'https://sds.yourcompany.com',  // Your actual URL
        'debug'     => false,                           // NEVER true in production
        'timezone'  => 'America/New_York',
        'version'   => '1.0.0',
    ],

    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'name'     => 'sds_system',
        'user'     => 'sds_user',
        'password' => 'sds_password',   // Use your actual password
        'charset'  => 'utf8mb4',
    ],

    'company' => [
        'name'    => 'Your Company Name, Inc.',
        'address' => '123 Industrial Blvd, Suite 100',
        'city'    => 'Anytown',
        'state'   => 'OH',
        'zip'     => '44000',
        'country' => 'US',
        'phone'   => '(555) 123-4567',
        'fax'     => '(555) 123-4568',
        'email'   => 'sds@yourcompany.com',
        'emergency_phone' => 'CHEMTREC: (800) 424-9300',
        'website' => 'https://www.yourcompany.com',
    ],

    // ... leave remaining sections at defaults unless you need changes
];
```

### Create Required Storage Directories

The storage directories should already exist in the repository, but verify and create any missing ones:

```bash
mkdir -p /var/www/sds-system/public/uploads/supplier-sds
mkdir -p /var/www/sds-system/public/generated-pdfs
mkdir -p /var/www/sds-system/storage/logs
mkdir -p /var/www/sds-system/storage/cache
mkdir -p /var/www/sds-system/storage/temp
mkdir -p /var/www/sds-system/storage/data
```

---

## 6. Running Migrations and Seeding

### Run Database Migrations

The migration runner reads SQL files from `migrations/` and applies them in order. It also creates the database if it does not exist (provided the user has `CREATE DATABASE` privileges):

```bash
cd /var/www/sds-system
php migrations/migrate.php
```

Expected output:

```
Connected to database 'sds_system'.
  APPLYING: 001_create_schema ... OK
Applied 1 migration(s).
```

If you run it again, it will report that all migrations are up to date:

```
Connected to database 'sds_system'.
  SKIP: 001_create_schema (already applied)
All migrations are up to date.
```

### Seed Initial Data

The seed script creates default users, sample raw materials, finished goods, formulas, SARA 313 entries, exempt VOC entries, and application settings:

```bash
php seeds/seed.php
```

Expected output:

```
Seeding database...
  Created users: admin, editor, viewer
  Created 5 raw materials
  Created raw material constituents
  Created CAS master entries
  Created 2 finished goods
  Created formulas with lines
  Seeded SARA 313 list (subset)
  Seeded exempt VOC list
  Seeded settings

Seed complete! Default login: admin / SDS-Admin-2024!
```

### Default User Accounts

| Username | Password           | Role     |
|----------|--------------------|----------|
| admin    | `SDS-Admin-2024!`  | admin    |
| editor   | `SDS-Editor-2024!` | editor   |
| viewer   | `SDS-Viewer-2024!` | readonly |

> **IMPORTANT:** Change all default passwords immediately after first login. The admin password in particular should be changed before the system is accessible on the network.

---

## 7. File Permissions

### Set Ownership

All application files must be owned by `www-data` (the Apache user on Debian-based systems):

```bash
chown -R www-data:www-data /var/www/sds-system
```

### Set Directory and File Permissions

```bash
# Base permissions: directories 755, files 644
find /var/www/sds-system -type d -exec chmod 755 {} \;
find /var/www/sds-system -type f -exec chmod 644 {} \;

# Writable directories for uploads, generated PDFs, and storage
chmod -R 775 /var/www/sds-system/public/uploads
chmod -R 775 /var/www/sds-system/public/generated-pdfs
chmod -R 775 /var/www/sds-system/storage/logs
chmod -R 775 /var/www/sds-system/storage/cache
chmod -R 775 /var/www/sds-system/storage/temp
chmod -R 775 /var/www/sds-system/storage/data

# Protect configuration file
chmod 640 /var/www/sds-system/config/config.php
chown www-data:www-data /var/www/sds-system/config/config.php

# Make cron scripts executable
chmod 750 /var/www/sds-system/cron/*.php
```

### Verify Permissions

```bash
# Check that Apache can write to the required directories
sudo -u www-data touch /var/www/sds-system/public/uploads/.perm-test && \
  echo "uploads: OK" && rm /var/www/sds-system/public/uploads/.perm-test

sudo -u www-data touch /var/www/sds-system/public/generated-pdfs/.perm-test && \
  echo "generated-pdfs: OK" && rm /var/www/sds-system/public/generated-pdfs/.perm-test

sudo -u www-data touch /var/www/sds-system/storage/logs/.perm-test && \
  echo "storage/logs: OK" && rm /var/www/sds-system/storage/logs/.perm-test

sudo -u www-data touch /var/www/sds-system/storage/cache/.perm-test && \
  echo "storage/cache: OK" && rm /var/www/sds-system/storage/cache/.perm-test

sudo -u www-data touch /var/www/sds-system/storage/temp/.perm-test && \
  echo "storage/temp: OK" && rm /var/www/sds-system/storage/temp/.perm-test
```

All five checks should print "OK".

---

## 8. Cron Job Configuration

The SDS System includes three cron scripts for automated maintenance:

| Script                | Purpose                                             | Recommended Schedule        |
|-----------------------|-----------------------------------------------------|-----------------------------|
| `refresh-federal.php` | Refreshes PubChem and NIOSH hazard data for all CAS numbers | Weekly, Sundays at 2:00 AM  |
| `refresh-sara.php`    | Imports/updates SARA 313 TRI chemical list from CSV | Weekly, Sundays at 3:00 AM  |
| `housekeeping.php`    | Purges old audit logs, refresh logs, and temp files  | Daily at 4:00 AM            |

### Install the Crontab

Edit the `www-data` user's crontab:

```bash
crontab -u www-data -e
```

Add the following entries:

```cron
# SDS System - Federal hazard data refresh (weekly, Sunday 2:00 AM)
0 2 * * 0 /usr/bin/php /var/www/sds-system/cron/refresh-federal.php >> /var/www/sds-system/storage/logs/cron-federal.log 2>&1

# SDS System - SARA 313 list refresh (weekly, Sunday 3:00 AM)
0 3 * * 0 /usr/bin/php /var/www/sds-system/cron/refresh-sara.php >> /var/www/sds-system/storage/logs/cron-sara.log 2>&1

# SDS System - Housekeeping (daily, 4:00 AM)
0 4 * * * /usr/bin/php /var/www/sds-system/cron/housekeeping.php >> /var/www/sds-system/storage/logs/cron-housekeeping.log 2>&1
```

### Verify Crontab Installation

```bash
crontab -u www-data -l
```

### Test Each Cron Script Manually

Run each script once to confirm it works:

```bash
sudo -u www-data /usr/bin/php /var/www/sds-system/cron/refresh-federal.php
sudo -u www-data /usr/bin/php /var/www/sds-system/cron/housekeeping.php
```

> **Note for `refresh-sara.php`:** This script requires the EPA TRI chemical list CSV to be placed at `/var/www/sds-system/storage/data/sara313.csv` before running. Download it from https://www.epa.gov/toxics-release-inventory-tri-program/tri-listed-chemicals.

### Log Rotation (Optional but Recommended)

Create a logrotate configuration:

```bash
nano /etc/logrotate.d/sds-system
```

```
/var/www/sds-system/storage/logs/cron-*.log {
    weekly
    rotate 12
    compress
    delaycompress
    missingok
    notifempty
    create 644 www-data www-data
}
```

---

## 9. SSL/HTTPS Setup with Let's Encrypt

### Install Certbot

```bash
apt install -y certbot python3-certbot-apache
```

### Obtain and Install the Certificate

Ensure your domain's DNS A record points to this server's public IP before running certbot:

```bash
certbot --apache -d sds.yourcompany.com
```

Certbot will:
1. Verify domain ownership via HTTP challenge
2. Obtain the certificate from Let's Encrypt
3. Automatically configure the Apache SSL vhost
4. Set up HTTP-to-HTTPS redirect

### Verify SSL Configuration

After certbot finishes, verify the SSL vhost was created:

```bash
ls /etc/apache2/sites-enabled/sds-system-le-ssl.conf
apache2ctl configtest
```

Test the redirect:

```bash
curl -I http://sds.yourcompany.com
```

You should see a `301 Moved Permanently` with `Location: https://sds.yourcompany.com/`.

### Automatic Certificate Renewal

Certbot installs a systemd timer for auto-renewal. Verify it is active:

```bash
systemctl status certbot.timer
```

Test the renewal process (dry run):

```bash
certbot renew --dry-run
```

### Force HTTPS in Apache (Manual Fallback)

If certbot did not create the redirect automatically, add this to your port-80 vhost in `/etc/apache2/sites-available/sds-system.conf`:

```apache
<VirtualHost *:80>
    ServerName sds.yourcompany.com
    RewriteEngine On
    RewriteCond %{HTTPS} off
    RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
</VirtualHost>
```

Then reload Apache:

```bash
systemctl reload apache2
```

---

## 10. Verification Steps

Perform these checks to confirm the deployment is fully operational.

### 10.1. Apache and PHP

```bash
# Confirm Apache is running
systemctl status apache2

# Confirm PHP is working through Apache
echo "<?php phpinfo(); ?>" > /var/www/sds-system/public/phpinfo.php
curl -s http://localhost/phpinfo.php | grep "PHP Version"
rm /var/www/sds-system/public/phpinfo.php   # Remove immediately after testing
```

### 10.2. Application Login

Open a browser and navigate to `https://sds.yourcompany.com` (or `http://<container-ip>` if testing locally before DNS/SSL setup).

1. You should see the SDS System login page
2. Log in with: **admin** / **SDS-Admin-2024!**
3. You should be redirected to the dashboard

### 10.3. SDS Lookup Test

1. Navigate to **Raw Materials** in the application menu
2. You should see the five seeded raw materials (RM-001 through RM-005)
3. Click on **RM-001** (Laromer DPGDA)
4. Verify constituent data is displayed (CAS 57472-68-1 -- Dipropylene Glycol Diacrylate)

### 10.4. PDF Generation (One-Click Download)

This is the critical test to verify TCPDF and the full pipeline:

1. Navigate to **Finished Goods**
2. Click on **UV-YEL-100** (UV Offset Yellow Process Ink)
3. Click the **Generate SDS PDF** button (or equivalent one-click download action)
4. A PDF should download to your browser
5. Open the PDF and verify:
   - All 16 GHS sections are present
   - Company information matches your `config.php` settings
   - Chemical constituents and CAS numbers are listed correctly
   - The PDF renders without errors or blank pages

### 10.5. File System Write Test

Verify the application can write to all required directories:

```bash
# Check that generated PDFs were actually written
ls -la /var/www/sds-system/public/generated-pdfs/

# Check application logs are being written
ls -la /var/www/sds-system/storage/logs/
```

### 10.6. Database Connectivity

```bash
# Quick check from the command line
mysql -u sds_user -p'sds_password' sds_system -e "
  SELECT 'users' AS tbl, COUNT(*) AS cnt FROM users
  UNION ALL
  SELECT 'raw_materials', COUNT(*) FROM raw_materials
  UNION ALL
  SELECT 'finished_goods', COUNT(*) FROM finished_goods
  UNION ALL
  SELECT 'formulas', COUNT(*) FROM formulas
  UNION ALL
  SELECT 'sara313_list', COUNT(*) FROM sara313_list;
"
```

Expected counts: 3 users, 5 raw materials, 2 finished goods, 2 formulas, 9 SARA 313 entries.

### 10.7. Outbound API Connectivity

Verify the server can reach the federal data APIs:

```bash
curl -s -o /dev/null -w "%{http_code}" "https://pubchem.ncbi.nlm.nih.gov/rest/pug/compound/name/water/JSON"
# Should return 200

curl -s -o /dev/null -w "%{http_code}" "https://www.cdc.gov/niosh/npg/"
# Should return 200
```

---

## 11. Troubleshooting Common Issues

### "500 Internal Server Error" on Any Page

```bash
# Check the Apache error log
tail -50 /var/log/apache2/sds-system-error.log

# Check the application log
tail -50 /var/www/sds-system/storage/logs/*.log
```

Common causes:
- **Missing PHP extensions:** Run `php -m` and verify all required extensions are loaded
- **Composer autoloader not generated:** Run `composer dump-autoload --optimize` in `/var/www/sds-system`
- **config.php missing:** Ensure `/var/www/sds-system/config/config.php` exists and is readable

### "403 Forbidden" or Blank Page

```bash
# Verify AllowOverride is set
apache2ctl -S | grep sds

# Verify mod_rewrite is enabled
apache2ctl -M | grep rewrite
```

If `rewrite_module` is not listed:

```bash
a2enmod rewrite
systemctl restart apache2
```

### Database Connection Refused

```bash
# Check MariaDB/MySQL is running
systemctl status mariadb   # or mysql

# Test connectivity
mysql -u sds_user -p'sds_password' sds_system -e "SELECT 1;"
```

Common causes:
- **Wrong credentials in config.php:** Double-check `db.host`, `db.user`, `db.password`
- **MariaDB not running:** `systemctl start mariadb`
- **Firewall blocking port 3306:** Not applicable if using `127.0.0.1` (same host)

### PDF Generation Fails

```bash
# Check that TCPDF is installed
ls /var/www/sds-system/vendor/tecnickcom/tcpdf/tcpdf.php

# Check PHP memory limit (TCPDF needs adequate memory)
php -i | grep memory_limit
```

If TCPDF is missing:

```bash
cd /var/www/sds-system
composer install --no-dev --optimize-autoloader
```

If PDFs are blank or truncated, increase PHP memory:

```bash
# In /etc/php/8.2/apache2/php.ini
memory_limit = 512M
```

Then restart Apache: `systemctl restart apache2`

### Uploads Fail with Permission Error

```bash
# Verify ownership
ls -la /var/www/sds-system/public/uploads/
ls -la /var/www/sds-system/public/generated-pdfs/

# Fix if needed
chown -R www-data:www-data /var/www/sds-system/public/uploads
chown -R www-data:www-data /var/www/sds-system/public/generated-pdfs
chmod -R 775 /var/www/sds-system/public/uploads
chmod -R 775 /var/www/sds-system/public/generated-pdfs
```

### Cron Jobs Not Running

```bash
# Verify crontab exists for www-data
crontab -u www-data -l

# Verify cron service is running
systemctl status cron

# Check for cron execution in syslog
grep CRON /var/log/syslog | tail -20

# Test manually
sudo -u www-data /usr/bin/php /var/www/sds-system/cron/housekeeping.php
```

### "Class not found" Errors

```bash
# Regenerate the Composer autoloader
cd /var/www/sds-system
composer dump-autoload --optimize
```

### Session or Login Issues

```bash
# Check PHP session directory is writable
ls -la /var/lib/php/sessions/

# Verify session configuration
php -i | grep session.save_path
```

### Large File Upload Fails

Check both PHP and Apache limits:

```bash
# PHP limits (in /etc/php/8.2/apache2/php.ini)
php -i | grep -E 'upload_max_filesize|post_max_size'

# Apache limits (if using mod_reqtimeout)
grep -r "LimitRequestBody" /etc/apache2/
```

Ensure `upload_max_filesize` and `post_max_size` accommodate your largest supplier SDS PDFs (default max in the app config is 20 MB).

---

## 12. Backup and Restore

### Database Backup with mysqldump

#### Full Backup

```bash
# Create backup directory
mkdir -p /var/backups/sds-system

# Dump the entire database
mysqldump -u sds_user -p'sds_password' \
  --single-transaction \
  --routines \
  --triggers \
  --add-drop-table \
  sds_system > /var/backups/sds-system/sds_system_$(date +%Y%m%d_%H%M%S).sql
```

#### Automated Daily Database Backup

Create a backup script:

```bash
nano /usr/local/bin/sds-backup.sh
```

```bash
#!/bin/bash
# SDS System - Daily Backup Script

BACKUP_DIR="/var/backups/sds-system"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
RETENTION_DAYS=30

# Database backup
mysqldump -u sds_user -p'sds_password' \
  --single-transaction \
  --routines \
  --triggers \
  sds_system | gzip > "${BACKUP_DIR}/db_${TIMESTAMP}.sql.gz"

# File backup (uploads and generated PDFs)
tar czf "${BACKUP_DIR}/files_${TIMESTAMP}.tar.gz" \
  /var/www/sds-system/public/uploads \
  /var/www/sds-system/public/generated-pdfs \
  /var/www/sds-system/config/config.php \
  /var/www/sds-system/storage/data

# Purge old backups
find "${BACKUP_DIR}" -name "db_*.sql.gz" -mtime +${RETENTION_DAYS} -delete
find "${BACKUP_DIR}" -name "files_*.tar.gz" -mtime +${RETENTION_DAYS} -delete

echo "[$(date)] Backup completed: db_${TIMESTAMP}.sql.gz, files_${TIMESTAMP}.tar.gz"
```

```bash
chmod 750 /usr/local/bin/sds-backup.sh
```

Add to root's crontab:

```bash
crontab -e
```

```cron
# SDS System daily backup at 1:00 AM
0 1 * * * /usr/local/bin/sds-backup.sh >> /var/log/sds-backup.log 2>&1
```

### Restore from Backup

#### Database Restore

```bash
# Decompress if gzipped
gunzip /var/backups/sds-system/db_20260219_010000.sql.gz

# Restore
mysql -u sds_user -p'sds_password' sds_system < /var/backups/sds-system/db_20260219_010000.sql
```

#### File Restore

```bash
# Restore uploaded files and generated PDFs
tar xzf /var/backups/sds-system/files_20260219_010000.tar.gz -C /

# Fix permissions after restore
chown -R www-data:www-data /var/www/sds-system/public/uploads
chown -R www-data:www-data /var/www/sds-system/public/generated-pdfs
chmod -R 775 /var/www/sds-system/public/uploads
chmod -R 775 /var/www/sds-system/public/generated-pdfs
```

### Proxmox Snapshots

Proxmox snapshots provide instant, full-state backups of the entire container or VM.

#### Creating a Snapshot (from the Proxmox host)

```bash
# For LXC containers
pct snapshot 200 pre-upgrade --description "Before SDS System v1.1 upgrade"

# For VMs (QEMU)
qm snapshot 200 pre-upgrade --description "Before SDS System v1.1 upgrade"
```

Or use the Proxmox web UI: **Container/VM > Snapshots > Take Snapshot**.

#### Restoring from a Snapshot

```bash
# Stop the container first
pct stop 200

# Rollback to snapshot
pct rollback 200 pre-upgrade

# Start the container
pct start 200
```

#### Snapshot Best Practices

- **Always snapshot before upgrades** (application updates, OS updates, PHP version changes)
- **Do not rely solely on snapshots** -- they are tied to the storage and are not offsite backups
- **Delete old snapshots** to reclaim disk space: `pct delsnapshot 200 old-snapshot-name`
- **Combine with mysqldump backups** for maximum recoverability -- snapshots give you fast rollback while SQL dumps give you portable, granular data recovery

#### Offsite Backup Recommendation

For disaster recovery, copy the database and file backups to a remote location:

```bash
# Example: rsync to a backup server
rsync -avz /var/backups/sds-system/ backup-user@backup-server:/backups/sds-system/

# Example: copy to an NFS mount
cp /var/backups/sds-system/db_*.sql.gz /mnt/nfs-backup/sds-system/
```

---

## 13. Updating the Application

### Pre-Update Checklist

1. **Back up the database** (see section 12)
2. **Back up uploaded files** (see section 12)
3. **Take a Proxmox snapshot** (see section 12)
4. **Notify users** that the system will be briefly unavailable

### Update Procedure

#### Step 1: Enable Maintenance Mode (Optional)

Create a simple maintenance page:

```bash
echo '<!DOCTYPE html><html><body><h1>SDS System Maintenance</h1><p>The system is being updated. Please try again shortly.</p></body></html>' \
  > /var/www/sds-system/public/maintenance.html
```

Add a temporary rewrite rule at the top of `/var/www/sds-system/public/.htaccess`:

```apache
# Maintenance mode - remove after update
RewriteCond %{REMOTE_ADDR} !^YOUR\.ADMIN\.IP$
RewriteCond %{REQUEST_URI} !^/maintenance\.html$
RewriteRule ^(.*)$ /maintenance.html [R=503,L]
```

#### Step 2: Pull the Latest Code

```bash
cd /var/www/sds-system

# If using Git
git fetch origin
git stash              # Stash any local changes (e.g., config.php if tracked)
git pull origin main
git stash pop          # Reapply local changes
```

Or if deploying from an archive:

```bash
# Back up current config
cp config/config.php /tmp/sds-config-backup.php

# Extract new code (excluding config and uploads)
tar xzf /tmp/sds-system-v1.1.tar.gz --exclude='config/config.php' \
  --exclude='public/uploads' --exclude='public/generated-pdfs' \
  --exclude='storage' -C /var/www/sds-system

# Restore config if overwritten
cp /tmp/sds-config-backup.php config/config.php
```

#### Step 3: Update Dependencies

```bash
cd /var/www/sds-system
composer install --no-dev --optimize-autoloader
```

#### Step 4: Run Pending Migrations

```bash
php migrations/migrate.php
```

The migration runner will skip already-applied migrations and only apply new ones.

#### Step 5: Fix Permissions

```bash
chown -R www-data:www-data /var/www/sds-system
find /var/www/sds-system -type d -exec chmod 755 {} \;
find /var/www/sds-system -type f -exec chmod 644 {} \;
chmod -R 775 /var/www/sds-system/public/uploads
chmod -R 775 /var/www/sds-system/public/generated-pdfs
chmod -R 775 /var/www/sds-system/storage
chmod 640 /var/www/sds-system/config/config.php
chmod 750 /var/www/sds-system/cron/*.php
```

#### Step 6: Clear Cache and Restart

```bash
# Clear application cache
rm -rf /var/www/sds-system/storage/cache/*

# Restart Apache to pick up any PHP changes
systemctl restart apache2
```

#### Step 7: Disable Maintenance Mode

Remove the maintenance rewrite rule from `.htaccess` and delete the maintenance page:

```bash
rm /var/www/sds-system/public/maintenance.html
```

#### Step 8: Verify

Follow the [Verification Steps](#10-verification-steps) to confirm everything is working after the update. Pay special attention to:

- Login still works
- SDS PDF generation still works (TCPDF version compatibility)
- Cron scripts still execute without errors
- New features or fixes from the update are functional

### Rolling Back a Failed Update

If the update causes problems:

**Option A: Proxmox snapshot rollback (fastest)**

```bash
# On the Proxmox host
pct stop 200
pct rollback 200 pre-upgrade
pct start 200
```

**Option B: Manual rollback**

```bash
# Restore database
mysql -u sds_user -p'sds_password' sds_system < /var/backups/sds-system/db_YYYYMMDD_HHMMSS.sql

# Restore files
cd /var/www/sds-system
git checkout <previous-tag-or-commit>
composer install --no-dev --optimize-autoloader

# Fix permissions
chown -R www-data:www-data /var/www/sds-system
systemctl restart apache2
```
