<?php

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/class_management_service.php';

runStartup();

$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? '';
if ($apiKey !== ORGANIZER_API_KEY) {
    jsonResponse(['error' => 'Invalid API key'], 403);
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
} catch (Exception $exception) {
    if (DEBUG) error_log('[API] Class status failed: ' . $exception->getMessage());
    jsonResponse(['error' => 'Class status update failed'], 500);
}
