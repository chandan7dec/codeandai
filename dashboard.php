<?php

declare(strict_types=1);

/**
 * User Dashboard
 *
 * Tab 1: Enrolled classes (filter: all / free / paid / upcoming / completed)
 * Tab 2: Payment history with downloadable receipts.
 *
 * The registration flow has no logins; the dashboard is keyed by the email
 * used at registration (verified via a simple self-service lookup form).
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/dashboard_service.php';

secureSessionStart();

runStartup();
sendSecurityHeaders();

$email = trim((string)($_GET['email'] ?? ($_SESSION['dashboard_email'] ?? '')));
$service = new DashboardService();
$filter = (string)($_GET['filter'] ?? 'all');
if (!in_array($filter, ['all', 'free', 'paid', 'upcoming', 'completed'], true)) {
    $filter = 'all';
}

// Look up the registrant by email.
$registrant = null;
$enrolledClasses = [];
$payments = [];
$lookupError = '';

$db = getDB();
if ($email !== '') {
    $_SESSION['dashboard_email'] = $email;
    if ($db instanceof PDO) {
        $stmt = $db->prepare('SELECT * FROM registrants WHERE email = ? LIMIT 1');
        $stmt->execute([normalizeEmail($email)]);
        $registrant = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } else {
        $stmt = $db->prepare('SELECT * FROM registrants WHERE email = ? LIMIT 1');
        $stmt->bind_param('s', $normalized);
        $normalized = normalizeEmail($email);
        $stmt->execute();
        $registrant = $stmt->get_result()->fetch_assoc() ?: null;
    }
    if (!$registrant) {
        $lookupError = 'No registrations found for that email address.';
        $email = '';
    } else {
        $enrolledClasses = $service->getUserEnrolledClasses((string)$registrant['email'], $filter);
        $payments = $service->getUserPaymentHistory((string)$registrant['email']);
    }
}

$successPaid = isset($_GET['paid']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#fafafa">
    <script src="/assets/js/theme.js"></script>
    <title>My Dashboard</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <button type="button" class="theme-toggle" aria-label="Switch to dark mode" aria-pressed="false">
        <span class="theme-icon theme-icon-moon" aria-hidden="true">&#9790;</span>
        <span class="theme-icon theme-icon-sun" aria-hidden="true">&#9728;</span>
    </button>
    <div class="container container-dashboard">
        <div class="header">
            <h1>My Dashboard</h1>
            <p class="subtitle">Your enrolled classes and payment history</p>
        </div>

        <?php if ($successPaid): ?>
        <div class="alert alert-success" role="status">Payment received — your registration is confirmed. Your receipt is below.</div>
        <?php endif; ?>

        <?php if ($lookupError): ?>
        <div class="alert alert-error" role="alert"><?= sanitize($lookupError) ?></div>
        <?php endif; ?>

        <?php if (!$registrant): ?>
        <form method="GET" action="/dashboard.php" class="registration-form">
            <div class="form-group">
                <label for="email">Email used at registration</label>
                <input type="email" id="email" name="email" value="<?= sanitize($email) ?>" required placeholder="you@example.com">
                <small class="help-text">We'll look up your registrations and payments by email.</small>
            </div>
            <button type="submit" class="btn btn-primary">View My Dashboard</button>
        </form>

        <?php else: ?>
        <p class="help-text">Signed in as <strong><?= sanitize($registrant['email']) ?></strong> · <a href="/dashboard.php?logout=1">Switch email</a></p>

        <div class="dashboard-content">
            <div class="dashboard-tabs" role="tablist">
                <button class="dashboard-tab-btn active" data-tab="classes" role="tab" type="button">Enrolled Classes (<?= count($enrolledClasses) ?>)</button>
                <button class="dashboard-tab-btn" data-tab="payments" role="tab" type="button">Payment History (<?= count($payments) ?>)</button>
            </div>

            <!-- Tab 1: Enrolled classes -->
            <div class="dashboard-tab-panel active" id="tab-classes">
                <div class="dashboard-filters">
                    <?php foreach (['all' => 'All', 'free' => 'Free', 'paid' => 'Paid', 'upcoming' => 'Upcoming', 'completed' => 'Completed'] as $key => $label): ?>
                    <a class="chip <?= $filter === $key ? 'active' : '' ?>" href="/dashboard.php?email=<?= urlencode((string)$registrant['email']) ?>&filter=<?= $key ?>"><?= sanitize($label) ?></a>
                    <?php endforeach; ?>
                </div>

                <?php if (empty($enrolledClasses)): ?>
                <div class="empty-state"><p>No enrolled classes for this filter.</p></div>
                <?php else: ?>
                <table class="registrations-table">
                    <thead><tr><th>Class</th><th>Trainer</th><th>Date</th><th>Type</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($enrolledClasses as $row): ?>
                        <tr>
                            <td><strong><?= sanitize($row['title']) ?></strong><br><small><?= sanitize($row['topic']) ?></small></td>
                            <td><?= sanitize($row['trainer_name']) ?></td>
                            <td><?= sanitize(formatScheduledDate($row['scheduled_at'], $row['timezone'])) ?></td>
                            <td>
                                <?php if ($row['is_paid']): ?>
                                <span class="badge badge-paid">Paid ₹<?= number_format((float)$row['price'], 2) ?></span>
                                <?php else: ?>
                                <span class="badge badge-free">Free</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge badge-<?= sanitize($row['registration_status']) ?>"><?= sanitize($row['registration_status']) ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <!-- Tab 2: Payment history -->
            <div class="dashboard-tab-panel" id="tab-payments">
                <?php if (empty($payments)): ?>
                <div class="empty-state"><p>No payments found.</p></div>
                <?php else: ?>
                <table class="registrations-table">
                    <thead><tr><th>Transaction</th><th>Class</th><th>Amount</th><th>Status</th><th>Date</th><th>Receipt</th></tr></thead>
                    <tbody>
                        <?php foreach ($payments as $p): ?>
                        <tr>
                            <td>
                                <small><?= sanitize((string)($p['transaction_id'] ?? '-')) ?></small><br>
                                <small>Order: <?= sanitize((string)$p['merchant_order_id']) ?></small>
                            </td>
                            <td><?= sanitize((string)$p['class_title']) ?></td>
                            <td>₹<?= number_format((float)$p['amount'], 2) ?></td>
                            <td><span class="badge badge-<?= sanitize((string)$p['status']) ?>"><?= sanitize((string)$p['status']) ?></span></td>
                            <td><?= sanitize((string)$p['created_at']) ?></td>
                            <td>
                                <?php if ($p['status'] === 'success'): ?>
                                <a class="btn btn-small btn-secondary" href="/dashboard.php?email=<?= urlencode((string)$registrant['email']) ?>&receipt=<?= sanitize((string)$p['id']) ?>">View</a>
                                <?php else: ?>
                                —
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="footer">
            <p><a href="/register.php">Register for a class</a> · <a href="/resources.php">Training resources</a></p>
        </div>
    </div>

    <?php if (!empty($_GET['receipt']) && $registrant): ?>
    <!-- Receipt modal content is injected by JS-less detail view: render inline -->
    <?php
    $receiptPayment = null;
    foreach ($payments as $p) {
        if ($p['id'] === (string)$_GET['receipt'] && $p['status'] === 'success') {
            $receiptPayment = $p;
            break;
        }
    }
    ?>
    <?php if ($receiptPayment): ?>
    <div class="modal" style="display:flex;">
        <div class="modal-content">
            <span class="close" onclick="window.location.href='/dashboard.php?email=<?= urlencode((string)$registrant['email']) ?>'">&times;</span>
            <h3>Payment Receipt</h3>
            <div class="detail-grid">
                <div class="detail-item"><label>Transaction ID</label><span><?= sanitize((string)($receiptPayment['transaction_id'] ?? '-')) ?></span></div>
                <div class="detail-item"><label>Order ID</label><span><?= sanitize((string)$receiptPayment['merchant_order_id']) ?></span></div>
                <div class="detail-item"><label>Class</label><span><?= sanitize((string)$receiptPayment['class_title']) ?></span></div>
                <div class="detail-item"><label>Amount</label><span>₹<?= number_format((float)$receiptPayment['amount'], 2) ?> <?= sanitize((string)$receiptPayment['currency']) ?></span></div>
                <div class="detail-item"><label>Method</label><span><?= sanitize((string)$receiptPayment['payment_method']) ?><?= !empty($receiptPayment['payer_vpa']) ? ' from ' . sanitize((string)$receiptPayment['payer_vpa']) : '' ?></span></div>
                <div class="detail-item"><label>Date</label><span><?= sanitize((string)$receiptPayment['created_at']) ?> UTC</span></div>
                <div class="detail-item"><label>Registration</label><span><?= sanitize((string)$receiptPayment['registration_status']) ?></span></div>
            </div>
            <p class="help-text" style="margin-top:10px;">Use your browser's Print → Save as PDF to download this receipt.</p>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <script>
    // Tab switching
    document.querySelectorAll('.dashboard-tab-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.dashboard-tab-btn').forEach(function (b) { b.classList.remove('active'); });
            document.querySelectorAll('.dashboard-tab-panel').forEach(function (p) { p.classList.remove('active'); });
            btn.classList.add('active');
            document.getElementById('tab-' + btn.dataset.tab).classList.add('active');
        });
    });
    </script>
</body>
</html>
