<?php

declare(strict_types=1);
/**
 * Success / Confirmation Page
 * 
 * Displayed after successful registration.
 * Equivalent to Python's templates/success.html.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/security_headers.php';

sendSecurityHeaders();

secureSessionStart();

$registration = $_SESSION['registration_result'] ?? null;

// If no registration data, redirect back to register
if (!$registration) {
    redirectTo('/register.php');
}

$reg = $registration['registration'];
$demoClass = $registration['demo_class'];
$whatsappConsent = $registration['whatsapp_consent'] ?? false;
$whatsappRedirectUrl = $registration['whatsapp_redirect_url'] ?? null;
$receipt = $registration['payment_receipt'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#fafafa">
    <script src="/assets/js/theme.js"></script>
    <title>Registration Confirmed</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <button type="button" class="theme-toggle" aria-label="Switch to dark mode" aria-pressed="false">
        <span class="theme-icon theme-icon-moon" aria-hidden="true">&#9790;</span>
        <span class="theme-icon theme-icon-sun" aria-hidden="true">&#9728;</span>
    </button>
    <div class="container">
        <div class="header">
            <h1>You're In! 🎉</h1>
            <p class="subtitle">Registration confirmed</p>
        </div>

        <div class="success-content">
            <div class="confirmation-card">
                <div class="status-badge success">
                    <span>✓</span>
                    <span>Registration Successful</span>
                </div>
                <p class="help-text" style="margin-top:8px;">A confirmation email has been sent to your email address.</p>

                <div class="class-details">
                    <h2>Class Details</h2>
                    <div class="detail-grid">
                        <div class="detail-item">
                            <label>Class</label>
                            <span><?= sanitize($demoClass['title']) ?></span>
                        </div>
                        <?php if (($demoClass['topic'] ?? '') !== ''): ?>
                        <div class="detail-item">
                            <label>Topic</label>
                            <span><?= sanitize((string)$demoClass['topic']) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (($demoClass['trainer_name'] ?? '') !== ''): ?>
                        <div class="detail-item">
                            <label>Trainer</label>
                            <span><?= sanitize((string)$demoClass['trainer_name']) ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="detail-item">
                            <label>Date</label>
                            <span><?= sanitize($demoClass['scheduled_at']) ?></span>
                        </div>
                        <div class="detail-item">
                            <label>Timezone</label>
                            <span><?= sanitize($demoClass['timezone']) ?></span>
                        </div>
                        <?php if (!empty($demoClass['teams_link'])): ?>
                        <div class="detail-item">
                            <label>Teams Link</label>
                            <a href="<?= sanitize($demoClass['teams_link']) ?>" target="_blank" rel="noopener">Join Teams Meeting</a>
                        </div>
                        <?php endif; ?>
                        <?php $calLink = googleCalendarLink($demoClass); if ($calLink): ?>
                        <div class="detail-item">
                            <label>Reminder</label>
                            <a href="<?= sanitize($calLink) ?>" target="_blank" rel="noopener">+ Add to Google Calendar</a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($receipt): ?>
                <div class="receipt-section">
                    <h3>Payment Receipt</h3>
                    <div class="detail-grid">
                        <div class="detail-item">
                            <label>Transaction ID</label>
                            <span><?= sanitize((string)($receipt['transaction_id'] ?? '-')) ?></span>
                        </div>
                        <div class="detail-item">
                            <label>Order ID</label>
                            <span><?= sanitize((string)$receipt['merchant_order_id']) ?></span>
                        </div>
                        <div class="detail-item">
                            <label>Amount Paid</label>
                            <span>₹<?= sanitize(number_format((float)$receipt['amount'], 2)) ?> <?= sanitize((string)$receipt['currency']) ?></span>
                        </div>
                        <div class="detail-item">
                            <label>Method</label>
                            <span><?= sanitize((string)$receipt['payment_method']) ?><?= !empty($receipt['payer_vpa']) ? ' from ' . sanitize((string)$receipt['payer_vpa']) : '' ?></span>
                        </div>
                        <div class="detail-item">
                            <label>Date</label>
                            <span><?= sanitize((string)$receipt['created_at']) ?> UTC</span>
                        </div>
                    </div>
                    <p class="help-text" style="margin-top:8px;">You can download this receipt anytime from your <a href="/dashboard.php">dashboard</a>.</p>
                </div>
                <?php endif; ?>

                <div class="whatsapp-section">
                    <h3>Join Our <?= sanitize(WHATSAPP_GROUP_NAME) ?> WhatsApp Group</h3>
                    <p>Get class updates, announcements, and reminders</p>
                    <?php if ($whatsappRedirectUrl): ?>
                    <a href="<?= sanitize($whatsappRedirectUrl) ?>" class="btn btn-whatsapp">
                        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
                        </svg>
                        Join Group
                    </a>
                    <?php else: ?>
                    <div class="recovery-notice">
                        <p>WhatsApp group link is not available yet. Please contact the organizer to get the invite link.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="next-steps">
                <h3>What's Next?</h3>
                <ol>
                    <li>Check your email for the meeting link</li>
                    <li>Join the WhatsApp group for updates</li>
                </ol>
            </div>

            <div class="actions">
                <a href="/register.php" class="btn btn-secondary">Register Another</a>
            </div>
        </div>

        <?php siteFooter('<p class="footer-note">Need help? Contact the organizer</p>'); ?>
    </div>
</body>
</html>
