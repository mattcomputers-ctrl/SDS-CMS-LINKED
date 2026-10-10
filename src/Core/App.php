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

        // Timezone — config default until DB is available
        date_default_timezone_set(self::config('app.timezone', 'America/Chicago'));

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

        // Override timezone from DB setting if present; MySQL runs in UTC (audit #59)
        try {
            $tzRow = self::$database->fetch("SELECT `value` FROM settings WHERE `key` = 'app.timezone'");
            if ($tzRow && !empty($tzRow['value'])) {
                date_default_timezone_set($tzRow['value']);
            }
            // Audit #59 — one clock: MySQL writes (CURRENT_TIMESTAMP, ON UPDATE
            // CURRENT_TIMESTAMP, NOW()) are UTC, matching the UTC_TIMESTAMP()
            // staleness bumps and the publishers' PublishClock::nowUtc()
            // published_at. PHP date() stays in the admin time zone for printed
            // effective dates; stored datetimes are shown via PublishClock::display().
            self::$database->getPdo()->exec("SET time_zone = '" . \SDS\Services\PublishClock::DB_TIME_ZONE . "'");
        } catch (\Throwable $e) {
            // Non-fatal — fall back to PHP-only timezone
        }

        // Initialise and start session. The PHP session/cookie lifetime
        // must be at least as long as the admin-configured idle-logout
        // window (plus a margin), otherwise PHP GC or cookie expiry would
        // end an idle session before AuthMiddleware's timeout fires and
        // the user would be logged out early with no "timed out" notice.
        $sessionConfig = self::$config['session'] ?? [];
        $sessionConfig['lifetime'] = max(
            (int) ($sessionConfig['lifetime'] ?? 3600),
            Session::configuredIdleTimeout() + 300
        );
        self::$session = new Session();
        self::$session->start($sessionConfig);
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
        // Client-side idle timer pings this to keep the server-side
        // activity window in step (see public/js/session-timeout.js).
        $router->post('/auth/heartbeat', 'AuthController@heartbeat');

        // ── Public RM SDS Book (no login required) ─────────────────
        $router->get('/rm-sds-book', 'SDSBookController@publicIndex');

        // ── Dashboard ────────────────────────────────────────────────
        $router->get('/', 'DashboardController@index');

        // ── SDS Lookup (read-only search / download) ─────────────────
        $router->get('/lookup',              'LookupController@index');
        $router->get('/lookup/search',       'LookupController@search');
        $router->get('/lookup/download/{id}', 'LookupController@download');

        // ── SDS Review (per-FG readiness report) ─────────────────────
        $router->get('/sds-review',          'SDSReviewController@index');

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
        $router->post('/reports/generate',       'ReportController@generate');
        $router->post('/reports/generate-pdf',   'ReportController@generatePdf');
        $router->post('/reports/prop65',         'ReportController@prop65');
        $router->post('/reports/prop65-pdf',     'ReportController@prop65Pdf');
        $router->post('/reports/order-history',  'ReportController@orderHistory');
        $router->post('/reports/ross',           'ReportController@ross');
        $router->post('/reports/ross-pdf',       'ReportController@rossPdf');
        $router->post('/reports/export-sds',     'ReportController@exportShippedSds');
        $router->get('/reports/customers',       'ReportController@customers');

        // ── Product Aliases (read-only — synced from CMS) ───────────────
        $router->get('/aliases',                 'AliasController@index');

        // ── SDS Book (plant lookup) ────────────────────────────────────
        $router->get('/sds-book',                         'SDSBookController@index');
        $router->post('/sds-book/delete-supplier/{id}',   'SDSBookController@deleteSupplierSds');
        $router->post('/sds-book/delete-fg/{id}',         'SDSBookController@deleteFgSds');
        $router->get('/sds-book/export',                  'ExportController@exportAllFgSds');

        // ── Finished Goods ───────────────────────────────────────────
        $router->get('/finished-goods',            'FinishedGoodController@index');
        $router->get('/finished-goods/create',     'FinishedGoodController@create');
        $router->post('/finished-goods',           'FinishedGoodController@store');
        $router->get('/finished-goods/component-lookup', 'FinishedGoodController@componentLookup');
        $router->get('/finished-goods/{id}/edit',  'FinishedGoodController@edit');
        $router->post('/finished-goods/{id}',      'FinishedGoodController@update');
        $router->post('/finished-goods/{id}/hazard-override', 'FinishedGoodController@saveHazardOverride');

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
        $router->get('/sds/resale/{rm_id}/preview',         'SDSController@previewResale');
        $router->post('/sds/resale/{rm_id}/publish',        'SDSController@publishResale');
        $router->get('/sds/resale/{rm_id}/edit',            'SDSController@editResale');       // audit #45
        $router->post('/sds/resale/{rm_id}/save-edits',     'SDSController@saveResaleEdits');  // audit #45
        $router->get('/sds/{finished_good_id}/edit',        'SDSController@edit');
        $router->post('/sds/{finished_good_id}/save-edits', 'SDSController@saveEdits');
        $router->post('/sds/{finished_good_id}/publish',    'SDSController@publish');
        $router->get('/sds/version/{id}/download',          'SDSController@download');
        $router->get('/sds/version/{id}/trace',             'SDSController@trace');

        // ── CAS Determinations (permission-gated) ────────────────────
        $router->get('/determinations',              'AdminController@determinations');
        $router->get('/determinations/create',       'AdminController@createDetermination');
        $router->post('/determinations',             'AdminController@storeDetermination');
        $router->post('/determinations/descriptions', 'AdminController@saveCasDescription');
        // ── Audit #19: Section 10 element flags on cas_master (same page key; literal path must precede /determinations/{id}) ──
        $router->post('/determinations/element-flags', 'AdminController@saveCasElementFlags');
        $router->get('/determinations/element-flags',        'AdminController@elementFlagsSeedPreview'); // seed dry run (CasElementFlagSeeder::plan)
        $router->post('/determinations/element-flags/apply', 'AdminController@applyElementFlagsSeed');   // seed apply (CasElementFlagSeeder::apply)
        // ── Audit #12: RCRA TC metal flags on cas_master (Section 13 D004-D011 for metal compounds) ──
        $router->post('/determinations/tc-metals',       'AdminController@saveCasTcMetals');     // manual edit (CAS Descriptions)
        $router->get('/determinations/tc-metals',        'AdminController@tcMetalsSeedPreview'); // seed dry run (CasTcMetalSeeder::plan)
        $router->post('/determinations/tc-metals/apply', 'AdminController@applyTcMetalsSeed');   // seed apply (CasTcMetalSeeder::apply)
        $router->post('/determinations/tsca',         'AdminController@saveTscaOverride'); // audit #29 per-CAS TSCA override
        $router->get('/determinations/{id}/edit',    'AdminController@editDetermination');
        $router->post('/determinations/{id}',        'AdminController@updateDetermination');

        // ── Exempt VOC Library (permission-gated) ───────────────────
        $router->get('/exempt-vocs',              'AdminController@exemptVocs');
        $router->get('/exempt-vocs/create',       'AdminController@createExemptVoc');
        $router->post('/exempt-vocs',             'AdminController@storeExemptVoc');
        $router->get('/exempt-vocs/{id}/edit',    'AdminController@editExemptVoc');
        $router->post('/exempt-vocs/{id}',        'AdminController@updateExemptVoc');
        $router->post('/exempt-vocs/{id}/delete', 'AdminController@deleteExemptVoc');

        // ── California Prop 65 List (permission-gated) ──────────────
        $router->get('/prop65',              'AdminController@prop65');
        $router->get('/prop65/create',       'AdminController@createProp65');
        $router->post('/prop65',             'AdminController@storeProp65');
        $router->get('/prop65/{id}/edit',    'AdminController@editProp65');
        $router->post('/prop65/{id}',        'AdminController@updateProp65');
        $router->post('/prop65/{id}/delete', 'AdminController@deleteProp65');

        // ── EPA HAP List (permission-gated) ─────────────────────────
        $router->get('/haps',              'AdminController@haps');
        $router->get('/haps/create',       'AdminController@createHap');
        $router->post('/haps',             'AdminController@storeHap');
        $router->get('/haps/{id}/edit',    'AdminController@editHap');
        $router->post('/haps/{id}',        'AdminController@updateHap');
        $router->post('/haps/{id}/delete', 'AdminController@deleteHap');

        // ── TSCA Inventory (permission-gated, audit #29) ────────────
        $router->get('/tsca',                 'AdminController@tsca');
        $router->get('/tsca/create',          'AdminController@createTsca');
        $router->post('/tsca',                'AdminController@storeTsca');
        $router->post('/tsca/import',         'AdminController@importTsca');        // literal routes before {cas}
        $router->post('/tsca/import/apply',   'AdminController@applyTscaImport');
        $router->post('/tsca/import/discard', 'AdminController@discardTscaImport');
        $router->get('/tsca/{cas}/edit',      'AdminController@editTsca');
        $router->post('/tsca/{cas}',          'AdminController@updateTsca');
        $router->post('/tsca/{cas}/delete',   'AdminController@deleteTsca');

        // ── EPA RCRA Waste Codes (permission-gated; audit #26, T3) ───
        $router->get('/rcra',              'AdminController@rcra');
        $router->get('/rcra/create',       'AdminController@createRcra');
        $router->post('/rcra',             'AdminController@storeRcra');
        $router->get('/rcra/{id}/edit',    'AdminController@editRcra');
        $router->post('/rcra/{id}',        'AdminController@updateRcra');
        $router->post('/rcra/{id}/delete', 'AdminController@deleteRcra');

        // ── Bulk SDS Publish (permission-gated) ─────────────────────
        $router->get('/bulk-publish',                           'BulkPublishController@page');
        $router->post('/bulk-publish/start',                    'BulkPublishController@start');
        $router->get('/bulk-publish/progress/{token}',          'BulkPublishController@progress');
        $router->post('/bulk-publish/stop/{token}',             'BulkPublishController@stop');
        // ── Queue management ───────────────────────────────────────
        $router->get('/bulk-publish/queue',                     'BulkPublishController@queue');
        $router->get('/bulk-publish/queue/{id}/status',         'BulkPublishController@queueStatus');
        $router->post('/bulk-publish/queue/{id}/dismiss',       'BulkPublishController@dismissQueued');
        $router->post('/bulk-publish/queue/{id}/force-fail',    'BulkPublishController@forceFailQueued');

        // ── Manufacturers ────────────────────────────────────────
        $router->get('/manufacturers',                     'ManufacturerController@index');
        $router->get('/manufacturers/create',              'ManufacturerController@create');
        $router->post('/manufacturers',                    'ManufacturerController@store');
        $router->get('/manufacturers/{id}/edit',           'ManufacturerController@edit');
        $router->post('/manufacturers/{id}',               'ManufacturerController@update');
        $router->post('/manufacturers/{id}/delete',        'ManufacturerController@delete');

        // ── Customers ────────────────────────────────────────────
        $router->get('/customers',                'CustomerController@index');
        $router->get('/customers/create',         'CustomerController@create');
        $router->post('/customers',               'CustomerController@store');
        $router->get('/customers/{id}/edit',      'CustomerController@edit');
        $router->post('/customers/{id}',          'CustomerController@update');
        $router->post('/customers/{id}/delete',   'CustomerController@delete');
        $router->post('/customers/{id}/send-orders', 'CustomerController@sendForOrders');

        // ── SDS Send Queue ──────────────────────────────────────
        $router->get('/sds-send-queue',                'SDSSendQueueController@index');
        $router->post('/sds-send-queue/{id}/send',     'SDSSendQueueController@send');
        $router->post('/sds-send-queue/send-customer', 'SDSSendQueueController@sendForCustomer');
        $router->post('/sds-send-queue/{id}/dismiss',  'SDSSendQueueController@dismiss');

        // ── Stale RM SDS ──────────────────────────────────────
        $router->get('/stale-rm-sds',                                   'StaleRmSdsController@index');
        $router->post('/raw-materials/{id}/confirm-sds-current',        'RawMaterialController@confirmSdsCurrent');
        // Per-supplier variant: confirms ONE raw_material_sds row (the RM
        // edit page renders a button per supplier's current SDS).
        $router->post('/raw-materials/sds-version/{id}/confirm-current', 'RawMaterialController@confirmSdsVersionCurrent');

        // ── SDS Update Required ─────────────────────────────────
        $router->get('/sds-updates',                           'SDSUpdateController@index');
        $router->post('/sds-updates/scan',                     'SDSUpdateController@scan');
        $router->post('/sds-updates/republish',                'SDSUpdateController@republish');
        $router->post('/sds-updates/republish-private-label',  'SDSUpdateController@republishPrivateLabel');
        $router->post('/sds-updates/dismiss',                  'SDSUpdateController@dismiss');

        // ── Private Label SDS ────────────────────────────────────
        // Literal segments are registered before the {id} routes.
        $router->get('/private-label',                                    'PrivateLabelController@index');
        $router->get('/private-label/documents',                          'PrivateLabelController@documents');
        $router->get('/private-label/create',                             'PrivateLabelController@legacyCreate');
        $router->get('/private-label/aliases-for-fg',                     'PrivateLabelController@aliasesForFg');
        $router->get('/private-label/live-preview',                       'PrivateLabelController@livePreview');
        $router->get('/private-label/manufacturer/{id}',                  'PrivateLabelController@manufacturer');
        $router->get('/private-label/manufacturer/{id}/items/create',     'PrivateLabelController@createItem');
        $router->post('/private-label/manufacturer/{id}/items',           'PrivateLabelController@storeItem');
        $router->post('/private-label/manufacturer/{id}/republish-stale', 'PrivateLabelController@republishStale');
        $router->get('/private-label/items/{id}/edit',                    'PrivateLabelController@editItem');
        $router->post('/private-label/items/{id}',                        'PrivateLabelController@updateItem');
        $router->post('/private-label/items/{id}/publish',                'PrivateLabelController@publishItem');
        $router->post('/private-label/items/{id}/retire',                 'PrivateLabelController@retireItem');
        $router->post('/private-label/items/{id}/delete',                 'PrivateLabelController@deleteItem');
        $router->get('/private-label/items/{id}/history',                 'PrivateLabelController@itemHistory');
        $router->get('/private-label/{id}/download',                      'PrivateLabelController@download');
        $router->get('/private-label/{id}/preview',                       'PrivateLabelController@preview');

        // ── CMS Import ───────────────────────────────────────────
        $router->get('/cms-import',              'CMSImportController@index');
        $router->post('/cms-import/preview',     'CMSImportController@preview');
        $router->post('/cms-import/import',      'CMSImportController@import');
        $router->post('/cms-import/full-sync',   'CMSImportController@fullSync');
        $router->get('/cms-import/incomplete',   'CMSImportController@incomplete');

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
        $router->post('/label-templates/{id}/set-default', 'LabelTemplateController@setDefault');

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
            $r->post('/settings/bump-inhalation-cas', 'AdminController@bumpInhalationCas');
            $r->post('/settings/bump-all-sds', 'AdminController@bumpAllUnblockedSds');

            // ── Product Families (SDS content audit #3, track T1) ────────
            // Literal paths before {id} — the router takes the first regex match.
            $r->get('/product-families',                              'AdminController@productFamilies');
            $r->get('/product-families/create',                       'AdminController@createProductFamily');
            $r->get('/product-families/recompute',                    'AdminController@recomputeProductFamilies');
            $r->post('/product-families/recompute',                   'AdminController@applyProductFamilies');
            $r->post('/product-families/reset-legacy-manual',         'AdminController@resetLegacyFamilyPicks');
            $r->post('/product-families',                             'AdminController@storeProductFamily');
            $r->get('/product-families/{id}/edit',                    'AdminController@editProductFamily');
            $r->post('/product-families/{id}',                        'AdminController@updateProductFamily');
            $r->post('/product-families/{id}/delete',                 'AdminController@deleteProductFamily');
            $r->post('/product-families/{id}/rules',                  'AdminController@storeProductFamilyRule');
            $r->post('/product-families/{id}/rules/{rule_id}/delete', 'AdminController@deleteProductFamilyRule');

            // Federal data
            $r->get('/federal-data',          'AdminController@federalData');
            $r->post('/federal-data/refresh', 'AdminController@refreshFederalData');

            // ECHA CLP Annex VI M-factor import (Phase 4 aquatic data)
            $r->get('/echa-import',           'AdminController@echaImport');
            $r->post('/echa-import/upload',   'AdminController@uploadEchaCsv');

            // Audit log
            $r->get('/audit-log', 'AdminController@auditLog');

            // SDS versions management (soft delete / restore)
            $r->get('/sds-versions',              'AdminController@sdsVersions');
            $r->post('/sds-versions/{id}/delete',  'AdminController@deleteSdsVersion');
            $r->post('/sds-versions/{id}/restore', 'AdminController@restoreSdsVersion');

            // Backup & Restore
            $r->get('/backups',                   'AdminController@backups');
            $r->post('/backups/create',           'AdminController@createBackup');
            $r->post('/backups/upload',           'AdminController@uploadBackup');
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

            // SARA 313 List Management
            $r->get('/sara313',              'AdminController@sara313List');
            $r->get('/sara313/create',       'AdminController@createSara313');
            $r->post('/sara313',             'AdminController@storeSara313');
            $r->post('/sara313/import',      'AdminController@importSara313'); // literal route must precede {id} catch-all
            $r->get('/sara313/{id}/edit',    'AdminController@editSara313');
            $r->post('/sara313/{id}',        'AdminController@updateSara313');
            $r->post('/sara313/{id}/delete', 'AdminController@deleteSara313');

            // SNUR List Management
            $r->get('/snur-list',              'AdminController@snurList');
            $r->post('/snur-list',             'AdminController@storeSnur');
            $r->post('/snur-list/{id}/delete', 'AdminController@deleteSnur');

            // SDS PDF Archive
            $r->get('/sds-archive',                    'SdsArchiveController@index');
            $r->post('/sds-archive/generate',          'SdsArchiveController@generate');
            $r->get('/sds-archive/download/{file}',    'SdsArchiveController@download');
            $r->post('/sds-archive/purge',             'SdsArchiveController@purge');
            $r->post('/sds-archive/delete-zip/{file}', 'SdsArchiveController@deleteZip');
        });

        // ── Dispatch ─────────────────────────────────────────────────
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri    = $_SERVER['REQUEST_URI']    ?? '/';

        $router->dispatch($method, $uri);
    }
}
