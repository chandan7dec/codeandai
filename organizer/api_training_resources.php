<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/training_resource_service.php';

runStartup();
sendSecurityHeaders();

if (!organizerAuthenticated()) {
    jsonResponse(['error' => 'Invalid API key. Log in at /login.php or pass api_key.'], 403);
}

$service = new TrainingResourceService();

try {
    if (isMethod('GET')) {
        $classId = trim((string)($_GET['class_id'] ?? ''));
        $resources = $service->getAllWithClass();
        if ($classId !== '') {
            $resources = array_values(array_filter(
                $resources,
                static fn (array $r): bool => (string)$r['class_id'] === $classId
            ));
        }
        jsonResponse(['resources' => $resources]);
    }

    if (isMethod('POST')) {
        // POST ?action=publish {"id": "...", "published": true|false} → toggle visibility
        if (($_GET['action'] ?? '') === 'publish') {
            $body = getJsonBody();
            $id = (string)($body['id'] ?? '');
            if ($id === '') {
                jsonResponse(['error' => 'id is required'], 400);
            }
            jsonResponse([
                'message' => ((bool)($body['published'] ?? false)) ? 'Resource published' : 'Resource unpublished',
                'resource' => $service->setPublished($id, (bool)($body['published'] ?? false)),
            ]);
        }
        $resource = $service->addResource(getJsonBody());
        jsonResponse(['message' => 'Resource added', 'resource' => $resource], 201);
    }

    if (isMethod('PUT') || isMethod('PATCH')) {
        $id = (string)($_GET['id'] ?? '');
        if ($id === '') {
            jsonResponse(['error' => 'id is required'], 400);
        }
        $resource = $service->updateResource($id, getJsonBody());
        jsonResponse(['message' => 'Resource updated', 'resource' => $resource]);
    }

    if (isMethod('DELETE')) {
        $id = (string)($_GET['id'] ?? '');
        if ($id === '') {
            jsonResponse(['error' => 'id is required'], 400);
        }
        $service->deleteResource($id);
        jsonResponse(['message' => 'Resource deleted successfully']);
    }

    jsonResponse(['error' => 'Method not allowed'], 405);
} catch (InvalidArgumentException $exception) {
    $fields = json_decode($exception->getMessage(), true);
    jsonResponse(['error' => 'Validation failed', 'fields' => is_array($fields) ? $fields : []], 400);
} catch (OutOfBoundsException $exception) {
    jsonResponse(['error' => $exception->getMessage()], 404);
} catch (Throwable $exception) {
    if (DEBUG) {
        error_log('[API] Training resources failed: ' . $exception->getMessage());
    }
    jsonResponse(['error' => 'Training resources request failed'], 500);
}
