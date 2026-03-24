<?php

declare(strict_types=1);

namespace SDS\Services;

use SDS\Core\App;
use SDS\Core\Database;

/**
 * BackupService — database and file backup / restore operations.
 *
 * Backup types:
 *   - full:         Complete mysqldump of ALL tables + uploaded files / generated PDFs.
 *   - product_data: Raw materials, CAS, constituents, finished goods, formulas, aliases,
 *                   competent person determinations, exempt VOCs.
 *   - settings:     Settings table, users, permission groups (NOT network settings).
 *   - sds_history:  SDS versions, generation traces, text overrides, generated PDFs.
 *   - regulatory:   Seed/regulatory reference data (SARA 313, Prop 65, carcinogens,
 *                   HAPs, SNUR, CAS master, hazard data, exposure limits, DOT info).
 */
class BackupService
{
    /** Section → tables mapping for section-based backups. */
    public const SECTIONS = [
        'product_data' => [
            'label'  => 'Product Data',
            'desc'   => 'Raw materials, CAS constituents, finished goods, formulas, aliases, CAS determinations, and exempt VOCs.',
            'tables' => [
                'raw_materials',
                'raw_material_constituents',
                'raw_material_sds',
                'finished_goods',
                'formulas',
                'formula_lines',
                'aliases',
                'competent_person_determinations',
                'exempt_voc_list',
            ],
            'files' => ['uploads/supplier-sds'],
        ],
        'settings' => [
            'label'  => 'Settings',
            'desc'   => 'System settings (not network), users, permission groups, label templates, pictograms.',
            'tables' => [
                'settings',
                'users',
                'permission_groups',
                'group_permissions',
                'user_group_members',
                'label_templates',
            ],
            'files' => ['uploads/pictograms', 'uploads/logos'],
        ],
        'label_templates' => [
            'label'  => 'Label Templates',
            'desc'   => 'All label templates (layout, dimensions, field configuration).',
            'tables' => [
                'label_templates',
            ],
            'files' => [],
        ],
        'sds_history' => [
            'label'  => 'SDS History',
            'desc'   => 'All published/draft SDS versions, generation traces, text overrides, and generated PDFs.',
            'tables' => [
                'sds_versions',
                'sds_generation_trace',
                'text_overrides',
            ],
            'files' => ['generated-pdfs'],
        ],
        'regulatory' => [
            'label'  => 'Regulatory & Reference Data',
            'desc'   => 'CAS master, SARA 313, Prop 65, carcinogens, HAPs, SNUR list, hazard source records, hazard classifications, exposure limits, DOT transport, and dataset refresh logs.',
            'tables' => [
                'cas_master',
                'prop65_list',
                'carcinogen_list',
                'sara313_list',
                'hap_list',
                'snur_list',
                'hazard_source_records',
                'hazard_classifications',
                'exposure_limits',
                'dot_transport_info',
                'dataset_refresh_log',
            ],
            'files' => [],
        ],
    ];

    /** All data tables across all sections (union of all section tables). */
    private static function allDataTables(): array
    {
        $tables = [];
        foreach (self::SECTIONS as $section) {
            $tables = array_merge($tables, $section['tables']);
        }
        return array_unique($tables);
    }

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
     * Get the tables for a given backup type.
     *
     * @param  string $type 'full', 'product_data', 'settings', 'sds_history', 'regulatory'
     * @return array|null   Table list, or null for full (all tables)
     */
    public static function tablesForType(string $type): ?array
    {
        if ($type === 'full') {
            return null; // all tables
        }
        return self::SECTIONS[$type]['tables'] ?? null;
    }

    /**
     * Get the file directories for a given backup type.
     */
    public static function filesForType(string $type): array
    {
        if ($type === 'full') {
            return ['uploads', 'generated-pdfs'];
        }
        return self::SECTIONS[$type]['files'] ?? [];
    }

    /**
     * Create a backup.
     *
     * @param  string      $type  'full', 'product_data', 'settings', 'sds_history', 'regulatory'
     * @param  string|null $notes Optional user notes
     * @param  int|null    $createdBy User ID (null for system/cron)
     * @return array{id: int, filename: string, file_size: int}
     * @throws \RuntimeException on failure
     */
    public static function create(string $type = 'full', ?string $notes = null, ?int $createdBy = null): array
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
            $tables  = self::tablesForType($type);
            $cmd     = self::buildMysqldumpCmd($config, $dbName, $tables);
            $cmd    .= ' > ' . escapeshellarg($sqlFile) . ' 2>&1';

            exec($cmd, $output, $rc);
            if ($rc !== 0) {
                throw new \RuntimeException(
                    'mysqldump failed (code ' . $rc . '): ' . implode("\n", $output)
                );
            }

            // ── 2. Copy relevant files ───────────────────────────
            $fileDirs   = self::filesForType($type);
            $basePath   = App::basePath() . '/public';

            foreach ($fileDirs as $relDir) {
                $srcDir = $basePath . '/' . $relDir;
                if (is_dir($srcDir)) {
                    self::copyDir($srcDir, $tmpDir . '/files/' . $relDir);
                }
            }

            // ── 3. Write manifest ────────────────────────────────
            $manifest = [
                'type'       => $type,
                'created_at' => date('Y-m-d H:i:s'),
                'version'    => App::config('app.version', '1.0.0'),
                'tables'     => $tables ?? ['*'],
                'files'      => $fileDirs,
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
            $userId = $createdBy ?? (function_exists('current_user_id') ? current_user_id() : null);
            $id = $db->insert('backups', [
                'filename'    => $filename,
                'backup_type' => $type,
                'file_size'   => $fileSize,
                'tables_json' => json_encode($manifest['tables']),
                'notes'       => $notes,
                'created_by'  => $userId,
            ]);

            return [
                'id'        => (int) $id,
                'filename'  => $filename,
                'file_size' => $fileSize,
                'path'      => $archivePath,
            ];
        } finally {
            self::removeDir($tmpDir);
        }
    }

    /**
     * Restore a backup from the given backup record ID.
     *
     * @param  int  $id       Backup row ID
     * @param  bool $confirm  Must be true to proceed (safety check)
     * @return array{tables_restored: int, files_restored: bool, type: string}
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

            // Read manifest
            $manifest = [];
            if (file_exists($tmpDir . '/manifest.json')) {
                $manifest = json_decode(file_get_contents($tmpDir . '/manifest.json'), true) ?: [];
            }

            $backupType = $manifest['type'] ?? $record['backup_type'];

            // ── 2. For section-based restores, drop existing data first ──
            $tables = $manifest['tables'] ?? [];
            if (!in_array('*', $tables) && !empty($tables)) {
                $db->query("SET FOREIGN_KEY_CHECKS = 0");
                foreach ($tables as $table) {
                    $db->query("TRUNCATE TABLE `{$table}`");
                }
                $db->query("SET FOREIGN_KEY_CHECKS = 1");
            }

            // ── 3. Restore database ──────────────────────────────
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

            // ── 4. Restore files ─────────────────────────────────
            $filesRestored = false;
            $basePath = App::basePath() . '/public';
            $fileDirs = $manifest['files'] ?? [];

            // Support legacy backup format (pre-sections)
            if (empty($fileDirs) && $backupType === 'full') {
                // Legacy full backup: check for old-style dirs
                if (is_dir($tmpDir . '/uploads')) {
                    self::copyDir($tmpDir . '/uploads', $basePath . '/uploads');
                    $filesRestored = true;
                }
                if (is_dir($tmpDir . '/generated-pdfs')) {
                    self::copyDir($tmpDir . '/generated-pdfs', $basePath . '/generated-pdfs');
                    $filesRestored = true;
                }
            } else {
                foreach ($fileDirs as $relDir) {
                    $srcDir = $tmpDir . '/files/' . $relDir;
                    $dstDir = $basePath . '/' . $relDir;
                    if (is_dir($srcDir)) {
                        self::copyDir($srcDir, $dstDir);
                        $filesRestored = true;
                    }
                }
            }

            $tableCount = count($tables);
            if (in_array('*', $tables)) {
                $tableCount = -1; // all tables
            }

            return [
                'tables_restored' => $tableCount,
                'files_restored'  => $filesRestored,
                'type'            => $backupType,
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

    // ── FTP Operations ──────────────────────────────────────────

    /**
     * Get FTP configuration from settings.
     */
    public static function getFtpConfig(): array
    {
        $db = Database::getInstance();
        $keys = [
            'backup.ftp_enabled',
            'backup.ftp_host',
            'backup.ftp_port',
            'backup.ftp_username',
            'backup.ftp_password',
            'backup.ftp_path',
            'backup.ftp_passive',
            'backup.ftp_ssl',
            'backup.schedule_enabled',
            'backup.schedule_frequency',
            'backup.schedule_type',
            'backup.schedule_time',
            'backup.schedule_retention',
        ];

        $config = [];
        foreach ($keys as $key) {
            $row = $db->fetch("SELECT `value` FROM settings WHERE `key` = ?", [$key]);
            $shortKey = str_replace('backup.', '', $key);
            $config[$shortKey] = $row['value'] ?? '';
        }

        return $config;
    }

    /**
     * Save FTP configuration to settings.
     */
    public static function saveFtpConfig(array $config): void
    {
        $db = Database::getInstance();

        foreach ($config as $key => $value) {
            $fullKey  = 'backup.' . $key;
            $existing = $db->fetch("SELECT `key` FROM settings WHERE `key` = ?", [$fullKey]);
            if ($existing) {
                $db->update('settings', ['value' => (string) $value], '`key` = ?', [$fullKey]);
            } else {
                $db->insert('settings', ['key' => $fullKey, 'value' => (string) $value]);
            }
        }
    }

    /**
     * Upload a backup file to the configured FTP server.
     *
     * @param  string $localPath  Full path to the backup file
     * @param  string $remoteFilename  Filename on the remote server
     * @return array{success: bool, message: string}
     */
    public static function uploadToFtp(string $localPath, string $remoteFilename): array
    {
        $config = self::getFtpConfig();

        if (empty($config['ftp_host'])) {
            return ['success' => false, 'message' => 'FTP host is not configured.'];
        }

        $host    = $config['ftp_host'];
        $port    = (int) ($config['ftp_port'] ?: 21);
        $user    = $config['ftp_username'] ?? '';
        $pass    = $config['ftp_password'] ?? '';
        $path    = rtrim($config['ftp_path'] ?? '', '/');
        $passive = ($config['ftp_passive'] ?? '1') === '1';
        $ssl     = ($config['ftp_ssl'] ?? '0') === '1';

        try {
            if ($ssl && function_exists('ftp_ssl_connect')) {
                $conn = @ftp_ssl_connect($host, $port, 30);
            } else {
                $conn = @ftp_connect($host, $port, 30);
            }

            if (!$conn) {
                return ['success' => false, 'message' => "Could not connect to FTP server {$host}:{$port}."];
            }

            if (!@ftp_login($conn, $user, $pass)) {
                ftp_close($conn);
                return ['success' => false, 'message' => 'FTP login failed. Check username and password.'];
            }

            if ($passive) {
                ftp_pasv($conn, true);
            }

            // Ensure remote directory exists (create if needed)
            if ($path !== '') {
                $parts = explode('/', trim($path, '/'));
                $currentDir = '';
                foreach ($parts as $part) {
                    $currentDir .= '/' . $part;
                    @ftp_mkdir($conn, $currentDir);
                }
                @ftp_chdir($conn, $path);
            }

            $remotePath = ($path !== '' ? $path . '/' : '/') . $remoteFilename;

            if (!ftp_put($conn, $remotePath, $localPath, FTP_BINARY)) {
                ftp_close($conn);
                return ['success' => false, 'message' => 'Failed to upload file to FTP server.'];
            }

            ftp_close($conn);
            return ['success' => true, 'message' => "Backup uploaded to FTP: {$remoteFilename}"];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'FTP error: ' . $e->getMessage()];
        }
    }

    /**
     * Test FTP connection with given credentials.
     *
     * @return array{success: bool, message: string}
     */
    public static function testFtpConnection(array $config): array
    {
        $host    = $config['ftp_host'] ?? '';
        $port    = (int) ($config['ftp_port'] ?? 21);
        $user    = $config['ftp_username'] ?? '';
        $pass    = $config['ftp_password'] ?? '';
        $path    = rtrim($config['ftp_path'] ?? '', '/');
        $passive = ($config['ftp_passive'] ?? '1') === '1';
        $ssl     = ($config['ftp_ssl'] ?? '0') === '1';

        if (empty($host)) {
            return ['success' => false, 'message' => 'FTP host is required.'];
        }

        try {
            if ($ssl && function_exists('ftp_ssl_connect')) {
                $conn = @ftp_ssl_connect($host, $port, 10);
            } else {
                $conn = @ftp_connect($host, $port, 10);
            }

            if (!$conn) {
                return ['success' => false, 'message' => "Could not connect to {$host}:{$port}. Check host and port."];
            }

            if (!@ftp_login($conn, $user, $pass)) {
                ftp_close($conn);
                return ['success' => false, 'message' => 'Connection succeeded but login failed. Check username and password.'];
            }

            if ($passive) {
                ftp_pasv($conn, true);
            }

            // Test directory access
            if ($path !== '') {
                if (!@ftp_chdir($conn, $path)) {
                    // Try to create it
                    $parts = explode('/', trim($path, '/'));
                    $currentDir = '';
                    foreach ($parts as $part) {
                        $currentDir .= '/' . $part;
                        @ftp_mkdir($conn, $currentDir);
                    }
                    if (!@ftp_chdir($conn, $path)) {
                        ftp_close($conn);
                        return ['success' => false, 'message' => "Connected and logged in, but cannot access or create directory: {$path}"];
                    }
                }
            }

            $pwd = ftp_pwd($conn);
            ftp_close($conn);

            return ['success' => true, 'message' => "Connection successful. Current directory: {$pwd}"];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'FTP error: ' . $e->getMessage()];
        }
    }

    /**
     * Run a scheduled backup: create the backup and optionally upload to FTP.
     *
     * @return array{backup: array, ftp: array|null}
     */
    public static function runScheduledBackup(): array
    {
        $config = self::getFtpConfig();

        $type = $config['schedule_type'] ?: 'full';
        if (!isset(self::SECTIONS[$type]) && $type !== 'full') {
            $type = 'full';
        }

        $result = self::create($type, 'Scheduled backup', null);

        $ftpResult = null;
        if (($config['ftp_enabled'] ?? '') === '1' && !empty($config['ftp_host'])) {
            $ftpResult = self::uploadToFtp($result['path'], $result['filename']);
        }

        // Enforce retention: delete old scheduled backups beyond retention count
        $retention = (int) ($config['schedule_retention'] ?: 10);
        self::enforceRetention($retention);

        return ['backup' => $result, 'ftp' => $ftpResult];
    }

    /**
     * Keep only the N most recent backups created by the scheduler.
     */
    private static function enforceRetention(int $keepCount): void
    {
        if ($keepCount <= 0) {
            return;
        }

        $db = Database::getInstance();
        $old = $db->fetchAll(
            "SELECT id FROM backups WHERE notes = 'Scheduled backup' ORDER BY created_at DESC LIMIT 999 OFFSET ?",
            [$keepCount]
        );

        foreach ($old as $row) {
            self::delete((int) $row['id']);
        }
    }

    // ── Private helpers ──────────────────────────────────────────

    /**
     * Build mysqldump command.
     *
     * @param array       $config DB config
     * @param string      $dbName Database name
     * @param array|null  $tables Specific tables, or null for all
     */
    private static function buildMysqldumpCmd(array $config, string $dbName, ?array $tables): string
    {
        $cmd = sprintf(
            'mysqldump --host=%s --port=%s --user=%s --password=%s --single-transaction --routines --triggers',
            escapeshellarg($config['host']),
            escapeshellarg((string) ($config['port'] ?? 3306)),
            escapeshellarg($config['user']),
            escapeshellarg($config['password'])
        );

        if ($tables !== null) {
            $tableArgs = implode(' ', array_map('escapeshellarg', $tables));
            $cmd .= ' ' . escapeshellarg($dbName) . ' ' . $tableArgs;
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
