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

$service = new ClassManagementService();

try {
    if (isMethod('GET')) {
        jsonResponse(['classes' => $service->getAllClasses()]);
    }
    if (isMethod('POST')) {
        $class = $service->create(getJsonBody());
        jsonResponse($class, 201);
    }
    if (isMethod('PUT') || isMethod('PATCH')) {
        $id = (string)($_GET['id'] ?? '');
        if ($id === '') jsonResponse(['error' => 'id is required'], 400);
        jsonResponse($service->update($id, getJsonBody()));
    }
    if (isMethod('DELETE')) {
        $id = (string)($_GET['id'] ?? '');
        if ($id === '') jsonResponse(['error' => 'id is required'], 400);
        $service->delete($id);
        jsonResponse(['message' => 'Class deleted successfully']);
    }
    jsonResponse(['error' => 'Method not allowed'], 405);
} catch (InvalidArgumentException $exception) {
    $fields = json_decode($exception->getMessage(), true);
    jsonResponse(['error' => 'Validation failed', 'fields' => is_array($fields) ? $fields : []], 400);
} catch (OutOfBoundsException $exception) {
    jsonResponse(['error' => $exception->getMessage()], 404);
} catch (RuntimeException $exception) {
    jsonResponse(['error' => $exception->getMessage()], 409);
} catch (Throwable $exception) {
    // Throwable (not just Exception) so TypeErrors etc. still produce a JSON
    // body — an empty 500 makes the dashboard's response.json() blow up with
    // "Unexpected end of JSON input" instead of a readable error.
    error_log('[API] Class management failed in ' . $exception->getFile() . ':' . $exception->getLine()
        . ' — ' . $exception->getMessage());
    // The organizer is authenticated, so the real cause (e.g. a MySQL
    // column/permission error) is safe to show — and far more useful than a
    // bare "Class management failed". Detail also always lands in the log.
    jsonResponse([
        'error' => 'Class management failed',
        'detail' => $exception->getMessage(),
        'at' => basename($exception->getFile()) . ':' . $exception->getLine(),
    ], 500);
}
