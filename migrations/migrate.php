#!/usr/bin/env php
<?php
/**
 * Database Migration Runner
 * Usage: php migrations/migrate.php
 */

$configPath = __DIR__ . '/../config/config.php';
if (!file_exists($configPath)) {
    fwrite(STDERR, "ERROR: config/config.php not found. Copy config.example.php and configure.\n");
    exit(1);
}
$config = require $configPath;

$db = $config['db'];
$dsn = "mysql:host={$db['host']};port={$db['port']};charset={$db['charset']}";

try {
    // Connect without selecting a database first
    $pdo = new PDO($dsn, $db['user'], $db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Create database if not exists
    $dbName = $db['name'];
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$dbName}`");

    echo "Connected to database '{$dbName}'.\n";

    // Check which migrations have been applied
    $applied = [];
    try {
        $stmt = $pdo->query("SELECT version FROM schema_migrations ORDER BY version");
        $applied = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        // Table doesn't exist yet, that's fine
    }

    // Find migration files
    $migrationDir = __DIR__;
    $files = glob($migrationDir . '/*.sql');
    sort($files);

    $count = 0;
    foreach ($files as $file) {
        $version = basename($file, '.sql');
        if (in_array($version, $applied)) {
            echo "  SKIP: {$version} (already applied)\n";
            continue;
        }

        echo "  APPLYING: {$version} ... ";
        $sql = file_get_contents($file);

        // Execute multi-statement SQL
        $pdo->exec($sql);

        echo "OK\n";
        $count++;
    }

    if ($count === 0) {
        echo "All migrations are up to date.\n";
    } else {
        echo "Applied {$count} migration(s).\n";
    }

} catch (PDOException $e) {
    fwrite(STDERR, "Database error: " . $e->getMessage() . "\n");
    exit(1);
}
