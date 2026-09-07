<?php
/**
 * Health Check Endpoint
 */
require_once __DIR__ . '/includes/db.php';

header('Content-Type: application/json');
echo json_encode([
    'status' => 'ok',
    'service' => APP_NAME,
]);
