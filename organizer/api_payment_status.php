<?php

declare(strict_types=1);

/**
 * Payment status API (polled by payment.php).
 *
 * GET ?order=ORD_... → { "status": "...", "amount": "...", "timeout_minutes": n }
 *
 * Only exposes non-sensitive fields. The merchant_order_id acts as the
 * capability token (24 random hex chars, generated per payment attempt).
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/upi_service.php';

sendSecurityHeaders();

if (!isMethod('GET')) {
    jsonResponse(['error' => 'Method not allowed. Use GET.'], 405);
}

$orderId = trim((string)($_GET['order'] ?? ''));
if ($orderId === '') {
    jsonResponse(['error' => 'order is required'], 400);
}

$service = new UpiService();

// Auto-expire stale payments so the poller sees terminal states promptly.
$service->expirePendingPayments();

$payment = $service->getByMerchantOrderId($orderId);
if (!$payment) {
    jsonResponse(['error' => 'Payment not found'], 404);
}

jsonResponse([
    'order_id' => $payment['merchant_order_id'],
    'status' => $payment['status'],
    'amount' => $payment['amount'],
    'currency' => $payment['currency'],
    'timeout_minutes' => UPI_PAYMENT_TIMEOUT_MINUTES,
]);
