<?php

use ReportingEngine\Core\Response;

// View routes
$router->get('/', function () {
    return Response::view('layout', ['content' => 'dashboard']);
});

$router->get('/reports', function () {
    return Response::view('layout', ['content' => 'reports/index']);
});

$router->get('/reports/new', function () {
    return Response::view('layout', [
        'content' => 'reports/designer',
        'reportId' => null,
        'extraCss' => ['/css/designer.css'],
        'extraScripts' => [
            '/js/designer/QueryEditor.js',
            '/js/designer/BorderEditor.js',
            '/js/designer/BandManager.js',
            '/js/designer/ElementEditor.js',
            '/js/designer/GroupEditor.js',
            '/js/designer/AggregateEditor.js',
            '/js/designer/DragDrop.js',
            '/js/designer/Designer.js',
        ],
    ]);
});

$router->get('/reports/designer/{id}', function ($request) {
    return Response::view('layout', [
        'content' => 'reports/designer',
        'reportId' => $request->getParam('id'),
        'extraCss' => ['/css/designer.css'],
        'extraScripts' => [
            '/js/designer/QueryEditor.js',
            '/js/designer/BorderEditor.js',
            '/js/designer/BandManager.js',
            '/js/designer/ElementEditor.js',
            '/js/designer/GroupEditor.js',
            '/js/designer/AggregateEditor.js',
            '/js/designer/DragDrop.js',
            '/js/designer/Designer.js',
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
