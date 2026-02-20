<?php

declare(strict_types=1);

namespace SDS\Services;

use SDS\Core\App;
use SDS\Core\Database;

/**
 * BackupService — database and file backup / restore operations.
 *
 * Two backup types:
 *   - full:    Complete mysqldump of all tables + uploaded files / generated PDFs.
 *   - content: Only data-bearing tables (raw materials, formulas, finished goods,
 *              regulatory lists, hazard data, SDS versions).  Does NOT include
 *              settings, users, audit_log, or schema_migrations — safe for
 *              restoring content into a clean install without overwriting config.
 */
class BackupService
{
    /** Tables included in a "content-only" backup. */
    private const CONTENT_TABLES = [
        'cas_master',
        'raw_materials',
        'raw_material_constituents',
        'finished_goods',
        'formulas',
        'formula_lines',
        'prop65_list',
        'carcinogen_list',
        'sara313_list',
        'exempt_voc_list',
        'hazard_source_records',
        'hazard_classifications',
        'exposure_limits',
        'sds_versions',
        'sds_generation_trace',
        'text_overrides',
        'dot_transport_info',
        'competent_person_determinations',
        'dataset_refresh_log',
    ];

    /** Directory where backups are stored. */
    public static function backupDir(): string
    {
        $dir = App::basePath() . '/storage/backups';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /**
     * Create a backup.
     *
     * @param  string      $type  'full' or 'content'
     * @param  string|null $notes Optional user notes
     * @return array{id: int, filename: string, file_size: int}
     * @throws \RuntimeException on failure
     */
    public static function create(string $type = 'full', ?string $notes = null): array
    {
        $db     = Database::getInstance();
        $config = App::config('db');
        $dir    = self::backupDir();
        $ts     = date('Ymd_His');
        $dbName = $config['name'];

        $filename = "sds_backup_{$type}_{$ts}.tar.gz";
        $tmpDir   = sys_get_temp_dir() . '/sds_backup_' . $ts;
        mkdir($tmpDir, 0755, true);

        try {
            // ── 1. Database dump ──────────────────────────────────
            $sqlFile = $tmpDir . '/database.sql';
            $cmd = self::buildMysqldumpCmd($config, $dbName, $type);
            $cmd .= ' > ' . escapeshellarg($sqlFile) . ' 2>&1';

            exec($cmd, $output, $rc);
            if ($rc !== 0) {
                throw new \RuntimeException(
                    'mysqldump failed (code ' . $rc . '): ' . implode("\n", $output)
                );
            }

            // ── 2. Copy uploaded files (full backup only) ────────
            if ($type === 'full') {
                $uploadsDir = App::basePath() . '/public/uploads';
                $pdfsDir    = App::basePath() . '/public/generated-pdfs';

                if (is_dir($uploadsDir)) {
                    self::copyDir($uploadsDir, $tmpDir . '/uploads');
                }
                if (is_dir($pdfsDir)) {
                    self::copyDir($pdfsDir, $tmpDir . '/generated-pdfs');
                }
            }

            // ── 3. Write manifest ────────────────────────────────
            $manifest = [
                'type'       => $type,
                'created_at' => date('Y-m-d H:i:s'),
                'version'    => App::config('app.version', '1.0.0'),
                'tables'     => $type === 'content' ? self::CONTENT_TABLES : ['*'],
            ];
            file_put_contents($tmpDir . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));

            // ── 4. Create tar.gz ─────────────────────────────────
            $archivePath = $dir . '/' . $filename;
            $tarCmd = 'tar -czf ' . escapeshellarg($archivePath)
                    . ' -C ' . escapeshellarg($tmpDir) . ' .';
            exec($tarCmd, $tarOut, $tarRc);
            if ($tarRc !== 0) {
                throw new \RuntimeException('tar failed: ' . implode("\n", $tarOut));
            }

            $fileSize = filesize($archivePath);

            // ── 5. Record in database ────────────────────────────
            $id = $db->insert('backups', [
                'filename'    => $filename,
                'backup_type' => $type,
                'file_size'   => $fileSize,
                'tables_json' => json_encode($manifest['tables']),
                'notes'       => $notes,
                'created_by'  => current_user_id(),
            ]);

            return ['id' => (int) $id, 'filename' => $filename, 'file_size' => $fileSize];
        } finally {
            // Clean up temp directory
            self::removeDir($tmpDir);
        }
    }

    /**
     * Restore a backup from the given backup record ID.
     *
     * @param  int  $id       Backup row ID
     * @param  bool $confirm  Must be true to proceed (safety check)
     * @return array{tables_restored: int, files_restored: bool}
     * @throws \RuntimeException on failure
     */
    public static function restore(int $id, bool $confirm = false): array
    {
        if (!$confirm) {
            throw new \RuntimeException('Restore requires explicit confirmation.');
        }

        $db     = Database::getInstance();
        $config = App::config('db');
        $dir    = self::backupDir();

        $record = $db->fetch("SELECT * FROM backups WHERE id = ?", [$id]);
        if (!$record) {
            throw new \RuntimeException('Backup record not found.');
        }

        $archivePath = $dir . '/' . $record['filename'];
        if (!file_exists($archivePath)) {
            throw new \RuntimeException('Backup file not found on disk: ' . $record['filename']);
        }

        $tmpDir = sys_get_temp_dir() . '/sds_restore_' . time();
        mkdir($tmpDir, 0755, true);

        try {
            // ── 1. Extract archive ───────────────────────────────
            $tarCmd = 'tar -xzf ' . escapeshellarg($archivePath)
                    . ' -C ' . escapeshellarg($tmpDir);
            exec($tarCmd, $tarOut, $tarRc);
            if ($tarRc !== 0) {
                throw new \RuntimeException('Failed to extract archive.');
            }

            $sqlFile = $tmpDir . '/database.sql';
            if (!file_exists($sqlFile)) {
                throw new \RuntimeException('No database.sql found in backup archive.');
            }

            // ── 2. Restore database ──────────────────────────────
            $mysqlCmd = sprintf(
                'mysql --host=%s --port=%s --user=%s --password=%s %s < %s 2>&1',
                escapeshellarg($config['host']),
                escapeshellarg((string) ($config['port'] ?? 3306)),
                escapeshellarg($config['user']),
                escapeshellarg($config['password']),
                escapeshellarg($config['name']),
                escapeshellarg($sqlFile)
            );

            exec($mysqlCmd, $sqlOut, $sqlRc);
            if ($sqlRc !== 0) {
                throw new \RuntimeException(
                    'mysql restore failed (code ' . $sqlRc . '): ' . implode("\n", $sqlOut)
                );
            }

            // ── 3. Restore files (full backup only) ──────────────
            $filesRestored = false;
            if ($record['backup_type'] === 'full') {
                $uploadsDir = App::basePath() . '/public/uploads';
                $pdfsDir    = App::basePath() . '/public/generated-pdfs';

                if (is_dir($tmpDir . '/uploads')) {
                    self::copyDir($tmpDir . '/uploads', $uploadsDir);
                    $filesRestored = true;
                }
                if (is_dir($tmpDir . '/generated-pdfs')) {
                    self::copyDir($tmpDir . '/generated-pdfs', $pdfsDir);
                    $filesRestored = true;
                }
            }

            // Read manifest for table count
            $manifest = [];
            if (file_exists($tmpDir . '/manifest.json')) {
                $manifest = json_decode(file_get_contents($tmpDir . '/manifest.json'), true) ?: [];
            }

            $tableCount = count($manifest['tables'] ?? []);
            if (in_array('*', $manifest['tables'] ?? [])) {
                $tableCount = -1; // all tables
            }

            return [
                'tables_restored' => $tableCount,
                'files_restored'  => $filesRestored,
            ];
        } finally {
            self::removeDir($tmpDir);
        }
    }

    /**
     * Delete a backup file and its database record.
     */
    public static function delete(int $id): void
    {
        $db     = Database::getInstance();
        $dir    = self::backupDir();
        $record = $db->fetch("SELECT * FROM backups WHERE id = ?", [$id]);

        if ($record) {
            $filePath = $dir . '/' . $record['filename'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            $db->query("DELETE FROM backups WHERE id = ?", [$id]);
        }
    }

    /**
     * List all backups.
     *
     * @return array
     */
    public static function listAll(): array
    {
        $db = Database::getInstance();
        return $db->fetchAll(
            "SELECT b.*, u.display_name AS created_by_name
             FROM backups b
             LEFT JOIN users u ON u.id = b.created_by
             ORDER BY b.created_at DESC"
        );
    }

    /**
     * Get the filesystem path for downloading a backup.
     */
    public static function getFilePath(int $id): ?string
    {
        $db     = Database::getInstance();
        $dir    = self::backupDir();
        $record = $db->fetch("SELECT filename FROM backups WHERE id = ?", [$id]);

        if (!$record) {
            return null;
        }

        $path = $dir . '/' . $record['filename'];
        return file_exists($path) ? $path : null;
    }

    // ── Private helpers ──────────────────────────────────────────

    private static function buildMysqldumpCmd(array $config, string $dbName, string $type): string
    {
        $cmd = sprintf(
            'mysqldump --host=%s --port=%s --user=%s --password=%s --single-transaction --routines --triggers',
            escapeshellarg($config['host']),
            escapeshellarg((string) ($config['port'] ?? 3306)),
            escapeshellarg($config['user']),
            escapeshellarg($config['password'])
        );

        if ($type === 'content') {
            // Only specific tables, no CREATE DATABASE
            $tables = implode(' ', array_map('escapeshellarg', self::CONTENT_TABLES));
            $cmd .= ' ' . escapeshellarg($dbName) . ' ' . $tables;
        } else {
            $cmd .= ' ' . escapeshellarg($dbName);
        }

        return $cmd;
    }

    private static function copyDir(string $src, string $dst): void
    {
        if (!is_dir($dst)) {
            mkdir($dst, 0755, true);
        }
        $cmd = sprintf('cp -a %s/. %s/', escapeshellarg($src), escapeshellarg($dst));
        exec($cmd);
    }

    private static function removeDir(string $dir): void
    {
        if (is_dir($dir)) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }
}
