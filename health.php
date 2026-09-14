<?php

declare(strict_types=1);
/**
 * Health Check & Deploy Diagnostic
 *
 * Visiting this page runs runStartup() — all migrations execute (idempotent)
 * — then reports DB driver, schema state and missing columns as JSON.
 *
 * Healthy response: {"status":"ok", ...,"database":{"driver":"mysql|sqlite","schema_ok":true}}
 * Schema problems:  "schema_ok":false + "missing_columns" lists exactly what
 * the auto-migrations could not create (e.g. ALTER privileges revoked).
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json');

$database = ['driver' => isSQLite() ? 'sqlite' : 'mysql', 'schema_ok' => false];
$status = 'ok';
$http = 200;

try {
    runStartup();

    $conn = getDB();
    $required = ['title', 'topic', 'trainer_name', 'scheduled_at', 'timezone', 'teams_link', 'status', 'registration_open', 'capacity', 'is_paid', 'price'];
    $present = [];

    if ($conn instanceof PDO) {
        foreach ($conn->query('PRAGMA table_info(demo_classes)') as $col) {
            $present[] = $col['name'];
        }
    } else {
        $result = $conn->query('SHOW COLUMNS FROM demo_classes');
        while ($result && ($col = $result->fetch_assoc())) {
            $present[] = $col['Field'];
        }
    }

    $missing = array_values(array_diff($required, $present));
    $database['missing_columns'] = $missing;
    $database['schema_ok'] = $missing === [];
    if ($missing !== []) {
        $status = 'degraded';
        $http = 500;
    }
} catch (Throwable $e) {
    $status = 'error';
    $http = 500;
    $database['error'] = $e->getMessage();
}

http_response_code($http);
echo json_encode([
    'status' => $status,
    'service' => APP_NAME,
    'time' => utcnow(),
    'database' => $database,
], JSON_PRETTY_PRINT);
