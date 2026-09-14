<?php

declare(strict_types=1);

/**
 * UPI Payment Page
 *
 * Shown after a paid-class registration is created (status: pending).
 *  - Displays the dynamic UPI QR code, amount and order reference
 *  - Polls organizer/api_payment_status.php for status changes
 *  - On success: redirects to success.php with the receipt
 *  - On failure/expiry: shows a retry option (new payment attempt)
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/registration_service.php';
require_once __DIR__ . '/includes/upi_service.php';
require_once __DIR__ . '/includes/class_management_service.php';

secureSessionStart();

runStartup();
sendSecurityHeaders();

$orderId = trim((string)($_GET['order'] ?? ''));
$service = new UpiService();

// Auto-expire stale payments so users see the expired state immediately.
$service->expirePendingPayments();

// Load payment context. Prefer the order id; fall back to the newest
// pending payment created for this session (page refresh safety).
$payment = $orderId !== '' ? $service->getByMerchantOrderId($orderId) : null;
$context = $_SESSION['payment_context'] ?? null;

if (!$payment && $context && !empty($context['payment']['merchant_order_id'])) {
    $payment = $service->getByMerchantOrderId((string)$context['payment']['merchant_order_id']);
}
if (!$payment) {
    htmlError('Payment session not found. Please register again.', 404);
}

// Load registration + class details for the receipt/summary.
$registration = (new RegistrationService())->getRegistration((string)$payment['registration_id']);
$classService = new ClassManagementService();
$demoClass = $classService->getById((string)$payment['class_id']);

$status = (string)$payment['status'];
$amount = number_format((float)$payment['amount'], 2);
$qrCode = null;
$upiPayload = null;

if ($status === 'initiated' || $status === 'pending') {
    $qrCode = UpiService::generateUpiQrCode((string)$payment['merchant_order_id'], (float)$payment['amount'], (string)$payment['class_id']);
    $upiPayload = UpiService::buildUpiPayload((string)$payment['merchant_order_id'], (float)$payment['amount']);
}

// Success handoff: stash the receipt in the session for success.php.
if ($status === 'success' && $context) {
    $_SESSION['registration_result'] = $context;
    $_SESSION['registration_result']['payment_receipt'] = [
        'merchant_order_id' => $payment['merchant_order_id'],
        'transaction_id' => $payment['transaction_id'],
        'amount' => $payment['amount'],
        'currency' => $payment['currency'],
        'status' => $payment['status'],
        'payment_method' => $payment['payment_method'],
        'payer_vpa' => $payment['payer_vpa'],
        'created_at' => $payment['created_at'],
        'class_title' => $demoClass['title'] ?? '',
    ];
    session_write_close();
    redirectTo('/success.php?paid=1');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#fafafa">
    <script src="/assets/js/theme.js"></script>
    <title>Complete Your Payment</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <button type="button" class="theme-toggle" aria-label="Switch to dark mode" aria-pressed="false">
        <span class="theme-icon theme-icon-moon" aria-hidden="true">&#9790;</span>
        <span class="theme-icon theme-icon-sun" aria-hidden="true">&#9728;</span>
    </button>
    <div class="container">
        <div class="header">
            <h1>Complete Your Payment</h1>
            <p class="subtitle">Pay via UPI to confirm your seat</p>
        </div>

        <div class="success-content">
            <?php if ($status === 'initiated' || $status === 'pending'): ?>
            <div class="confirmation-card">
                <div class="status-badge pending">
                    <span>&#8987;</span>
                    <span>Awaiting payment</span>
                </div>

                <div class="class-details">
                    <div class="detail-grid">
                        <div class="detail-item">
                            <label>Class</label>
                            <span><?= sanitize($demoClass['title'] ?? '') ?></span>
                        </div>
                        <div class="detail-item">
                            <label>Amount</label>
                            <span class="price-tag">&#8377;<?= sanitize($amount) ?></span>
                        </div>
                        <div class="detail-item">
                            <label>Order ID</label>
                            <span><?= sanitize($payment['merchant_order_id']) ?></span>
                        </div>
                        <div class="detail-item">
                            <label>Pay to</label>
                            <span><?= sanitize(UPI_MERCHANT_VPA) ?> (<?= sanitize(UPI_MERCHANT_NAME) ?>)</span>
                        </div>
                    </div>
                </div>

                <div class="qr-section">
                    <img src="<?= sanitize($qrCode) ?>" alt="UPI QR code for &#8377;<?= sanitize($amount) ?>" class="qr-image" width="240" height="240">
                    <p class="help-text">Scan with any UPI app (GPay, PhonePe, Paytm, BHIM&hellip;)</p>
                    <details class="upi-id-details">
                        <summary>Or pay using your UPI app manually</summary>
                        <code class="upi-payload"><?= sanitize($upiPayload) ?></code>
                    </details>
                </div>

                <p class="help-text" id="paymentPollNote">
                    This page updates automatically. Payment must be completed within <?= (int)UPI_PAYMENT_TIMEOUT_MINUTES ?> minutes or your seat will be released.
                </p>
            </div>

            <?php elseif ($status === 'failed'): ?>
            <div class="confirmation-card">
                <div class="status-badge failed">
                    <span>&#10060;</span>
                    <span>Payment failed</span>
                </div>
                <p class="help-text">Your payment could not be completed and your seat was released. You can safely retry.</p>
                <div class="actions">
                    <a href="/register.php" class="btn btn-primary">Retry Payment</a>
                    <a href="/dashboard.php" class="btn btn-secondary">My Registrations</a>
                </div>
            </div>

            <?php elseif ($status === 'expired'): ?>
            <div class="confirmation-card">
                <div class="status-badge expired">
                    <span>&#9203;</span>
                    <span>Payment expired</span>
                </div>
                <p class="help-text">
                    This payment request expired after <?= (int)UPI_PAYMENT_TIMEOUT_MINUTES ?> minutes and your seat was released.
                    Please register again to get a new payment request.
                </p>
                <div class="actions">
                    <a href="/register.php" class="btn btn-primary">Retry Payment</a>
                    <a href="/dashboard.php" class="btn btn-secondary">My Registrations</a>
                </div>
            </div>

            <?php else: /* success reached without context (e.g. session lost) */ ?>
            <div class="confirmation-card">
                <div class="status-badge success">
                    <span>&#10003;</span>
                    <span>Payment received</span>
                </div>
                <p class="help-text">Your payment was received and your registration is confirmed. View your receipt on your dashboard.</p>
                <div class="actions">
                    <a href="/dashboard.php" class="btn btn-primary">Go to My Dashboard</a>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <?php siteFooter('<p class="footer-note">Need help? Contact the organizer</p>'); ?>
    </div>

    <script>
    (function () {
        var orderId = <?= json_encode($payment['merchant_order_id']) ?>;
        var pollInterval = 3000; // 3s
        var maxAttempts = 200;   // ~10 minutes of polling
        var attempts = 0;

        function checkStatus() {
            attempts++;
            if (attempts > maxAttempts) return;
            fetch('/organizer/api_payment_status.php?order=' + encodeURIComponent(orderId), { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data && data.status === 'success') {
                        window.location.reload();
                    } else if (data && (data.status === 'failed' || data.status === 'expired')) {
                        window.location.reload();
                    } else {
                        setTimeout(checkStatus, pollInterval);
                    }
                })
                .catch(function () { setTimeout(checkStatus, pollInterval * 2); });
        }
        setTimeout(checkStatus, pollInterval);
    })();
    </script>
</body>
</html>
