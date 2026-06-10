<?php
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $uri;
if ($uri !== '/' && file_exists($file)) {
    header('X-Router-Static: true');
    return false;
}
header('X-Router-Passthrough: ' . $uri);
require __DIR__ . '/index.php';
