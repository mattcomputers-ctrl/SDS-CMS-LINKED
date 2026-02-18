<?php
/**
 * SDS System — Front Controller
 * All requests are routed through this file via .htaccess
 */

// Autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Bootstrap application
$app = new SDS\Core\App();
$app->run();
