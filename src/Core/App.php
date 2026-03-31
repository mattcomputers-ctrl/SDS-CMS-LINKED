<?php

namespace SDS\Core;

use SDS\Middleware\AuthMiddleware;

/**
 * App — Application bootstrap for the SDS System.
 *
 * Loads configuration, initialises core services (database, session),
 * registers all routes, and dispatches the incoming HTTP request.
 */
class App
{
    /** @var array Full configuration array */
    private static array $config = [];

    /** @var Database */
    private static Database $database;

    /** @var Session */
    private static Session $session;

    /** @var string Absolute path to the project root */
    private static string $basePath;

    /* ------------------------------------------------------------------
     *  Bootstrap
     * ----------------------------------------------------------------*/

    public function __construct()
    {
        // Resolve project root (one level up from src/Core)
        self::$basePath = dirname(__DIR__, 2);

        // Load configuration
        $configFile = self::$basePath . '/config/config.php';
        if (!file_exists($configFile)) {
            throw new \RuntimeException('Configuration file not found: ' . $configFile);
        }
        self::$config = require $configFile;

        // Timezone
        date_default_timezone_set(self::config('app.timezone', 'America/New_York'));

        // Error reporting based on debug flag
        if (self::config('app.debug', false)) {
            error_reporting(E_ALL);
            ini_set('display_errors', '1');
        } else {
            error_reporting(0);
            ini_set('display_errors', '0');
        }

        // Initialise database singleton
        self::$database = Database::init(self::$config['db']);

        // Initialise and start session
        self::$session = new Session();
        self::$session->start(self::$config['session'] ?? []);
    }

    /* ------------------------------------------------------------------
     *  Static accessors
     * ----------------------------------------------------------------*/

    /**
     * Retrieve a config value using dot notation.
     *
     * @param string $key     e.g. 'app.name', 'db.host', 'paths.uploads'
     * @param mixed  $default Fallback if key does not exist
     * @return mixed
     */
    public static function config(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value    = self::$config;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Return the Database singleton.
     */
    public static function db(): Database
    {
        return self::$database;
    }

    /**
     * Return the Session instance.
     */
    public static function session(): Session
    {
        return self::$session;
    }

    /**
     * Return the absolute path to the project root.
     */
    public static function basePath(): string
    {
        return self::$basePath;
    }

    /* ------------------------------------------------------------------
     *  Run — route registration & dispatch
     * ----------------------------------------------------------------*/

    /**
     * Register all application routes and dispatch the current request.
     */
    public function run(): void
    {
        $router = new Router();

        // ── Global middleware ─────────────────────────────────────────
        $auth = new AuthMiddleware();
        $router->addMiddleware([$auth, 'handle']);

        // ── Authentication routes ────────────────────────────────────
        $router->get('/login',  'AuthController@loginForm');
        $router->post('/login', 'AuthController@login');
        $router->get('/logout', 'AuthController@logout');

        // ── Public RM SDS Book (no login required) ─────────────────
        $router->get('/rm-sds-book', 'SDSBookController@publicIndex');

        // ── Dashboard ────────────────────────────────────────────────
        $router->get('/', 'DashboardController@index');

        // ── SDS Lookup (read-only search / download) ─────────────────
        $router->get('/lookup',              'LookupController@index');
        $router->get('/lookup/search',       'LookupController@search');
        $router->get('/lookup/download/{id}', 'LookupController@download');

        // ── Raw Materials ────────────────────────────────────────────
        $router->get('/raw-materials',                       'RawMaterialController@index');
        $router->get('/raw-materials/create',                'RawMaterialController@create');
        $router->post('/raw-materials',                      'RawMaterialController@store');
        $router->get('/raw-materials/cas-lookup',            'RawMaterialController@casLookup');
        $router->get('/raw-materials/{id}/edit',             'RawMaterialController@edit');
        $router->post('/raw-materials/{id}',                 'RawMaterialController@update');
        $router->post('/raw-materials/{id}/delete',          'RawMaterialController@delete');
        $router->get('/raw-materials/{id}/sds',              'RawMaterialController@viewSds');
        $router->get('/raw-materials/sds-version/{sdsId}',   'RawMaterialController@viewSdsVersion');
        $router->get('/raw-materials/{id}/constituents',     'RawMaterialController@constituents');
        $router->post('/raw-materials/{id}/constituents',    'RawMaterialController@saveConstituents');

        // ── HAP / VOC Reporting ─────────────────────────────────────────
        $router->get('/reports',                 'ReportController@index');
        $router->post('/reports/upload-items',   'ReportController@uploadItemNames');
        $router->post('/reports/upload-shipping', 'ReportController@uploadShippingDetail');
        $router->post('/reports/generate',       'ReportController@generate');
        $router->post('/reports/generate-pdf',   'ReportController@generatePdf');
        $router->post('/reports/export-sds',     'ReportController@exportShippedSds');
        $router->post('/reports/clear',          'ReportController@clear');
        $router->get('/reports/customers',       'ReportController@customers');

        // ── Product Aliases ──────────────────────────────────────────────
        $router->get('/aliases',                 'AliasController@index');
        $router->post('/aliases/upload',         'AliasController@upload');
        $router->post('/aliases/{id}/delete',    'AliasController@delete');
        $router->post('/aliases/delete-all',     'AliasController@deleteAll');

        // ── SDS Book (plant lookup) ────────────────────────────────────
        $router->get('/sds-book',                         'SDSBookController@index');
        $router->post('/sds-book/delete-supplier/{id}',   'SDSBookController@deleteSupplierSds');
        $router->post('/sds-book/delete-fg/{id}',         'SDSBookController@deleteFgSds');
        $router->get('/sds-book/export',                  'ExportController@exportAllFgSds');

        // ── Finished Goods ───────────────────────────────────────────
        $router->get('/finished-goods',            'FinishedGoodController@index');
        $router->get('/finished-goods/create',     'FinishedGoodController@create');
        $router->post('/finished-goods',           'FinishedGoodController@store');
        $router->get('/finished-goods/{id}/edit',  'FinishedGoodController@edit');
        $router->post('/finished-goods/{id}',      'FinishedGoodController@update');

        // ── Formulas ─────────────────────────────────────────────────
        $router->get('/formulas/mass-replace',                 'FormulaController@massReplace');
        $router->post('/formulas/mass-replace',                'FormulaController@massReplaceSubmit');
        $router->get('/formulas/{finished_good_id}',           'FormulaController@index');
        $router->get('/formulas/{finished_good_id}/edit',      'FormulaController@edit');
        $router->post('/formulas/{finished_good_id}',          'FormulaController@update');
        $router->get('/formulas/{finished_good_id}/calculate', 'FormulaController@calculate');

        // ── SDS Generation / Versions ────────────────────────────────
        $router->get('/sds/{finished_good_id}',             'SDSController@index');
        $router->get('/sds/{finished_good_id}/preview',     'SDSController@preview');
        $router->get('/sds/{finished_good_id}/edit',        'SDSController@edit');
        $router->post('/sds/{finished_good_id}/save-edits', 'SDSController@saveEdits');
        $router->post('/sds/{finished_good_id}/publish',    'SDSController@publish');
        $router->get('/sds/version/{id}/download',          'SDSController@download');
        $router->get('/sds/version/{id}/trace',             'SDSController@trace');

        // ── CAS Determinations (permission-gated) ────────────────────
        $router->get('/determinations',              'AdminController@determinations');
        $router->get('/determinations/create',       'AdminController@createDetermination');
        $router->post('/determinations',             'AdminController@storeDetermination');
        $router->get('/determinations/{id}/edit',    'AdminController@editDetermination');
        $router->post('/determinations/{id}',        'AdminController@updateDetermination');

        // ── Exempt VOC Library (permission-gated) ───────────────────
        $router->get('/exempt-vocs',              'AdminController@exemptVocs');
        $router->get('/exempt-vocs/create',       'AdminController@createExemptVoc');
        $router->post('/exempt-vocs',             'AdminController@storeExemptVoc');
        $router->get('/exempt-vocs/{id}/edit',    'AdminController@editExemptVoc');
        $router->post('/exempt-vocs/{id}',        'AdminController@updateExemptVoc');
        $router->post('/exempt-vocs/{id}/delete', 'AdminController@deleteExemptVoc');

        // ── Bulk SDS Publish (permission-gated) ─────────────────────
        $router->get('/bulk-publish',                    'BulkPublishController@page');
        $router->post('/bulk-publish/start',             'BulkPublishController@start');
        $router->get('/bulk-publish/progress/{token}',   'BulkPublishController@progress');

        // ── Manufacturers ────────────────────────────────────────
        $router->get('/manufacturers',                     'ManufacturerController@index');
        $router->get('/manufacturers/create',              'ManufacturerController@create');
        $router->post('/manufacturers',                    'ManufacturerController@store');
        $router->get('/manufacturers/{id}/edit',           'ManufacturerController@edit');
        $router->post('/manufacturers/{id}',               'ManufacturerController@update');
        $router->post('/manufacturers/{id}/delete',        'ManufacturerController@delete');

        // ── SDS Update Required ─────────────────────────────────
        $router->get('/sds-updates',                           'SDSUpdateController@index');
        $router->post('/sds-updates/scan',                     'SDSUpdateController@scan');
        $router->post('/sds-updates/republish',                'SDSUpdateController@republish');
        $router->post('/sds-updates/republish-private-label',  'SDSUpdateController@republishPrivateLabel');
        $router->post('/sds-updates/dismiss',                  'SDSUpdateController@dismiss');

        // ── Private Label SDS ────────────────────────────────────
        $router->get('/private-label',                     'PrivateLabelController@index');
        $router->get('/private-label/create',              'PrivateLabelController@create');
        $router->get('/private-label/live-preview',        'PrivateLabelController@livePreview');
        $router->post('/private-label/generate',           'PrivateLabelController@generate');
        $router->get('/private-label/{id}/download',       'PrivateLabelController@download');
        $router->get('/private-label/{id}/preview',        'PrivateLabelController@preview');

        // ── GHS Labels ────────────────────────────────────────────
        $router->get('/labels',           'LabelController@index');
        $router->post('/labels/generate', 'LabelController@generate');

        // ── Label Templates ──────────────────────────────────────
        $router->get('/label-templates',              'LabelTemplateController@index');
        $router->get('/label-templates/create',       'LabelTemplateController@create');
        $router->post('/label-templates',             'LabelTemplateController@store');
        $router->get('/label-templates/{id}/edit',    'LabelTemplateController@edit');
        $router->post('/label-templates/{id}',        'LabelTemplateController@update');
        $router->post('/label-templates/{id}/delete', 'LabelTemplateController@delete');

        // ── Bulk SDS Export (permission-gated) ──────────────────────
        $router->get('/bulk-export',                      'ExportController@exportPage');
        $router->post('/bulk-export/start',               'ExportController@startExport');
        $router->get('/bulk-export/progress/{token}',     'ExportController@exportProgress');
        $router->get('/bulk-export/download/{filename}',  'ExportController@downloadExport');

        // ── Admin routes (grouped under /admin) ──────────────────────
        $router->group('/admin', function (Router $r) {
            // Users
            $r->get('/users',            'AdminController@users');
            $r->get('/users/create',     'AdminController@createUser');
            $r->post('/users',           'AdminController@storeUser');
            $r->get('/users/{id}/edit',  'AdminController@editUser');
            $r->post('/users/{id}',      'AdminController@updateUser');

            // Permission Groups
            $r->get('/groups',              'AdminController@groups');
            $r->get('/groups/create',       'AdminController@createGroup');
            $r->post('/groups',             'AdminController@storeGroup');
            $r->get('/groups/{id}/edit',    'AdminController@editGroup');
            $r->post('/groups/{id}',        'AdminController@updateGroup');
            $r->post('/groups/{id}/delete', 'AdminController@deleteGroup');

            // Settings
            $r->get('/settings',  'AdminController@settings');
            $r->post('/settings', 'AdminController@saveSettings');

            // Federal data
            $r->get('/federal-data',          'AdminController@federalData');
            $r->post('/federal-data/refresh', 'AdminController@refreshFederalData');

            // Audit log
            $r->get('/audit-log', 'AdminController@auditLog');

            // SDS versions management (soft delete / restore)
            $r->get('/sds-versions',              'AdminController@sdsVersions');
            $r->post('/sds-versions/{id}/delete',  'AdminController@deleteSdsVersion');
            $r->post('/sds-versions/{id}/restore', 'AdminController@restoreSdsVersion');

            // Backup & Restore
            $r->get('/backups',                   'AdminController@backups');
            $r->post('/backups/create',           'AdminController@createBackup');
            $r->post('/backups/{id}/restore',     'AdminController@restoreBackup');
            $r->post('/backups/{id}/delete',      'AdminController@deleteBackup');
            $r->get('/backups/{id}/download',     'AdminController@downloadBackup');
            $r->post('/backups/ftp-settings',     'AdminController@saveFtpSettings');
            $r->post('/backups/ftp-test',         'AdminController@testFtpConnection');
            $r->post('/backups/{id}/ftp-upload',  'AdminController@uploadBackupToFtp');

            // Pictograms
            $r->get('/pictograms',               'AdminController@pictograms');
            $r->post('/pictograms/{code}/upload', 'AdminController@uploadPictogram');
            $r->post('/pictograms/{code}/delete', 'AdminController@deletePictogram');

            // Storage
            $r->get('/storage', 'AdminController@storage');

            // Network Settings
            $r->get('/network-settings',  'AdminController@networkSettings');
            $r->post('/network-settings', 'AdminController@saveNetworkSettings');

            // Training Data
            $r->get('/training-data',                'AdminController@trainingData');
            $r->post('/training-data/generate',      'AdminController@generateTrainingData');
            $r->get('/training-data/download/{type}', 'AdminController@downloadTrainingCsv');

            // Purge Data
            $r->get('/purge-data',  'AdminController@purgeData');
            $r->post('/purge-data', 'AdminController@executePurgeData');

            // SNUR List Management
            $r->get('/snur-list',              'AdminController@snurList');
            $r->post('/snur-list',             'AdminController@storeSnur');
            $r->post('/snur-list/{id}/delete', 'AdminController@deleteSnur');
        });

        // ── Dispatch ─────────────────────────────────────────────────
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri    = $_SERVER['REQUEST_URI']    ?? '/';

        $router->dispatch($method, $uri);
    }
}
