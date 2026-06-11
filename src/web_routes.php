<?php

use ReportingEngine\Core\Response;

$router->get('/login', function () {
    $config = \ReportingEngine\Core\Database::getConfig();
    $authEnabled = !empty(($config['auth'] ?? [])['enabled']);
    if (!$authEnabled) {
        return new Response('', 302, ['Location' => '/']);
    }
    return new Response(file_get_contents(__DIR__ . '/../views/auth/login.php'), 200,
        ['Content-Type' => 'text/html; charset=utf-8']
    );
});

// View routes
$router->get('/', function () {
    return Response::view('layout', ['content' => 'dashboard']);
});

$router->get('/reports', function () {
    return Response::view('layout', ['content' => 'reports/index']);
});

$router->get('/reports/new', function () {
    return Response::view('layout', [
        'content' => 'reports/templates',
    ]);
});

$router->get('/reports/designer/{id}', function ($request) {
    $config = \ReportingEngine\Core\Database::getConfig();
    $maxSize = (int)($config['max_upload_size'] ?? 1048576);
    $maxMb = $maxSize / 1048576;
    return Response::view('layout', [
        'content' => 'reports/designer',
        'reportId' => $request->getParam('id'),
        'maxUploadMb' => $maxMb,
        'extraCss' => ['/css/designer.css?v=2'],
        'extraScripts' => [
            '/js/designer/QueryEditor.js?v=2',
            '/js/designer/BorderEditor.js?v=2',
            '/js/designer/BandManager.js?v=2',
            '/js/designer/ElementEditor.js?v=2',
            '/js/designer/GroupEditor.js?v=2',
            '/js/designer/AggregateEditor.js?v=2',
            '/js/designer/DragDrop.js?v=2',
            '/js/designer/Designer.js?v=2',
            '/js/designer/ImagePicker.js?v=1',
        ],
    ]);
});

$router->get('/reports/preview/{id}', function ($request) {
    return Response::view('layout', ['content' => 'reports/preview', 'reportId' => $request->getParam('id')]);
});

$router->get('/connections', function () {
    return Response::view('layout', ['content' => 'connections/index']);
});

$router->get('/connections/edit/{id}', function ($request) {
    return Response::view('layout', ['content' => 'connections/edit', 'connectionId' => $request->getParam('id')]);
});

$router->get('/connections/new', function () {
    return Response::view('layout', ['content' => 'connections/edit', 'connectionId' => null]);
});

$router->get('/readme', function () {
    return Response::view('layout', ['content' => 'reports/readme']);
});

$router->get('/templates', function () {
    return Response::view('layout', ['content' => 'reports/template-list']);
});

$router->get('/templates/edit/{id}', function ($request) {
    return Response::view('layout', [
        'content' => 'reports/template-edit',
        'templateId' => $request->getParam('id'),
    ]);
});

$router->get('/settings', function () {
    return Response::view('layout', [
        'content' => 'settings/index',
        'extraScripts' => ['/js/settings.js'],
    ]);
});
