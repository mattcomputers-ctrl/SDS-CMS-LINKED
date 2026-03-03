<?php
/**
 * SDS System Configuration
 * Copy to config.php and edit values for your environment.
 */
return [
    'app' => [
        'name'      => 'SDS System',
        'url'       => 'http://localhost',
        'debug'     => false,
        'timezone'  => 'America/New_York',
        'version'   => '1.0.0',
    ],

    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'name'     => 'sds_system',
        'user'     => 'sds_user',
        'password' => 'CHANGE_ME',
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
        'lifetime' => 3600,   // 1 hour
        'name'     => 'SDS_SESSION',
    ],

    'upload' => [
        'max_size_mb'       => 20,
        'allowed_extensions' => ['pdf'],
        'allowed_mimetypes'  => ['application/pdf'],
    ],

    'company' => [
        'name'    => 'Your Ink Company, Inc.',
        'address' => '123 Industrial Blvd, Suite 100',
        'city'    => 'Anytown',
        'state'   => 'OH',
        'zip'     => '44000',
        'country' => 'US',
        'phone'   => '(555) 123-4567',
        'fax'     => '(555) 123-4568',
        'email'   => 'sds@yourinkcompany.com',
        'emergency_phone' => 'CHEMTREC: (800) 424-9300',
        'website' => 'https://www.yourinkcompany.com',
    ],

    'federal_data' => [
        'pubchem' => [
            'base_url'  => 'https://pubchem.ncbi.nlm.nih.gov/rest/pug',
            'view_url'  => 'https://pubchem.ncbi.nlm.nih.gov/rest/pug_view',
            'timeout'   => 30,
            'rate_limit_ms' => 200,  // PubChem asks for max 5 req/sec
        ],
        'niosh' => [
            'base_url' => 'https://www.cdc.gov/niosh/npg',
            'timeout'  => 30,
        ],
        'epa' => [
            'enabled' => true,
        ],
        'dot' => [
            'enabled' => true,
        ],
    ],

    'sds' => [
        'default_language'       => 'en',
        'supported_languages'    => ['en', 'es', 'fr', 'de'],
        'block_publish_missing'  => true,    // Block if federal hazard data missing
        'missing_threshold_pct'  => 1.0,     // Block if constituent >= this % lacks data
        'voc_calc_mode'          => 'method24_standard',
    ],

    'cron' => [
        'federal_refresh_hours' => 168,  // Weekly
        'sara_refresh_hours'    => 168,
        'log_retention_days'    => 365,
    ],
];
