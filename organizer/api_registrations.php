<?php

declare(strict_types=1);
/**
 * API: List Registrations (Organizer)
 * 
 * Returns JSON list of registrations with optional filters.
 * Equivalent to Python's /organizer/api/registrations endpoint.
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

if (!isMethod('GET')) {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

try {
    $demoClassId = $_GET['demo_class_id'] ?? null;
    $whatsappConsent = isset($_GET['whatsapp_consent']) ? $_GET['whatsapp_consent'] : null;
    $search = $_GET['search'] ?? null;
    $status = $_GET['status'] ?? null;
    
    // Convert whatsapp_consent to boolean
    $whatsappConsentBool = null;
    if ($whatsappConsent === 'true') $whatsappConsentBool = true;
    elseif ($whatsappConsent === 'false') $whatsappConsentBool = false;
    
    $service = new RegistrationService();
    $registrations = $service->getRegistrations(
        demoClassId: $demoClassId,
        whatsappConsent: $whatsappConsentBool,
        search: $search,
        status: $status
    );
    
    $result = array_map(function($reg) {
        return [
            'id' => $reg['id'],
            'name' => $reg['registrant_name'],
            'email' => $reg['registrant_email'],
            'phone_number' => $reg['phone_number'],
            'whatsapp_consent' => (bool)$reg['consented_to_whatsapp'],
            'registration_status' => $reg['registration_status'],
            'class_title' => $reg['class_title'],
            'submitted_at' => $reg['submitted_at'],
        ];
    }, $registrations);
    
    jsonResponse($result);
    
} catch (Exception $e) {
    jsonResponse(['error' => 'Failed to fetch registrations'], 500);
}
