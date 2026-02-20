#!/bin/bash
# ============================================================
# SDS System — Automated Installer
# For TurnKey Linux LAMP Stack
#
# Usage:
#   wget -qO install.sh https://your-repo/install.sh && bash install.sh
#   -- or --
#   bash install.sh
#
# This script will:
#   1. Install PHP 8.x and required extensions
#   2. Install Composer
#   3. Create the MySQL database and user
#   4. Run all database migrations
#   5. Configure Apache virtual host
#   6. Set up the application with your settings
#   7. Create your admin account
# ============================================================

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
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

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    print_error "This script must be run as root (use: sudo bash install.sh)"
    exit 1
fi

print_header "SDS System Installer"
echo "This will install the SDS (Safety Data Sheet) Authoring System."
echo "It is designed for TurnKey Linux LAMP Stack."
echo ""

# ============================================================
# Step 1: Gather information from the user
# ============================================================
print_header "Step 1: Configuration"

# Application directory
DEFAULT_INSTALL_DIR="/var/www/sds-system"
read -rp "Install directory [$DEFAULT_INSTALL_DIR]: " INSTALL_DIR
INSTALL_DIR="${INSTALL_DIR:-$DEFAULT_INSTALL_DIR}"

# Database settings
read -rp "MySQL root password: " -s MYSQL_ROOT_PASS
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
read -rp "Company name [SDS System]: " COMPANY_NAME
COMPANY_NAME="${COMPANY_NAME:-SDS System}"

# Server name
DEFAULT_SERVER_NAME=$(hostname -f 2>/dev/null || hostname)
read -rp "Server hostname or IP [$DEFAULT_SERVER_NAME]: " SERVER_NAME
SERVER_NAME="${SERVER_NAME:-$DEFAULT_SERVER_NAME}"

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

echo ""
print_step "Configuration complete. Starting installation..."
echo ""

# ============================================================
# Step 2: Install system dependencies
# ============================================================
print_header "Step 2: Installing System Dependencies"

print_step "Updating package lists..."
apt-get update -qq

print_step "Installing PHP extensions and utilities..."
apt-get install -y -qq \
    php-cli php-mysql php-mbstring php-xml php-curl php-zip \
    php-gd php-intl php-fileinfo php-bcmath \
    unzip curl git > /dev/null 2>&1

# Enable required Apache modules
print_step "Enabling Apache modules..."
a2enmod rewrite > /dev/null 2>&1 || true
a2enmod headers > /dev/null 2>&1 || true

print_success "System dependencies installed."

# ============================================================
# Step 3: Install Composer
# ============================================================
print_header "Step 3: Installing Composer"

if ! command -v composer &> /dev/null; then
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
else
    print_success "Composer already installed."
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
composer install --no-dev --optimize-autoloader --quiet 2>/dev/null

# Create required directories
print_step "Creating required directories..."
mkdir -p public/uploads/supplier-sds
mkdir -p public/generated-pdfs
mkdir -p storage/logs
mkdir -p storage/cache
mkdir -p storage/temp

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
 * Generated by install.sh on $(date '+%Y-%m-%d %H:%M:%S')
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
        'supported_languages'    => ['en', 'es', 'fr'],
        'block_publish_missing'  => true,
        'missing_threshold_pct'  => 1.0,
        'voc_calc_mode'          => 'method24_standard',
    ],

    'cron' => [
        'federal_refresh_hours' => 168,
        'sara_refresh_hours'    => 168,
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

print_step "Creating database and user..."
mysql -u root -p"$MYSQL_ROOT_PASS" << SQLEOF
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
        # Check if already applied
        APPLIED=$(mysql -u root -p"$MYSQL_ROOT_PASS" -N -e \
            "SELECT COUNT(*) FROM \`$DB_NAME\`.schema_migrations WHERE version='$MIGRATION_NAME'" 2>/dev/null || echo "0")
        if [ "$APPLIED" = "0" ]; then
            print_step "  Applying $MIGRATION_NAME..."
            mysql -u root -p"$MYSQL_ROOT_PASS" "$DB_NAME" < "$migration"
        else
            print_step "  Skipping $MIGRATION_NAME (already applied)"
        fi
    fi
done

print_success "Database migrations complete."

# Create admin user
print_step "Creating admin user..."
ADMIN_HASH=$(php -r "echo password_hash('$ADMIN_PASS', PASSWORD_ARGON2ID);")
mysql -u root -p"$MYSQL_ROOT_PASS" "$DB_NAME" << ADMINEOF
INSERT INTO users (username, email, password_hash, display_name, role, is_active)
VALUES ('$ADMIN_USER', NULL, '$ADMIN_HASH', '$ADMIN_DISPLAY', 'admin', 1)
ON DUPLICATE KEY UPDATE password_hash='$ADMIN_HASH', role='admin', is_active=1;
ADMINEOF

print_success "Admin user '$ADMIN_USER' created."

# Seed the server URL setting into the database
print_step "Saving server URL to settings..."
mysql -u root -p"$MYSQL_ROOT_PASS" "$DB_NAME" << URLEOF
INSERT INTO settings (\`key\`, \`value\`) VALUES ('app.server_url', 'http://$SERVER_NAME')
ON DUPLICATE KEY UPDATE \`value\` = 'http://$SERVER_NAME';
URLEOF
print_success "Server URL saved (changeable later in Admin > Settings)."

# ============================================================
# Step 7: Load Federal Regulatory Seed Data
# ============================================================
print_header "Step 7: Loading Federal Regulatory Data"

print_step "Loading pre-packaged seed data (Prop 65, IARC/NTP/OSHA, SARA 313, NIOSH, EPA, DOT)..."
print_info "This provides a comprehensive regulatory baseline for all known chemicals."

cd "$INSTALL_DIR"
if [ -f "$INSTALL_DIR/scripts/load-seed-data.php" ]; then
    COMPOSER_ALLOW_SUPERUSER=1 php "$INSTALL_DIR/scripts/load-seed-data.php" 2>&1 | while IFS= read -r line; do
        echo "  $line"
    done
    print_success "Seed data loaded."
else
    print_warn "Seed data loader not found. Skipping."
fi

print_info "Live federal data can be refreshed later via Admin > Data Sources"
print_info "or by running: php cron/refresh-federal.php"

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

# Update PHP settings
print_step "Updating PHP settings..."
PHP_INI=$(php -r "echo php_ini_loaded_file();" 2>/dev/null)
if [ -n "$PHP_INI" ]; then
    # Also update the Apache PHP ini
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
systemctl restart apache2

print_success "Apache configured and restarted."

# ============================================================
# Step 9: Final checks
# ============================================================
print_header "Installation Complete!"

echo -e "${GREEN}The SDS System has been installed successfully.${NC}"
echo ""
echo "  URL:       http://$SERVER_NAME"
echo "  Username:  $ADMIN_USER"
echo "  Password:  (the password you entered)"
echo ""
echo "  Install directory:  $INSTALL_DIR"
echo "  Database:           $DB_NAME"
echo "  Database user:      $DB_USER"
echo "  Database password:  $DB_PASS"
echo ""
echo -e "${YELLOW}Important next steps:${NC}"
echo "  1. Log in at http://$SERVER_NAME and go to Admin > Settings"
echo "     to configure your company information."
echo "  2. Upload your company logo and login page logo."
echo "  3. Create user accounts for your team."
echo "  4. Start adding raw materials with CAS constituents."
echo "  5. Upload supplier SDS PDFs for each raw material."
echo ""
echo -e "${YELLOW}Security notes:${NC}"
echo "  - The database password has been saved to: $CONFIG_FILE"
echo "  - Consider setting up HTTPS with Let's Encrypt:"
echo "    apt-get install certbot python3-certbot-apache"
echo "    certbot --apache -d $SERVER_NAME"
echo ""
echo -e "${GREEN}Thank you for installing SDS System!${NC}"
