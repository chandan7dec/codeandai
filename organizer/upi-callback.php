<?php

declare(strict_types=1);

/**
 * UPI Payment Callback Endpoint
 *
 * Receives POST callbacks from the UPI network/bank:
 *  - verifies the HMAC-SHA256 signature (reject tampered payloads)
 *  - applies the callback with idempotency (duplicate txnId / final states)
 *  - updates payment + registration status inside a transaction
 *
 * See contract: specs/001-paid-classes-upi/contracts/upi-payment-callback.json
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/upi_service.php';

// ── Simple rate limiting (per client IP, file-based sliding window) ──
// Callbacks are low-volume; 60 requests/minute per IP is generous and stops
// trivial flooding. Storage lives in the writable data/ directory.
function upiCallbackRateLimit(string $ip, int $limit = 60, int $windowSeconds = 60): bool
{
    $dir = __DIR__ . '/../data/rate-limit';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $file = $dir . '/' . hash('sha256', $ip) . '.json';
    $now = time();
    $hits = [];
    if (is_file($file)) {
        $hits = json_decode((string)file_get_contents($file), true) ?: [];
    }
    $hits = array_values(array_filter($hits, static fn ($t): bool => is_int($t) || is_numeric($t) ? (int)$t > $now - $windowSeconds : false));
    if (count($hits) >= $limit) {
        return false;
    }
    $hits[] = $now;
    file_put_contents($file, json_encode($hits), LOCK_EX);
    return true;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    jsonResponse(['error' => 'Method not allowed. Use POST.'], 405);
}

$clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (!upiCallbackRateLimit((string)$clientIp)) {
    if (DEBUG) {
        error_log('[UPI-CALLBACK] Rate limit exceeded for ' . $clientIp);
    }
    jsonResponse(['error' => 'Too many requests.'], 429);
}

// ── Parse payload (JSON body preferred, form-encoded tolerated) ──
$contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
if (str_contains($contentType, 'application/json')) {
    $payload = getJsonBody();
} else {
    $raw = file_get_contents('php://input');
    $decoded = json_decode((string)$raw, true);
    $payload = is_array($decoded) ? $decoded : $_POST;
}

if (!is_array($payload) || $payload === []) {
    jsonResponse(['error' => 'Invalid payload.'], 400);
}

// ── Signature verification (never skipped) ──
if (!UpiService::verifyCallback($payload)) {
    if (DEBUG) {
        error_log('[UPI-CALLBACK] Signature verification failed for txnId=' . ($payload['txnId'] ?? '?'));
    }
    jsonResponse(['error' => 'Signature verification failed.'], 401);
}

// ── Process with idempotency ──
try {
    $service = new UpiService();
    $result = $service->processCallback($payload);
    jsonResponse([
        'status' => 'ok',
        'payment_id' => $result['payment_id'],
        'payment_status' => $result['status'],
        'processed' => $result['processed'],
        'reason' => $result['reason'] ?? null,
    ]);
} catch (InvalidArgumentException $e) {
    jsonResponse(['error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    if (DEBUG) {
        error_log('[UPI-CALLBACK] Processing failed: ' . $e->getMessage());
    }
    jsonResponse(['error' => 'Callback processing failed.'], 500);
}
