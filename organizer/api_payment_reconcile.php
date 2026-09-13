<?php

declare(strict_types=1);

/**
 * Payment Reconciliation API (admin)
 *
 * POST ?payment_id=... with { "status": "success" | "failed", "note": "..." }
 *
 * Manual override for missed UPI callbacks (US5 / quickstart scenario 8).
 * Only initiated/pending payments can be reconciled.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/upi_service.php';

runStartup();
sendSecurityHeaders();

if (!organizerAuthenticated()) {
    jsonResponse(['error' => 'Invalid API key. Log in at /login.php or pass api_key.'], 403);
}

if (!isMethod('POST')) {
    jsonResponse(['error' => 'Method not allowed. Use POST.'], 405);
}

$paymentId = trim((string)($_GET['payment_id'] ?? ''));
if ($paymentId === '') {
    jsonResponse(['error' => 'payment_id is required'], 400);
}

$body = getJsonBody();
$status = strtolower(trim((string)($body['status'] ?? '')));
$note = trim((string)($body['note'] ?? ''));

if (!in_array($status, ['success', 'failed'], true)) {
    jsonResponse(['error' => 'status must be success or failed'], 400);
}

try {
    $service = new UpiService();
    $payment = $service->reconcilePayment($paymentId, $status, $note !== '' ? $note : 'manual_reconciliation_by_admin');
    jsonResponse([
        'message' => 'Payment reconciled',
        'payment_id' => $paymentId,
        'status' => $payment['status'] ?? $status,
    ]);
} catch (OutOfBoundsException $e) {
    jsonResponse(['error' => $e->getMessage()], 404);
} catch (RuntimeException $e) {
    jsonResponse(['error' => $e->getMessage()], 409);
} catch (InvalidArgumentException $e) {
    jsonResponse(['error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    if (DEBUG) {
        error_log('[API] Reconciliation failed: ' . $e->getMessage());
    }
    jsonResponse(['error' => 'Reconciliation failed'], 500);
}
