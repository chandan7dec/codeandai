<?php
/**
 * WhatsApp Redirect
 * 
 * Handle WhatsApp redirect and record consent when user clicks join.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/whatsapp_service.php';

runStartup();

$registrationId = $_GET['id'] ?? null;

if (!$registrationId) {
    http_response_code(400);
    echo "<h1>400 Bad Request</h1><p>Missing registration ID.</p>";
    exit;
}

try {
    $conn = getDB();
    
    // Get registration using the abstraction layer
    if ($conn instanceof PDO) {
        $stmt = $conn->prepare("SELECT * FROM registrations WHERE id = ?");
        $stmt->execute([$registrationId]);
        $registration = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $stmt = $conn->prepare("SELECT * FROM registrations WHERE id = ?");
        $stmt->bind_param('s', $registrationId);
        $stmt->execute();
        $registration = $stmt->get_result()->fetch_assoc();
    }
    
    if (!$registration) {
        http_response_code(404);
        echo "<h1>404 Not Found</h1><p>Registration not found.</p>";
        exit;
    }
    
    // Get registrant
    if ($conn instanceof PDO) {
        $stmt = $conn->prepare("SELECT * FROM registrants WHERE id = ?");
        $stmt->execute([$registration['registrant_id']]);
        $registrant = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $stmt = $conn->prepare("SELECT * FROM registrants WHERE id = ?");
        $stmt->bind_param('s', $registration['registrant_id']);
        $stmt->execute();
        $registrant = $stmt->get_result()->fetch_assoc();
    }
    
    // Record consent if not already consented
    if (!$registrant['consented_to_whatsapp']) {
        $now = utcnow();
        
        if ($conn instanceof PDO) {
            $stmt = $conn->prepare("UPDATE registrants SET consented_to_whatsapp = 1, wa_consent_at = ?, updated_at = ? WHERE id = ?");
            $stmt->execute([$now, $now, $registrant['id']]);
        } else {
            $stmt = $conn->prepare("UPDATE registrants SET consented_to_whatsapp = 1, wa_consent_at = ?, updated_at = ? WHERE id = ?");
            $stmt->bind_param('sss', $now, $now, $registrant['id']);
            $stmt->execute();
        }
        
        // Record consent
        $consentId = generateUUID();
        if ($conn instanceof PDO) {
            $stmt = $conn->prepare("INSERT INTO whatsapp_consent_records (id, registration_id, action, consent_state, reason, recorded_at) VALUES (?, ?, 'consent', 1, 'User joined WhatsApp group', ?)");
            $stmt->execute([$consentId, $registrationId, $now]);
        } else {
            $stmt = $conn->prepare("INSERT INTO whatsapp_consent_records (id, registration_id, action, consent_state, reason, recorded_at) VALUES (?, ?, 'consent', 1, 'User joined WhatsApp group', ?)");
            $stmt->bind_param('sss', $consentId, $registrationId, $now);
            $stmt->execute();
        }
    }
    
    // Check if consent was withdrawn
    if ($registrant['wa_consent_withdrawn_at']) {
        http_response_code(404);
        echo "<h1>404 Not Found</h1><p>WhatsApp redirect not available for this registration.</p>";
        exit;
    }
    
    // Get or generate redirect URL
    $whatsappService = new WhatsAppService();
    $redirectUrl = $whatsappService->getRedirectUrl($registrationId);
    
    if ($redirectUrl) {
        $whatsappService->recordRedirectAttempt($registrationId, $redirectUrl);
        redirectTo($redirectUrl);
    } else {
        http_response_code(404);
        echo "<h1>404 Not Found</h1><p>WhatsApp redirect not available for this registration.</p>";
        exit;
    }
    
} catch (Exception $e) {
    if (DEBUG) error_log("[WA] WhatsApp redirect failed: " . $e->getMessage());
    
    try {
        $whatsappService = new WhatsAppService();
        $whatsappService->recordRedirectFailure($registrationId, $e->getMessage());
    } catch (Exception $ex) {}
    
    http_response_code(500);
    echo "<h1>500 Internal Server Error</h1><p>WhatsApp redirect failed. Please try again later.</p>";
}
