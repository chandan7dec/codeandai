<?php
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

// Verify API key
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? '';
if ($apiKey !== ORGANIZER_API_KEY) {
    jsonResponse(['error' => 'Invalid API key'], 403);
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
