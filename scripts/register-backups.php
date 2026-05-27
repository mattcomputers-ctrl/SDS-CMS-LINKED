#!/usr/bin/env php
<?php
/**
 * register-backups.php — Find backup files on disk that aren't in the
 * backups table and register them so they appear on the Backups page.
 *
 * Called automatically by update.sh after migrations.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SDS\Core\App;
use SDS\Core\Database;

App::boot();
$db = Database::getInstance();

$backupDir = App::basePath() . '/storage/backups';
if (!is_dir($backupDir)) {
    exit(0);
}

$registered = 0;

foreach (glob($backupDir . '/pre_update_*.sql.gz') as $file) {
    $filename = basename($file);

    $exists = $db->fetch(
        "SELECT id FROM backups WHERE filename = ?",
        [$filename]
    );
    if ($exists) {
        continue;
    }

    $size = filesize($file) ?: 0;
    $mtime = date('Y-m-d H:i:s', filemtime($file) ?: time());

    $db->insert('backups', [
        'filename'    => $filename,
        'backup_type' => 'full',
        'file_size'   => $size,
        'notes'       => 'Pre-update backup',
        'created_by'  => null,
        'created_at'  => $mtime,
    ]);
    $registered++;
}

if ($registered > 0) {
    echo "Registered {$registered} backup(s) on the Backups page.\n";
}
