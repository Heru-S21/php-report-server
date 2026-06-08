<?php

use ReportingEngine\Api\ConnectionController;
use ReportingEngine\Api\ReportController;
use ReportingEngine\Api\QueryController;
use ReportingEngine\Api\RenderController;

// Connections
$router->get('/api/connections', [ConnectionController::class, 'index']);
$router->post('/api/connections', [ConnectionController::class, 'store']);
$router->get('/api/connections/{id}', [ConnectionController::class, 'show']);
$router->put('/api/connections/{id}', [ConnectionController::class, 'update']);
$router->delete('/api/connections/{id}', [ConnectionController::class, 'destroy']);
$router->post('/api/connections/{id}/test', [ConnectionController::class, 'test']);
$router->get('/api/connections/{id}/tables', [ConnectionController::class, 'tables']);
$router->get('/api/connections/{id}/tables/{table}/columns', [ConnectionController::class, 'columns']);
$router->get('/api/connections/{id}/table-columns', [ConnectionController::class, 'columns']);

// Reports
$router->get('/api/reports', [ReportController::class, 'index']);
$router->post('/api/reports', [ReportController::class, 'store']);
$router->get('/api/reports/{id}', [ReportController::class, 'show']);
$router->put('/api/reports/{id}', [ReportController::class, 'update']);
$router->delete('/api/reports/{id}', [ReportController::class, 'destroy']);
$router->post('/api/reports/{id}/duplicate', [ReportController::class, 'duplicate']);

// Query
$router->post('/api/query/execute', [QueryController::class, 'execute']);
$router->post('/api/query/fields', [QueryController::class, 'fields']);
$router->post('/api/query/build', [QueryController::class, 'build']);
$router->get('/api/query/templates', [QueryController::class, 'templates']);
$router->post('/api/query/templates', [QueryController::class, 'storeTemplate']);

// Render
$router->get('/api/render/{id}', [RenderController::class, 'render']);
$router->post('/api/render/preview', [RenderController::class, 'preview']);

// Settings
$router->get('/api/settings', [RenderController::class, 'settings']);
$router->put('/api/settings', [RenderController::class, 'updateSettings']);
