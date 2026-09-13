<?php

declare(strict_types=1);
/**
 * API: Delete Registration
 * 
 * Deletes a registration and all related records.
 * Equivalent to Python's DELETE /organizer/api/registrations/{id} endpoint.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/registration_service.php';

// Ensure DB is initialized
runStartup();
sendSecurityHeaders();

// Verify access: session login OR API key (constant-time compare inside).
if (!organizerAuthenticated()) {
    jsonResponse(['error' => 'Invalid API key. Log in at /login.php or pass api_key.'], 403);
}

if (!isMethod('DELETE') && !isMethod('POST')) {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

// Extract registration ID from query string
$registrationId = $_GET['registration_id'] ?? null;
if (!$registrationId) {
    jsonResponse(['error' => 'registration_id is required'], 400);
}

try {
    $service = new RegistrationService();
    $success = $service->deleteRegistration($registrationId);
    
    if ($success) {
        jsonResponse(['message' => 'Registration deleted successfully']);
    } else {
        jsonResponse(['error' => 'Registration not found'], 404);
    }
    
} catch (Exception $e) {
    if (DEBUG) {
        error_log("[API] Failed to delete registration: " . $e->getMessage());
    }
    jsonResponse(['error' => 'Failed to delete registration'], 500);
}
