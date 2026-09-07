<?php
/**
 * API: Record Follow-up Action
 * 
 * Records a manual follow-up action for a registration.
 * Equivalent to Python's /organizer/api/registrations/{id}/follow-up endpoint.
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

if (!isMethod('POST')) {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

// Extract registration ID from query string
$registrationId = $_GET['registration_id'] ?? null;
if (!$registrationId) {
    jsonResponse(['error' => 'registration_id is required'], 400);
}

// Get form data
$followUpType = $_POST['follow_up_type'] ?? null;
$outcome = $_POST['outcome'] ?? 'pending';
$notes = $_POST['notes'] ?? null;

if (!$followUpType) {
    jsonResponse(['error' => 'follow_up_type is required'], 400);
}

try {
    $service = new RegistrationService();
    $result = $service->recordFollowUp($registrationId, $followUpType, $outcome, $notes);
    jsonResponse($result);
    
} catch (InvalidArgumentException $e) {
    jsonResponse(['error' => $e->getMessage()], 400);
    
} catch (Exception $e) {
    if (DEBUG) {
        error_log("[API] Failed to record follow-up: " . $e->getMessage());
    }
    jsonResponse(['error' => 'Failed to record follow-up'], 500);
}
