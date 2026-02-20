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

        // ── Dashboard ────────────────────────────────────────────────
        $router->get('/', 'DashboardController@index');

        // ── SDS Lookup (read-only search / download) ─────────────────
        $router->get('/lookup',              'LookupController@index');
        $router->get('/lookup/search',       'LookupController@search');
        $router->get('/lookup/download/{id}', 'LookupController@download');

        // ── Raw Materials ────────────────────────────────────────────
        $router->get('/raw-materials',                    'RawMaterialController@index');
        $router->get('/raw-materials/create',             'RawMaterialController@create');
        $router->post('/raw-materials',                   'RawMaterialController@store');
        $router->get('/raw-materials/{id}/edit',          'RawMaterialController@edit');
        $router->post('/raw-materials/{id}',              'RawMaterialController@update');
        $router->post('/raw-materials/{id}/delete',       'RawMaterialController@delete');
        $router->get('/raw-materials/{id}/sds',             'RawMaterialController@viewSds');
        $router->get('/raw-materials/{id}/constituents',  'RawMaterialController@constituents');
        $router->post('/raw-materials/{id}/constituents', 'RawMaterialController@saveConstituents');

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

        // ── Admin routes (grouped under /admin) ──────────────────────
        $router->group('/admin', function (Router $r) {
            // Users
            $r->get('/users',            'AdminController@users');
            $r->get('/users/create',     'AdminController@createUser');
            $r->post('/users',           'AdminController@storeUser');
            $r->get('/users/{id}/edit',  'AdminController@editUser');
            $r->post('/users/{id}',      'AdminController@updateUser');

            // Settings
            $r->get('/settings',  'AdminController@settings');
            $r->post('/settings', 'AdminController@saveSettings');

            // Exempt VOC library
            $r->get('/exempt-vocs',              'AdminController@exemptVocs');
            $r->get('/exempt-vocs/create',       'AdminController@createExemptVoc');
            $r->post('/exempt-vocs',             'AdminController@storeExemptVoc');
            $r->get('/exempt-vocs/{id}/edit',    'AdminController@editExemptVoc');
            $r->post('/exempt-vocs/{id}',        'AdminController@updateExemptVoc');
            $r->post('/exempt-vocs/{id}/delete', 'AdminController@deleteExemptVoc');

            // Competent person determinations
            $r->get('/determinations',              'AdminController@determinations');
            $r->get('/determinations/create',       'AdminController@createDetermination');
            $r->post('/determinations',             'AdminController@storeDetermination');
            $r->get('/determinations/{id}/edit',    'AdminController@editDetermination');
            $r->post('/determinations/{id}',        'AdminController@updateDetermination');

            // Federal data
            $r->get('/federal-data',          'AdminController@federalData');
            $r->post('/federal-data/refresh', 'AdminController@refreshFederalData');

            // Audit log
            $r->get('/audit-log', 'AdminController@auditLog');

            // SDS versions management (soft delete / restore)
            $r->get('/sds-versions',              'AdminController@sdsVersions');
            $r->post('/sds-versions/{id}/delete',  'AdminController@deleteSdsVersion');
            $r->post('/sds-versions/{id}/restore', 'AdminController@restoreSdsVersion');
        });

        // ── Dispatch ─────────────────────────────────────────────────
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri    = $_SERVER['REQUEST_URI']    ?? '/';

        $router->dispatch($method, $uri);
    }
}
