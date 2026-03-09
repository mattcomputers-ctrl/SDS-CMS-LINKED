#!/bin/bash
# ============================================================
# SDS System — Update Script
# Updates an existing installation to the latest version.
#
# Usage:
#   sudo bash update.sh
#
# This script will:
#   1. Detect the current installation directory
#   2. Create a pre-update backup
#   3. Copy updated application files (preserving config, data, uploads)
#   4. Patch configuration for new features (e.g. add new languages)
#   5. Install/update PHP dependencies (Composer)
#   6. Create any missing directories
#   7. Run any new database migrations
#   8. Refresh seed data (upsert — no data loss)
#   9. Fix file permissions
#  10. Clear application cache
#  11. Restart Apache
#  12. Post-update verification
#
# Safe to run multiple times. Will NOT overwrite:
#   - config/config.php (your database & company settings)
#   - public/uploads/ (supplier SDS PDFs)
#   - public/generated-pdfs/ (generated SDS documents)
#   - storage/backups/ (your backup archives)
#   - storage/logs/ (application logs)
#   - Database content (users, formulas, raw materials, etc.)
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
    print_error "This script must be run as root (use: sudo bash update.sh)"
    exit 1
fi

# Determine the source directory (where this script lives)
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

if [ ! -f "$SCRIPT_DIR/composer.json" ] || [ ! -d "$SCRIPT_DIR/src" ]; then
    print_error "Cannot find application source files."
    print_error "Please run this script from the SDS-System source directory."
    exit 1
fi

print_header "SDS System — Updater"
echo "This will update an existing SDS System installation."
echo "Your configuration, data, uploads, and users will be preserved."
echo ""

# ============================================================
# Step 1: Detect installation
# ============================================================
print_header "Step 1: Detecting Installation"

DEFAULT_INSTALL_DIR="/var/www/sds-system"
read -rp "Installation directory [$DEFAULT_INSTALL_DIR]: " INSTALL_DIR
INSTALL_DIR="${INSTALL_DIR:-$DEFAULT_INSTALL_DIR}"

# Verify it's a valid SDS System installation
if [ ! -f "$INSTALL_DIR/composer.json" ]; then
    print_error "No SDS System installation found at $INSTALL_DIR"
    print_error "composer.json is missing. Is this the correct directory?"
    exit 1
fi

if [ ! -f "$INSTALL_DIR/config/config.php" ]; then
    print_error "No config/config.php found at $INSTALL_DIR"
    print_error "This doesn't appear to be a configured installation."
    exit 1
fi

print_success "Found SDS System installation at $INSTALL_DIR"

# Read database credentials from the existing config
print_step "Reading database configuration..."
DB_NAME=$(php -r "\$c = require '$INSTALL_DIR/config/config.php'; echo \$c['db']['name'];" 2>/dev/null)
DB_USER=$(php -r "\$c = require '$INSTALL_DIR/config/config.php'; echo \$c['db']['user'];" 2>/dev/null)
DB_PASS=$(php -r "\$c = require '$INSTALL_DIR/config/config.php'; echo \$c['db']['password'];" 2>/dev/null)
DB_HOST=$(php -r "\$c = require '$INSTALL_DIR/config/config.php'; echo \$c['db']['host'];" 2>/dev/null)
DB_PORT=$(php -r "\$c = require '$INSTALL_DIR/config/config.php'; echo \$c['db']['port'] ?? 3306;" 2>/dev/null)

if [ -z "$DB_NAME" ] || [ -z "$DB_USER" ]; then
    print_error "Could not read database settings from config/config.php"
    exit 1
fi

print_success "Database: $DB_NAME (user: $DB_USER)"

# Determine mysql/mariadb auth method
# Try app credentials first (they have full DB privileges from the installer)
MYSQL_CMD="mysql"
if command -v mariadb &> /dev/null; then
    MYSQL_CMD="mariadb"
fi

# Test connection with app credentials
if $MYSQL_CMD --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" --password="$DB_PASS" -e "SELECT 1" "$DB_NAME" > /dev/null 2>&1; then
    MYSQL_AUTH="--host=$DB_HOST --port=$DB_PORT --user=$DB_USER --password=$DB_PASS"
    print_success "Database connection verified (app credentials)."
else
    # Fall back to asking for root credentials
    print_warn "Cannot connect with app credentials. Trying root access..."
    read -rp "MySQL/MariaDB root password (blank for socket auth): " -s MYSQL_ROOT_PASS
    echo ""
    if [ -n "$MYSQL_ROOT_PASS" ]; then
        MYSQL_AUTH="-u root -p$MYSQL_ROOT_PASS"
    else
        MYSQL_AUTH="-u root"
    fi

    if ! $MYSQL_CMD $MYSQL_AUTH -e "SELECT 1" "$DB_NAME" > /dev/null 2>&1; then
        print_error "Cannot connect to database $DB_NAME. Check credentials."
        exit 1
    fi
    print_success "Database connection verified (root credentials)."
fi

# ============================================================
# Step 2: Pre-update backup
# ============================================================
print_header "Step 2: Pre-Update Backup"

BACKUP_TS=$(date '+%Y%m%d_%H%M%S')
BACKUP_DIR="$INSTALL_DIR/storage/backups"
mkdir -p "$BACKUP_DIR"

# Database backup
print_step "Backing up database..."
DB_BACKUP_FILE="$BACKUP_DIR/pre_update_${BACKUP_TS}.sql.gz"
mysqldump --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" --password="$DB_PASS" \
    --single-transaction --routines --triggers "$DB_NAME" 2>/dev/null | gzip > "$DB_BACKUP_FILE"

if [ -s "$DB_BACKUP_FILE" ]; then
    BACKUP_SIZE=$(du -h "$DB_BACKUP_FILE" | cut -f1)
    print_success "Database backed up ($BACKUP_SIZE): $DB_BACKUP_FILE"
else
    print_warn "Database backup may be empty. Continuing anyway..."
fi

# Config backup
print_step "Backing up configuration..."
CONFIG_BACKUP="$BACKUP_DIR/config_backup_${BACKUP_TS}.php"
cp "$INSTALL_DIR/config/config.php" "$CONFIG_BACKUP"
print_success "Config backed up: $CONFIG_BACKUP"

echo ""
print_info "Pre-update backups saved to: $BACKUP_DIR"
print_info "If anything goes wrong, restore the database with:"
print_info "  gunzip < $DB_BACKUP_FILE | $MYSQL_CMD $MYSQL_AUTH $DB_NAME"

# ============================================================
# Step 3: Update application files
# ============================================================
print_header "Step 3: Updating Application Files"

# Ensure source and destination are different
if [ "$SCRIPT_DIR" = "$INSTALL_DIR" ]; then
    print_info "Source and install directory are the same. Skipping file copy."
else
    print_step "Syncing application files to $INSTALL_DIR..."

    rsync -a --delete \
        --exclude='config/config.php' \
        --exclude='vendor/' \
        --exclude='public/uploads/' \
        --exclude='public/generated-pdfs/' \
        --exclude='storage/backups/' \
        --exclude='storage/logs/' \
        --exclude='storage/cache/' \
        --exclude='storage/temp/' \
        --exclude='.git/' \
        --exclude='.env' \
        "$SCRIPT_DIR/" "$INSTALL_DIR/"

    print_success "Application files updated."
fi

# ============================================================
# Step 4: Patch configuration for new features
# ============================================================
print_header "Step 4: Patching Configuration"

# Add German ('de') to supported_languages if not already present
print_step "Checking supported_languages configuration..."
HAS_DE=$(php -r "\$c = require '$INSTALL_DIR/config/config.php'; echo in_array('de', \$c['sds']['supported_languages'] ?? []) ? 'yes' : 'no';" 2>/dev/null)

if [ "$HAS_DE" = "no" ]; then
    print_step "Adding German (de) to supported_languages..."
    # Use PHP to safely patch the config array
    php -r "
        \$file = '$INSTALL_DIR/config/config.php';
        \$content = file_get_contents(\$file);
        // Add 'de' to the supported_languages array
        \$content = preg_replace(
            \"/('supported_languages'\s*=>\s*\[)([^\]]*?)(])/\",
            '\${1}\${2}, \'de\'\${3}',
            \$content
        );
        // Clean up any double commas or leading commas
        \$content = preg_replace(\"/, ,/\", ',', \$content);
        file_put_contents(\$file, \$content);
    " 2>/dev/null
    print_success "German language support added to config."
else
    print_success "German language already configured."
fi

# Add publish_workers if not already present
print_step "Checking publish_workers configuration..."
HAS_PW=$(php -r "\$c = require '$INSTALL_DIR/config/config.php'; echo isset(\$c['sds']['publish_workers']) ? 'yes' : 'no';" 2>/dev/null)

if [ "$HAS_PW" = "no" ]; then
    print_step "Adding publish_workers setting to sds config..."
    php -r "
        \$file = '$INSTALL_DIR/config/config.php';
        \$content = file_get_contents(\$file);
        // Add publish_workers after voc_calc_mode line
        \$content = preg_replace(
            \"/('voc_calc_mode'\s*=>\s*'[^']*'),/\",
            '\${1},' . \"\\n\" . \"        'publish_workers'        => 0,\",
            \$content
        );
        file_put_contents(\$file, \$content);
    " 2>/dev/null
    print_success "publish_workers setting added to config."
else
    print_success "publish_workers already configured."
fi

# ============================================================
# Step 5: Update PHP Dependencies
# ============================================================
print_header "Step 5: Updating PHP Dependencies"

cd "$INSTALL_DIR"

print_step "Running composer install..."
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --quiet 2>/dev/null

print_success "PHP dependencies updated."

# ============================================================
# Step 6: Create any missing directories
# ============================================================
print_header "Step 6: Verifying Directory Structure"

DIRS_CREATED=0
for dir in \
    public/uploads/supplier-sds \
    public/generated-pdfs \
    storage/logs \
    storage/cache \
    storage/temp \
    storage/backups
do
    if [ ! -d "$INSTALL_DIR/$dir" ]; then
        mkdir -p "$INSTALL_DIR/$dir"
        DIRS_CREATED=$((DIRS_CREATED + 1))
        print_step "  Created: $dir"
    fi
done

if [ "$DIRS_CREATED" -eq 0 ]; then
    print_success "All directories present."
else
    print_success "$DIRS_CREATED missing directories created."
fi

# ============================================================
# Step 7: Run database migrations
# ============================================================
print_header "Step 7: Running Database Migrations"

MIGRATIONS_APPLIED=0
MIGRATIONS_SKIPPED=0

for migration in "$INSTALL_DIR"/migrations/*.sql; do
    if [ -f "$migration" ]; then
        MIGRATION_NAME=$(basename "$migration" .sql)

        # Check if already applied (schema_migrations table may not exist for first migration)
        APPLIED=$($MYSQL_CMD $MYSQL_AUTH -N -e \
            "SELECT COUNT(*) FROM \`$DB_NAME\`.schema_migrations WHERE version='$MIGRATION_NAME'" 2>/dev/null || echo "0")

        if [ "$APPLIED" = "0" ]; then
            print_step "  Applying $MIGRATION_NAME..."
            $MYSQL_CMD $MYSQL_AUTH "$DB_NAME" < "$migration"
            MIGRATIONS_APPLIED=$((MIGRATIONS_APPLIED + 1))
        else
            MIGRATIONS_SKIPPED=$((MIGRATIONS_SKIPPED + 1))
        fi
    fi
done

if [ "$MIGRATIONS_APPLIED" -eq 0 ]; then
    print_success "Database is up to date ($MIGRATIONS_SKIPPED migrations already applied)."
else
    print_success "$MIGRATIONS_APPLIED new migration(s) applied, $MIGRATIONS_SKIPPED already present."
fi

# -- Schema fixes --------------------------------------------------
# group_permissions.access_level must be ENUM('none','read','full').
# If the table was created before migration 010 defined the correct
# ENUM, the column may have a different type causing "Data truncated"
# errors when creating permission groups.
print_step "Checking group_permissions.access_level column type..."
if $MYSQL_CMD $MYSQL_AUTH -N -e \
    "SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = '$DB_NAME'
       AND TABLE_NAME   = 'group_permissions'
       AND COLUMN_NAME  = 'access_level'" "$DB_NAME" 2>/dev/null | grep -q 1; then

    CURRENT_TYPE=$($MYSQL_CMD $MYSQL_AUTH -N -e \
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = '$DB_NAME'
           AND TABLE_NAME   = 'group_permissions'
           AND COLUMN_NAME  = 'access_level'" "$DB_NAME" 2>/dev/null)

    if [ "$CURRENT_TYPE" != "enum('none','read','full')" ]; then
        print_step "  Fixing access_level column (was: $CURRENT_TYPE)..."
        $MYSQL_CMD $MYSQL_AUTH "$DB_NAME" -e \
            "ALTER TABLE group_permissions MODIFY COLUMN access_level ENUM('none','read','full') NOT NULL DEFAULT 'none'"
        print_success "  access_level column corrected."
    else
        print_success "group_permissions.access_level is correct."
    fi
else
    print_info "group_permissions table not found yet — will be created by migrations."
fi

# ============================================================
# Step 8: Refresh seed data
# ============================================================
print_header "Step 8: Refreshing Seed Data"

print_step "Updating regulatory seed data (Prop 65, IARC/NTP/OSHA, HAPs, SARA 313, NIOSH, EPA, DOT)..."
print_info "This uses upsert logic — existing data is updated, nothing is deleted."

cd "$INSTALL_DIR"
if [ -f "$INSTALL_DIR/scripts/load-seed-data.php" ]; then
    COMPOSER_ALLOW_SUPERUSER=1 php "$INSTALL_DIR/scripts/load-seed-data.php" 2>&1 | while IFS= read -r line; do
        echo "  $line"
    done
    print_success "Seed data refreshed."
else
    print_warn "Seed data loader not found. Skipping."
fi

# ============================================================
# Step 9: Fix file permissions
# ============================================================
print_header "Step 9: Setting File Permissions"

print_step "Setting ownership to www-data..."
chown -R www-data:www-data "$INSTALL_DIR"

print_step "Setting directory permissions..."
chmod -R 755 "$INSTALL_DIR"
chmod -R 775 "$INSTALL_DIR/public/uploads"
chmod -R 775 "$INSTALL_DIR/public/generated-pdfs"
chmod -R 775 "$INSTALL_DIR/storage"

# Protect config file
chmod 640 "$INSTALL_DIR/config/config.php"

print_success "File permissions set."

# ============================================================
# Step 10: Clear application cache
# ============================================================
print_header "Step 10: Clearing Cache"

if [ -d "$INSTALL_DIR/storage/cache" ]; then
    rm -rf "$INSTALL_DIR/storage/cache/"* 2>/dev/null || true
    print_success "Application cache cleared."
else
    print_success "No cache to clear."
fi

# ============================================================
# Step 11: Restart Apache
# ============================================================
print_header "Step 11: Restarting Apache"

if systemctl is-active --quiet apache2; then
    print_step "Restarting Apache..."
    systemctl restart apache2
    print_success "Apache restarted."
elif systemctl is-active --quiet httpd; then
    print_step "Restarting httpd..."
    systemctl restart httpd
    print_success "httpd restarted."
else
    print_warn "Could not detect Apache service. You may need to restart it manually."
fi

# ============================================================
# Step 12: Verification
# ============================================================
print_header "Step 12: Post-Update Verification"

ERRORS=0

# Check Apache
if systemctl is-active --quiet apache2 2>/dev/null || systemctl is-active --quiet httpd 2>/dev/null; then
    print_success "Web server is running."
else
    print_error "Web server is NOT running."
    ERRORS=$((ERRORS + 1))
fi

# Check database connection
if $MYSQL_CMD --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" --password="$DB_PASS" \
    -e "SELECT 1 FROM \`$DB_NAME\`.users LIMIT 1" > /dev/null 2>&1; then
    print_success "Database connection verified."
else
    print_error "Database connection failed."
    ERRORS=$((ERRORS + 1))
fi

# Check PHP syntax on key files
print_step "Checking PHP syntax..."
PHP_ERRORS=0
for php_file in \
    "$INSTALL_DIR/src/Core/App.php" \
    "$INSTALL_DIR/src/Core/Database.php" \
    "$INSTALL_DIR/public/index.php"
do
    if [ -f "$php_file" ]; then
        if ! php -l "$php_file" > /dev/null 2>&1; then
            print_error "  Syntax error in: $(basename "$php_file")"
            PHP_ERRORS=$((PHP_ERRORS + 1))
        fi
    fi
done

if [ "$PHP_ERRORS" -eq 0 ]; then
    print_success "PHP syntax check passed."
else
    print_error "$PHP_ERRORS PHP syntax errors found."
    ERRORS=$((ERRORS + $PHP_ERRORS))
fi

# Check file permissions
if [ -w "$INSTALL_DIR/storage" ] && [ -w "$INSTALL_DIR/public/uploads" ]; then
    print_success "File permissions look correct."
else
    print_warn "File permissions may need adjustment."
fi

# Count migrations applied
MIGRATION_COUNT=$($MYSQL_CMD --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" --password="$DB_PASS" \
    -N -e "SELECT COUNT(*) FROM \`$DB_NAME\`.schema_migrations" 2>/dev/null || echo "?")
print_info "Database schema migrations applied: $MIGRATION_COUNT"

if [ "$ERRORS" -gt 0 ]; then
    echo ""
    print_warn "$ERRORS issue(s) detected. Review the errors above."
    echo ""
    print_info "To roll back the database, run:"
    print_info "  gunzip < $DB_BACKUP_FILE | $MYSQL_CMD $MYSQL_AUTH $DB_NAME"
    print_info "To restore the config, run:"
    print_info "  cp $CONFIG_BACKUP $INSTALL_DIR/config/config.php"
fi

# ============================================================
# Done!
# ============================================================
print_header "Update Complete!"

if [ "$ERRORS" -eq 0 ]; then
    echo -e "${GREEN}The SDS System has been updated successfully.${NC}"
else
    echo -e "${YELLOW}The update completed with $ERRORS warning(s). Please review above.${NC}"
fi
echo ""
echo "  Installation:  $INSTALL_DIR"
echo "  Database:      $DB_NAME"
echo "  Migrations:    $MIGRATION_COUNT applied"
echo ""
echo "  Pre-update backups:"
echo "    Database: $DB_BACKUP_FILE"
echo "    Config:   $CONFIG_BACKUP"
echo ""
if [ "$MIGRATIONS_APPLIED" -gt 0 ]; then
    echo -e "${CYAN}New migrations were applied. Review the changelog for any${NC}"
    echo -e "${CYAN}new features or settings that may need configuration.${NC}"
    echo ""
fi
echo -e "${GREEN}The system is ready to use.${NC}"
