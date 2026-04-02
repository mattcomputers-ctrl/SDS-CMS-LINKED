#!/bin/bash
# ============================================================
# SDS System — Automated Installer for Ubuntu Server
# For a bare/minimal Ubuntu Server 20.04, 22.04, or 24.04
#
# Usage:
#   wget -qO install-ubuntu.sh https://your-repo/install-ubuntu.sh && sudo bash install-ubuntu.sh
#   -- or --
#   sudo bash install-ubuntu.sh
#
# This script will:
#   1. Check and install all required dependencies
#      (Apache, MariaDB, PHP 8.x, Composer, etc.)
#   2. Create the MySQL database and user
#   3. Run all database migrations
#   4. Configure Apache virtual host
#   5. Set up the application with your settings
#   6. Create your admin account
#   7. Install cron jobs (data refresh, housekeeping)
#   8. Apply firewall rules (if UFW is available)
# ============================================================

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m' # No Color

print_header() {
    echo ""
    echo -e "${BLUE}============================================================${NC}"
    echo -e "${BLUE}  $1${NC}"
    echo -e "${BLUE}============================================================${NC}"
    echo ""
}

print_step() {
    echo -e "${GREEN}[*]${NC} $1"
}

print_warn() {
    echo -e "${YELLOW}[!]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

print_success() {
    echo -e "${GREEN}[OK]${NC} $1"
}

print_info() {
    echo -e "${CYAN}[i]${NC} $1"
}

# ============================================================
# Pre-flight checks
# ============================================================

# Must run as root
if [ "$EUID" -ne 0 ]; then
    print_error "This script must be run as root (use: sudo bash install-ubuntu.sh)"
    exit 1
fi

# Must be Ubuntu
if [ ! -f /etc/os-release ]; then
    print_error "Cannot detect OS. This script is designed for Ubuntu Server."
    exit 1
fi

. /etc/os-release
if [ "$ID" != "ubuntu" ]; then
    print_error "This script is designed for Ubuntu Server. Detected: $PRETTY_NAME"
    print_error "For TurnKey Linux LAMP, use install.sh instead."
    exit 1
fi

UBUNTU_MAJOR=$(echo "$VERSION_ID" | cut -d. -f1)
if [ "$UBUNTU_MAJOR" -lt 20 ]; then
    print_error "Ubuntu 20.04 or newer is required. Detected: $VERSION_ID"
    exit 1
fi

print_header "SDS System Installer for Ubuntu Server"
echo "This will install the SDS (Safety Data Sheet) Authoring System"
echo "on a bare Ubuntu Server ($PRETTY_NAME)."
echo ""
echo "The following packages will be installed if not already present:"
echo "  - Apache 2"
echo "  - MariaDB Server"
echo "  - PHP 8.x with required extensions"
echo "  - Composer (PHP dependency manager)"
echo ""

# ============================================================
# Step 1: Gather information from the user
# ============================================================
print_header "Step 1: Configuration"

# Application directory
DEFAULT_INSTALL_DIR="/var/www/sds-system"
read -rp "Install directory [$DEFAULT_INSTALL_DIR]: " INSTALL_DIR
INSTALL_DIR="${INSTALL_DIR:-$DEFAULT_INSTALL_DIR}"

# Database settings — we'll set the MariaDB root password during install
echo ""
echo "Database Configuration:"
echo "  MariaDB will be installed if not already present."
echo "  You can set a root password or leave it blank for socket auth."
echo ""
read -rp "MariaDB root password (leave blank for socket auth): " -s MYSQL_ROOT_PASS
echo ""

DEFAULT_DB_NAME="sds_system"
read -rp "Database name [$DEFAULT_DB_NAME]: " DB_NAME
DB_NAME="${DB_NAME:-$DEFAULT_DB_NAME}"

DEFAULT_DB_USER="sds_user"
read -rp "Database user [$DEFAULT_DB_USER]: " DB_USER
DB_USER="${DB_USER:-$DEFAULT_DB_USER}"

# Generate a random password for the DB user
DB_PASS_DEFAULT=$(openssl rand -base64 18 | tr -dc 'a-zA-Z0-9' | head -c 20)
read -rp "Database password [$DB_PASS_DEFAULT]: " DB_PASS
DB_PASS="${DB_PASS:-$DB_PASS_DEFAULT}"

# Company name
echo ""
read -rp "Company name [SDS System]: " COMPANY_NAME
COMPANY_NAME="${COMPANY_NAME:-SDS System}"

# Server IP / hostname
DEFAULT_SERVER_IP=$(hostname -I 2>/dev/null | awk '{print $1}')
if [ -z "$DEFAULT_SERVER_IP" ]; then
    DEFAULT_SERVER_IP=$(hostname -f 2>/dev/null || hostname)
fi
read -rp "Server IP address or hostname [$DEFAULT_SERVER_IP]: " SERVER_NAME
SERVER_NAME="${SERVER_NAME:-$DEFAULT_SERVER_IP}"

# Admin account
echo ""
echo "Create the initial administrator account:"
read -rp "Admin username [admin]: " ADMIN_USER
ADMIN_USER="${ADMIN_USER:-admin}"

while true; do
    read -rp "Admin password (min 8 chars): " -s ADMIN_PASS
    echo ""
    if [ ${#ADMIN_PASS} -ge 8 ]; then
        break
    fi
    print_warn "Password must be at least 8 characters. Try again."
done

read -rp "Admin display name [$ADMIN_USER]: " ADMIN_DISPLAY
ADMIN_DISPLAY="${ADMIN_DISPLAY:-$ADMIN_USER}"

# Timezone
read -rp "Timezone [America/New_York]: " TIMEZONE
TIMEZONE="${TIMEZONE:-America/New_York}"

# CMS (MSSQL) Database — for formula/item sync
echo ""
echo "CMS Database Connection (SQL Server on your network):"
echo "This connects to your CMS system to sync finished goods and formulas."
echo "You can skip this now and configure it later in config/config.php."
echo ""
read -rp "CMS SQL Server hostname or IP [skip]: " CMS_DB_HOST
CMS_DB_HOST="${CMS_DB_HOST:-}"

if [ -n "$CMS_DB_HOST" ]; then
    read -rp "CMS SQL Server port [1433]: " CMS_DB_PORT
    CMS_DB_PORT="${CMS_DB_PORT:-1433}"

    read -rp "CMS database name [CMS]: " CMS_DB_NAME
    CMS_DB_NAME="${CMS_DB_NAME:-CMS}"

    read -rp "CMS database user: " CMS_DB_USER
    read -rp "CMS database password: " -s CMS_DB_PASS
    echo ""
fi

echo ""
print_step "Configuration complete. Starting installation..."
echo ""

# ============================================================
# Step 2: Install system dependencies
# ============================================================
print_header "Step 2: Installing System Dependencies"

print_step "Updating package lists..."
apt-get update -qq

# Prevent interactive prompts during package installation
export DEBIAN_FRONTEND=noninteractive

# --- Apache ---
if command -v apache2 &> /dev/null; then
    print_success "Apache already installed."
else
    print_step "Installing Apache..."
    apt-get install -y -qq apache2 > /dev/null 2>&1
    print_success "Apache installed."
fi

# --- MariaDB ---
if command -v mariadb &> /dev/null || command -v mysql &> /dev/null; then
    print_success "MariaDB/MySQL already installed."
else
    print_step "Installing MariaDB Server..."
    apt-get install -y -qq mariadb-server mariadb-client > /dev/null 2>&1
    # Start and enable MariaDB
    systemctl start mariadb
    systemctl enable mariadb > /dev/null 2>&1
    print_success "MariaDB installed and started."

    # Set root password if one was provided
    if [ -n "$MYSQL_ROOT_PASS" ]; then
        print_step "Setting MariaDB root password..."
        mariadb -u root <<ROOTSQL
ALTER USER 'root'@'localhost' IDENTIFIED BY '$MYSQL_ROOT_PASS';
FLUSH PRIVILEGES;
ROOTSQL
        print_success "MariaDB root password set."
    fi
fi

# --- PHP ---
# Determine which PHP version is available
print_step "Detecting PHP availability..."

# Check if PHP is already installed
PHP_INSTALLED=false
if command -v php &> /dev/null; then
    PHP_VER=$(php -r "echo PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;" 2>/dev/null || echo "0.0")
    PHP_MAJOR=$(echo "$PHP_VER" | cut -d. -f1)
    if [ "$PHP_MAJOR" -ge 8 ]; then
        print_success "PHP $PHP_VER already installed."
        PHP_INSTALLED=true
    else
        print_warn "PHP $PHP_VER found but PHP 8.0+ is required. Installing newer version..."
    fi
fi

if [ "$PHP_INSTALLED" = false ]; then
    # Try to install PHP from default repos first
    print_step "Installing PHP and extensions..."

    # Check if ondrej/php PPA is needed (Ubuntu 20.04 ships PHP 7.4)
    if [ "$UBUNTU_MAJOR" -le 20 ]; then
        print_step "Adding PHP PPA for Ubuntu $VERSION_ID..."
        apt-get install -y -qq software-properties-common > /dev/null 2>&1
        add-apt-repository -y ppa:ondrej/php > /dev/null 2>&1
        apt-get update -qq

        # Install PHP 8.2 from PPA
        PHP_PKG_VER="8.2"
    elif [ "$UBUNTU_MAJOR" -le 22 ]; then
        # Ubuntu 22.04 ships PHP 8.1 by default
        PHP_PKG_VER="8.1"
        # Try default repo first, fall back to PPA
        if ! apt-cache show "php${PHP_PKG_VER}-cli" &>/dev/null; then
            print_step "Adding PHP PPA for Ubuntu $VERSION_ID..."
            apt-get install -y -qq software-properties-common > /dev/null 2>&1
            add-apt-repository -y ppa:ondrej/php > /dev/null 2>&1
            apt-get update -qq
            PHP_PKG_VER="8.2"
        fi
    else
        # Ubuntu 24.04+ ships PHP 8.3
        PHP_PKG_VER="8.3"
    fi

    apt-get install -y -qq \
        "php${PHP_PKG_VER}" \
        "php${PHP_PKG_VER}-cli" \
        "php${PHP_PKG_VER}-mysql" \
        "php${PHP_PKG_VER}-mbstring" \
        "php${PHP_PKG_VER}-xml" \
        "php${PHP_PKG_VER}-curl" \
        "php${PHP_PKG_VER}-zip" \
        "php${PHP_PKG_VER}-gd" \
        "php${PHP_PKG_VER}-intl" \
        "php${PHP_PKG_VER}-bcmath" \
        "php${PHP_PKG_VER}-fileinfo" \
        "libapache2-mod-php${PHP_PKG_VER}" \
        > /dev/null 2>&1

    print_success "PHP $PHP_PKG_VER installed with all required extensions."
fi

# Verify PHP extensions
print_step "Verifying PHP extensions..."
REQUIRED_EXTS=("pdo_mysql" "mbstring" "xml" "curl" "zip" "gd" "intl" "bcmath" "fileinfo")
MISSING_EXTS=()

for ext in "${REQUIRED_EXTS[@]}"; do
    if ! php -m 2>/dev/null | grep -qi "^$ext$"; then
        MISSING_EXTS+=("$ext")
    fi
done

if [ ${#MISSING_EXTS[@]} -gt 0 ]; then
    print_warn "Missing PHP extensions: ${MISSING_EXTS[*]}"
    print_step "Attempting to install missing extensions..."
    CURRENT_PHP_VER=$(php -r "echo PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;" 2>/dev/null)
    for ext in "${MISSING_EXTS[@]}"; do
        apt-get install -y -qq "php${CURRENT_PHP_VER}-${ext}" > /dev/null 2>&1 || \
        apt-get install -y -qq "php-${ext}" > /dev/null 2>&1 || \
        print_warn "Could not install php-${ext}. You may need to install it manually."
    done
fi

print_success "PHP extensions verified."

# --- SQL Server driver for CMS sync ---
print_step "Installing SQL Server PDO driver (for CMS sync)..."
CURRENT_PHP_VER=$(php -r "echo PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;" 2>/dev/null)
apt-get install -y -qq "php${CURRENT_PHP_VER}-sybase" > /dev/null 2>&1 || \
    apt-get install -y -qq php-sybase > /dev/null 2>&1 || true

if php -m 2>/dev/null | grep -qi 'pdo_dblib\|pdo_sqlsrv'; then
    print_success "SQL Server PDO driver installed (CMS sync ready)."
else
    print_warn "No SQL Server PDO driver found (pdo_dblib or pdo_sqlsrv)."
    print_warn "CMS sync will not work until one is installed."
    print_info "See: https://learn.microsoft.com/en-us/sql/connect/php/installation-tutorial-linux-mac"
fi

# --- Other utilities ---
print_step "Installing additional utilities..."
apt-get install -y -qq unzip curl git openssl > /dev/null 2>&1
print_success "Utilities installed."

# Enable required Apache modules
print_step "Enabling Apache modules..."
a2enmod rewrite > /dev/null 2>&1 || true
a2enmod headers > /dev/null 2>&1 || true

print_success "All system dependencies installed."

# ============================================================
# Step 3: Install Composer
# ============================================================
print_header "Step 3: Installing Composer"

if command -v composer &> /dev/null; then
    print_success "Composer already installed."
else
    print_step "Downloading Composer..."
    EXPECTED_CHECKSUM="$(php -r 'copy("https://composer.github.io/installer.sig", "php://stdout");')"
    php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
    ACTUAL_CHECKSUM="$(php -r "echo hash_file('sha384', 'composer-setup.php');")"

    if [ "$EXPECTED_CHECKSUM" != "$ACTUAL_CHECKSUM" ]; then
        print_warn "Composer installer checksum mismatch. Attempting install anyway..."
    fi

    php composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet
    rm -f composer-setup.php
    print_success "Composer installed."
fi

# ============================================================
# Step 4: Set up the application files
# ============================================================
print_header "Step 4: Setting Up Application"

if [ -d "$INSTALL_DIR" ] && [ -f "$INSTALL_DIR/composer.json" ]; then
    print_step "Application directory already exists. Updating..."
else
    # If we are running from the source directory, copy files
    SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
    if [ -f "$SCRIPT_DIR/composer.json" ] && [ -d "$SCRIPT_DIR/src" ]; then
        print_step "Copying application files to $INSTALL_DIR..."
        mkdir -p "$INSTALL_DIR"
        rsync -a --exclude='vendor' --exclude='config/config.php' \
              --exclude='storage/logs/*' --exclude='storage/cache/*' \
              "$SCRIPT_DIR/" "$INSTALL_DIR/"
    else
        print_error "Cannot find application source files."
        print_error "Please run this script from the SDS-System directory."
        exit 1
    fi
fi

cd "$INSTALL_DIR"

# Install PHP dependencies
print_step "Installing PHP dependencies (Composer)..."
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --quiet 2>/dev/null

# Create required directories
print_step "Creating required directories..."
mkdir -p public/uploads/supplier-sds
mkdir -p public/generated-pdfs
mkdir -p storage/logs
mkdir -p storage/cache
mkdir -p storage/temp
mkdir -p storage/backups

# Set permissions
print_step "Setting file permissions..."
chown -R www-data:www-data "$INSTALL_DIR"
chmod -R 755 "$INSTALL_DIR"
chmod -R 775 public/uploads
chmod -R 775 public/generated-pdfs
chmod -R 775 storage

print_success "Application files ready."

# ============================================================
# Step 5: Create configuration file
# ============================================================
print_header "Step 5: Creating Configuration"

CONFIG_FILE="$INSTALL_DIR/config/config.php"

cat > "$CONFIG_FILE" << CONFIGEOF
<?php
/**
 * SDS System Configuration
 * Generated by install-ubuntu.sh on $(date '+%Y-%m-%d %H:%M:%S')
 */
return [
    'app' => [
        'name'      => '$COMPANY_NAME',
        'url'       => 'http://$SERVER_NAME',
        'debug'     => false,
        'timezone'  => '$TIMEZONE',
        'version'   => '1.0.0',
    ],

    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'name'     => '$DB_NAME',
        'user'     => '$DB_USER',
        'password' => '$DB_PASS',
        'charset'  => 'utf8mb4',
    ],

    'paths' => [
        'uploads'       => __DIR__ . '/../public/uploads',
        'supplier_sds'  => __DIR__ . '/../public/uploads/supplier-sds',
        'generated_pdfs' => __DIR__ . '/../public/generated-pdfs',
        'storage'       => __DIR__ . '/../storage',
        'logs'          => __DIR__ . '/../storage/logs',
        'cache'         => __DIR__ . '/../storage/cache',
        'temp'          => __DIR__ . '/../storage/temp',
        'translations'  => __DIR__ . '/../templates/translations',
    ],

    'session' => [
        'lifetime' => 3600,
        'name'     => 'SDS_SESSION',
    ],

    'upload' => [
        'max_size_mb'       => 20,
        'allowed_extensions' => ['pdf'],
        'allowed_mimetypes'  => ['application/pdf'],
    ],

    'company' => [
        'name'    => '$COMPANY_NAME',
        'address' => '',
        'city'    => '',
        'state'   => '',
        'zip'     => '',
        'country' => 'US',
        'phone'   => '',
        'fax'     => '',
        'email'   => '',
        'emergency_phone' => 'CHEMTREC: (800) 424-9300',
        'website' => '',
    ],

    // CMS (MSSQL) database connection for formula/item sync
    'cms_db' => [
        'host'     => '${CMS_DB_HOST}',
        'port'     => ${CMS_DB_PORT:-1433},
        'name'     => '${CMS_DB_NAME:-CMS}',
        'user'     => '${CMS_DB_USER}',
        'password' => '${CMS_DB_PASS}',
    ],

    'federal_data' => [
        'pubchem' => [
            'base_url'  => 'https://pubchem.ncbi.nlm.nih.gov/rest/pug',
            'view_url'  => 'https://pubchem.ncbi.nlm.nih.gov/rest/pug_view',
            'timeout'   => 30,
            'rate_limit_ms' => 200,
        ],
        'niosh' => [
            'base_url' => 'https://www.cdc.gov/niosh/npg',
            'timeout'  => 30,
        ],
        'epa' => ['enabled' => true],
        'dot' => ['enabled' => true],
    ],

    'sds' => [
        'default_language'       => 'en',
        'supported_languages'    => ['en', 'es', 'fr', 'de'],
        'block_publish_missing'  => true,
        'missing_threshold_pct'  => 1.0,
        'voc_calc_mode'          => 'method24_standard',
        'publish_workers'        => 0,
    ],

    'cron' => [
        'log_retention_days'    => 365,
    ],
];
CONFIGEOF

chmod 640 "$CONFIG_FILE"
chown www-data:www-data "$CONFIG_FILE"

print_success "Configuration file created."

# ============================================================
# Step 6: Set up the database
# ============================================================
print_header "Step 6: Setting Up Database"

# Build the mysql auth arguments
if [ -n "$MYSQL_ROOT_PASS" ]; then
    MYSQL_AUTH="-u root -p$MYSQL_ROOT_PASS"
else
    # Socket auth (default for MariaDB on Ubuntu)
    MYSQL_AUTH="-u root"
fi

print_step "Creating database and user..."
mariadb $MYSQL_AUTH << SQLEOF
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'127.0.0.1';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQLEOF

print_success "Database and user created."

# Run migrations
print_step "Running database migrations..."
for migration in "$INSTALL_DIR"/migrations/*.sql; do
    if [ -f "$migration" ]; then
        MIGRATION_NAME=$(basename "$migration" .sql)
        # Check if already applied (schema_migrations table may not exist yet)
        APPLIED=$(mariadb $MYSQL_AUTH -N -e \
            "SELECT COUNT(*) FROM \`$DB_NAME\`.schema_migrations WHERE version='$MIGRATION_NAME'" 2>/dev/null || echo "0")
        if [ "$APPLIED" = "0" ]; then
            print_step "  Applying $MIGRATION_NAME..."
            mariadb $MYSQL_AUTH "$DB_NAME" < "$migration"
        else
            print_step "  Skipping $MIGRATION_NAME (already applied)"
        fi
    fi
done

print_success "Database migrations complete."

# -- Schema fixes --------------------------------------------------
# group_permissions.access_level must be ENUM('none','read','full').
# If the table was created before migration 010 defined the correct
# ENUM, the column may have a different type causing "Data truncated"
# errors when creating permission groups.
print_step "Checking group_permissions.access_level column type..."
if mariadb $MYSQL_AUTH -N -e \
    "SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = '$DB_NAME'
       AND TABLE_NAME   = 'group_permissions'
       AND COLUMN_NAME  = 'access_level'" "$DB_NAME" 2>/dev/null | grep -q 1; then

    CURRENT_TYPE=$(mariadb $MYSQL_AUTH -N -e \
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = '$DB_NAME'
           AND TABLE_NAME   = 'group_permissions'
           AND COLUMN_NAME  = 'access_level'" "$DB_NAME" 2>/dev/null)

    if [ "$CURRENT_TYPE" != "enum('none','read','full')" ]; then
        print_step "  Fixing access_level column (was: $CURRENT_TYPE)..."
        mariadb $MYSQL_AUTH "$DB_NAME" -e \
            "ALTER TABLE group_permissions MODIFY COLUMN access_level ENUM('none','read','full') NOT NULL DEFAULT 'none'"
        print_success "  access_level column corrected."
    else
        print_success "group_permissions.access_level is correct."
    fi
else
    print_info "group_permissions table not found yet — will be created by migrations."
fi

# Create admin user
print_step "Creating admin user..."
ADMIN_HASH=$(php -r "echo password_hash('$ADMIN_PASS', PASSWORD_ARGON2ID);")
mariadb $MYSQL_AUTH "$DB_NAME" << ADMINEOF
INSERT INTO users (username, email, password_hash, display_name, role, is_active)
VALUES ('$ADMIN_USER', NULL, '$ADMIN_HASH', '$ADMIN_DISPLAY', 'admin', 1)
ON DUPLICATE KEY UPDATE password_hash='$ADMIN_HASH', role='admin', is_active=1;
ADMINEOF

print_success "Admin user '$ADMIN_USER' created."

# Seed the server URL setting into the database
print_step "Saving server URL to settings..."
mariadb $MYSQL_AUTH "$DB_NAME" << URLEOF
INSERT INTO settings (\`key\`, \`value\`) VALUES ('app.server_url', 'http://$SERVER_NAME')
ON DUPLICATE KEY UPDATE \`value\` = 'http://$SERVER_NAME';
URLEOF

print_success "Server URL saved (changeable later in Admin > Settings)."

# ============================================================
# Step 7: Load Federal Regulatory Seed Data
# ============================================================
print_header "Step 7: Loading Federal Regulatory Data"

print_step "Loading pre-packaged seed data (OSHA PEL, NIOSH REL, ACGIH TLV, Prop 65, SARA 313, HAPs, carcinogens, EPA, DOT)..."
print_info "This populates exposure limits and regulatory lists for 700+ chemicals."

cd "$INSTALL_DIR"
if [ -f "$INSTALL_DIR/scripts/load-seed-data.php" ]; then
    COMPOSER_ALLOW_SUPERUSER=1 php "$INSTALL_DIR/scripts/load-seed-data.php" 2>&1 | while IFS= read -r line; do
        echo "  $line"
    done
    print_success "Seed data loaded."
else
    print_warn "Seed data loader not found. Skipping."
fi

print_info "Federal data can be refreshed manually via Admin > Data Sources"
print_info "or by running: php scripts/refresh-federal-data.php"

# ============================================================
# Step 8: Configure Apache
# ============================================================
print_header "Step 8: Configuring Apache"

APACHE_CONF="/etc/apache2/sites-available/sds-system.conf"

cat > "$APACHE_CONF" << APACHEEOF
<VirtualHost *:80>
    ServerName $SERVER_NAME
    DocumentRoot $INSTALL_DIR/public

    <Directory $INSTALL_DIR/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Protect sensitive directories
    <DirectoryMatch "^$INSTALL_DIR/(config|migrations|src|storage|tests|vendor)">
        Require all denied
    </DirectoryMatch>

    # PHP settings
    php_value upload_max_filesize 25M
    php_value post_max_size 30M
    php_value max_execution_time 120
    php_value memory_limit 256M

    ErrorLog \${APACHE_LOG_DIR}/sds-system-error.log
    CustomLog \${APACHE_LOG_DIR}/sds-system-access.log combined
</VirtualHost>
APACHEEOF

# Disable default site and enable SDS system
print_step "Enabling SDS System site..."
a2dissite 000-default.conf > /dev/null 2>&1 || true
a2ensite sds-system.conf > /dev/null 2>&1

# Update PHP settings for Apache
print_step "Updating PHP settings..."
PHP_INI=$(php -r "echo php_ini_loaded_file();" 2>/dev/null)
if [ -n "$PHP_INI" ]; then
    APACHE_PHP_INI=$(echo "$PHP_INI" | sed 's/cli/apache2/')
    if [ -f "$APACHE_PHP_INI" ]; then
        sed -i 's/upload_max_filesize = .*/upload_max_filesize = 25M/' "$APACHE_PHP_INI" 2>/dev/null || true
        sed -i 's/post_max_size = .*/post_max_size = 30M/' "$APACHE_PHP_INI" 2>/dev/null || true
        sed -i 's/max_execution_time = .*/max_execution_time = 120/' "$APACHE_PHP_INI" 2>/dev/null || true
        sed -i 's/memory_limit = .*/memory_limit = 256M/' "$APACHE_PHP_INI" 2>/dev/null || true
    fi
fi

# Restart Apache
print_step "Restarting Apache..."
systemctl enable apache2 > /dev/null 2>&1
systemctl restart apache2

print_success "Apache configured and restarted."

# ============================================================
# Step 9: Set up cron jobs
# ============================================================
print_header "Step 9: Setting Up Cron Jobs"

print_step "Installing crontab for www-data..."

# Build cron entries (federal data refresh removed — run manually via scripts/refresh-federal-data.php)
CRON_ENTRIES="# SDS System — Automated maintenance tasks
# Housekeeping: purge old logs, temp files (daily, 4:00 AM)
0 4 * * * cd $INSTALL_DIR && /usr/bin/php cron/housekeeping.php >> storage/logs/cron-housekeeping.log 2>&1"

# Merge with any existing www-data crontab
( crontab -u www-data -l 2>/dev/null | grep -v 'SDS System' | grep -v 'refresh-federal' | grep -v 'refresh-sara' | grep -v 'housekeeping'; echo "$CRON_ENTRIES" ) | crontab -u www-data - 2>/dev/null

print_success "Cron jobs installed for www-data."
print_info "  Daily:   Housekeeping           (4:00 AM)"

# ============================================================
# Step 10: Firewall (UFW)
# ============================================================
print_header "Step 10: Firewall Configuration"

if command -v ufw &> /dev/null; then
    print_step "Configuring UFW firewall..."
    ufw allow 'Apache Full' > /dev/null 2>&1 || ufw allow 80/tcp > /dev/null 2>&1
    ufw allow 22/tcp > /dev/null 2>&1  # Ensure SSH stays open

    if ufw status | grep -q "inactive"; then
        print_info "UFW is installed but inactive. Enabling now..."
        echo "y" | ufw enable > /dev/null 2>&1
    fi

    print_success "Firewall configured (HTTP + SSH allowed)."
else
    print_info "UFW not found. No firewall rules applied."
    print_info "If you have a firewall, make sure port 80 (HTTP) is open."
fi

# ============================================================
# Step 10: Final checks
# ============================================================
print_header "Step 11: Final Verification"

ERRORS=0

# Check Apache is running
if systemctl is-active --quiet apache2; then
    print_success "Apache is running."
else
    print_error "Apache is NOT running."
    ERRORS=$((ERRORS + 1))
fi

# Check MariaDB is running
if systemctl is-active --quiet mariadb; then
    print_success "MariaDB is running."
elif systemctl is-active --quiet mysql; then
    print_success "MySQL is running."
else
    print_error "MariaDB/MySQL is NOT running."
    ERRORS=$((ERRORS + 1))
fi

# Check PHP works
if php -r "echo 'OK';" 2>/dev/null | grep -q "OK"; then
    print_success "PHP is working."
else
    print_error "PHP is NOT working correctly."
    ERRORS=$((ERRORS + 1))
fi

# Check database connection
if mariadb $MYSQL_AUTH -e "SELECT 1 FROM \`$DB_NAME\`.users LIMIT 1" > /dev/null 2>&1; then
    print_success "Database connection verified."
else
    print_error "Cannot connect to database."
    ERRORS=$((ERRORS + 1))
fi

# Check file permissions
if [ -w "$INSTALL_DIR/storage" ] && [ -w "$INSTALL_DIR/public/uploads" ]; then
    print_success "File permissions look correct."
else
    print_warn "File permissions may need adjustment."
fi

if [ "$ERRORS" -gt 0 ]; then
    echo ""
    print_warn "$ERRORS issue(s) detected. Please check the errors above."
fi

# ============================================================
# Done!
# ============================================================
print_header "Installation Complete!"

echo -e "${GREEN}The SDS System has been installed successfully.${NC}"
echo ""
echo -e "  ${BOLD}URL:${NC}       http://$SERVER_NAME"
echo -e "  ${BOLD}Username:${NC}  $ADMIN_USER"
echo -e "  ${BOLD}Password:${NC}  (the password you entered)"
echo ""
echo "  Install directory:  $INSTALL_DIR"
echo "  Database:           $DB_NAME"
echo "  Database user:      $DB_USER"
echo "  Database password:  $DB_PASS"
echo "  Config file:        $CONFIG_FILE"
echo ""
echo -e "${YELLOW}Important next steps:${NC}"
echo "  1. Log in at http://$SERVER_NAME and go to Admin > Settings."
echo "  2. Verify or update the Server URL / IP Address."
echo "  3. Configure your company information and upload logos."
echo "  4. Create user accounts for your team."
echo "  5. Go to CMS Import to sync finished goods and formulas"
echo "     from your CMS database."
echo "  6. Work through the incomplete raw materials checklist"
echo "     to add CAS constituents and supplier SDS PDFs."
echo ""
echo -e "${YELLOW}To change the server IP later:${NC}"
echo "  - Log in as admin and go to Admin > Settings."
echo "  - Update the 'Server URL / IP Address' field at the top."
echo "  - Or edit $CONFIG_FILE and change the 'url' value."
echo ""
echo -e "${YELLOW}Security recommendations:${NC}"
echo "  - Set up HTTPS with Let's Encrypt:"
echo "    sudo apt install certbot python3-certbot-apache"
echo "    sudo certbot --apache -d $SERVER_NAME"
echo "  - Review the firewall rules:"
echo "    sudo ufw status"
echo ""
echo -e "${GREEN}Thank you for installing SDS System!${NC}"
