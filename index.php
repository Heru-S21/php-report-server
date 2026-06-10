<?php

if (php_sapi_name() === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $file = __DIR__ . $path;
    if ($path !== '/' && is_file($file)) {
        return false;
    }
}

require __DIR__ . '/vendor/autoload.php';

use ReportingEngine\Core\Router;
use ReportingEngine\Core\Request;
use ReportingEngine\Core\Response;
use ReportingEngine\Core\Database;

$config = require __DIR__ . '/config/app.php';

Database::init($config);

$request  = Request::fromGlobals();
$router   = new Router();

require __DIR__ . '/src/routes.php';
require __DIR__ . '/src/web_routes.php';

$response = $router->dispatch($request);
$response->send();
