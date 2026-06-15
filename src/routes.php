<?php

use ReportingEngine\Api\ConnectionController;
use ReportingEngine\Api\ImageController;
use ReportingEngine\Api\ReportController;
use ReportingEngine\Api\QueryController;
use ReportingEngine\Api\RenderController;
use ReportingEngine\Api\TemplateController;
use ReportingEngine\Api\FontController;

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
$router->get('/api/reports/{id}/export', [ReportController::class, 'export']);
$router->post('/api/reports/import', [ReportController::class, 'import']);

// Images
$router->get('/api/images', [ImageController::class, 'index']);
$router->post('/api/images/upload', [ImageController::class, 'upload']);
$router->get('/api/images/file/{guid}', [ImageController::class, 'file']);
$router->get('/api/images/{id}', [ImageController::class, 'show']);
$router->delete('/api/images/{id}', [ImageController::class, 'destroy']);

// Query
$router->post('/api/query/execute', [QueryController::class, 'execute']);
$router->post('/api/query/fields', [QueryController::class, 'fields']);
$router->post('/api/query/build', [QueryController::class, 'build']);
$router->get('/api/query/templates', [QueryController::class, 'templates']);
$router->post('/api/query/templates', [QueryController::class, 'storeTemplate']);

// Render
$router->get('/api/render/{id}', [RenderController::class, 'render']);
$router->post('/api/render/preview', [RenderController::class, 'preview']);

// Report Templates
$router->get('/api/report-templates', [TemplateController::class, 'index']);
$router->post('/api/report-templates', [TemplateController::class, 'store']);
$router->get('/api/report-templates/{id}', [TemplateController::class, 'show']);
$router->put('/api/report-templates/{id}', [TemplateController::class, 'update']);
$router->delete('/api/report-templates/{id}', [TemplateController::class, 'destroy']);

// Authentication
$router->post('/api/auth/login', [\ReportingEngine\Api\AuthController::class, 'login']);
$router->get('/api/auth/me', [\ReportingEngine\Api\AuthController::class, 'me']);
$router->post('/api/auth/logout', [\ReportingEngine\Api\AuthController::class, 'logout']);

// Fonts
$router->get('/api/fonts', [FontController::class, 'index']);
$router->post('/api/fonts/reload', [FontController::class, 'reload']);
$router->get('/api/fonts/file/{filename}', [FontController::class, 'file']);

// Settings
$router->get('/api/settings', [RenderController::class, 'settings']);
$router->put('/api/settings', [RenderController::class, 'updateSettings']);
