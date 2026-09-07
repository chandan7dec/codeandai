<?php
/**
 * WhatsApp Status
 * 
 * Get WhatsApp redirect status for a registration.
 * Equivalent to Python's /whatsapp/status/{id} endpoint.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/whatsapp_service.php';

// Ensure DB is initialized
runStartup();

$registrationId = $_GET['id'] ?? null;

if (!$registrationId) {
    jsonResponse(['error' => 'Missing registration ID'], 400);
}

try {
    $service = new WhatsAppService();
    $status = $service->getRedirectStatus($registrationId);
    jsonResponse($status);
    
} catch (Exception $e) {
    if (DEBUG) {
        error_log("[WA] Failed to get WhatsApp status: " . $e->getMessage());
    }
    jsonResponse(['error' => 'Failed to retrieve WhatsApp status'], 500);
}
