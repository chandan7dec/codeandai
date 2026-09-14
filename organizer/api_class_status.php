<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/class_management_service.php';

runStartup();
sendSecurityHeaders();

if (!organizerAuthenticated()) {
    jsonResponse(['error' => 'Invalid API key. Log in at /login.php or pass api_key.'], 403);
}
if (!isMethod('POST')) {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$id = (string)($_GET['id'] ?? '');
$body = getJsonBody();
$action = (string)($body['action'] ?? $_POST['action'] ?? '');
if ($id === '' || $action === '') {
    jsonResponse(['error' => 'id and action are required'], 400);
}

try {
    jsonResponse((new ClassManagementService())->transition($id, $action));
} catch (OutOfBoundsException $exception) {
    jsonResponse(['error' => $exception->getMessage()], 404);
} catch (RuntimeException $exception) {
    jsonResponse(['error' => $exception->getMessage()], 409);
} catch (Throwable $exception) {
    // Throwable so TypeErrors etc. still return JSON (empty bodies break response.json()).
    error_log('[API] Class status failed in ' . $exception->getFile() . ':' . $exception->getLine()
        . ' — ' . $exception->getMessage());
    jsonResponse([
        'error' => 'Class status update failed',
        'detail' => $exception->getMessage(),
        'at' => basename($exception->getFile()) . ':' . $exception->getLine(),
    ], 500);
}
