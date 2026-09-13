<?php

declare(strict_types=1);
/**
 * Organizer Dashboard
 * 
 * View all registrations with filters.
 * Equivalent to Python's organizer dashboard route.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/registration_service.php';
require_once __DIR__ . '/../includes/class_management_service.php';
require_once __DIR__ . '/../includes/admin_dashboard_service.php';
require_once __DIR__ . '/../includes/upi_service.php';
require_once __DIR__ . '/../includes/training_resource_service.php';

// Ensure DB is initialized
runStartup();
sendSecurityHeaders();

// ── Authentication: session login OR API key (backwards compatible) ──
secureSessionStart();
$sessionAuth = !empty($_SESSION['organizer_authenticated']);
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? '';
$keyAuth = is_string($apiKey) && $apiKey !== '' && hash_equals(ORGANIZER_API_KEY, $apiKey);
if (!$sessionAuth && !$keyAuth) {
    http_response_code(403);
    echo "<h1>403 Forbidden</h1><p><a href=\"/login.php\">Log in</a> to access the organizer dashboard, or pass ?api_key=... in the URL.</p>";
    exit;
}

// Get filter parameters
$demoClassId = $_GET['demo_class_id'] ?? null;
$whatsappConsent = isset($_GET['whatsapp_consent']) ? $_GET['whatsapp_consent'] : null;
$search = $_GET['search'] ?? null;
$classStatus = $_GET['class_status'] ?? '';
$classAvailability = $_GET['class_availability'] ?? '';

if (!in_array($classStatus, ['', 'active', 'cancelled', 'archived'], true)) {
    $classStatus = '';
}
if (!in_array($classAvailability, ['', 'open', 'closed', 'full'], true)) {
    $classAvailability = '';
}

// ── Payment logs / revenue (US5) ──
$paymentStatusFilter = (string)($_GET['payment_status'] ?? '');
if (!in_array($paymentStatusFilter, ['', 'initiated', 'pending', 'success', 'failed', 'expired'], true)) {
    $paymentStatusFilter = '';
}
$paymentClassFilter = trim((string)($_GET['payment_class'] ?? ''));
$paymentSearch = trim((string)($_GET['payment_search'] ?? ''));
$paymentDateFrom = trim((string)($_GET['payment_date_from'] ?? ''));
$paymentDateTo = trim((string)($_GET['payment_date_to'] ?? ''));

$adminService = new AdminDashboardService();
$paymentLogs = $adminService->getPaymentLogs([
    'status' => $paymentStatusFilter,
    'class_id' => $paymentClassFilter,
    'search' => $paymentSearch,
    'date_from' => $paymentDateFrom,
    'date_to' => $paymentDateTo,
]);
$revenueAnalytics = $adminService->getRevenueAnalytics();
$classRevenue = $adminService->getClassRevenue();

// Training resources (recordings + Drive docs)
$resourceService = new TrainingResourceService();
$allResources = $resourceService->getAllWithClass();

// Convert whatsapp_consent to boolean
$whatsappConsentBool = null;
if ($whatsappConsent === 'true') $whatsappConsentBool = true;
elseif ($whatsappConsent === 'false') $whatsappConsentBool = false;

$service = new RegistrationService();
$registrations = $service->getRegistrations(
    demoClassId: $demoClassId,
    whatsappConsent: $whatsappConsentBool,
    search: $search
);

$demoClasses = (new ClassManagementService())->getAllClasses();
$demoClasses = array_values(array_filter($demoClasses, function (array $demoClass) use ($classStatus, $classAvailability): bool {
    if ($classStatus !== '' && $demoClass['status'] !== $classStatus) {
        return false;
    }
    if ($classAvailability === 'open' && !$demoClass['registration_open']) {
        return false;
    }
    if ($classAvailability === 'closed' && $demoClass['registration_open']) {
        return false;
    }
    if ($classAvailability === 'full' && ($demoClass['capacity'] === null || $demoClass['remaining_capacity'] > 0)) {
        return false;
    }
    return true;
}));

$filters = [
    'demo_class_id' => $demoClassId,
    'whatsapp_consent' => $whatsappConsent,
    'search' => $search,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#fafafa">
    <script src="/assets/js/theme.js"></script>
    <title>Dashboard</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <button type="button" class="theme-toggle" aria-label="Switch to dark mode" aria-pressed="false">
        <span class="theme-icon theme-icon-moon" aria-hidden="true">&#9790;</span>
        <span class="theme-icon theme-icon-sun" aria-hidden="true">&#9728;</span>
    </button>
    <div class="container container-dashboard">
        <div class="header">
            <h1>Dashboard</h1>
            <p class="subtitle">Manage registrations & follow-ups</p>
        </div>

        <div class="dashboard-content">
            <div class="class-management-section collapsible-section" id="sec-classes">
                <h2 class="section-toggle" role="button" tabindex="0" aria-expanded="true" aria-controls="sec-classes-body">Training Details<span class="section-toggle-chevron" aria-hidden="true"></span></h2>
                <div class="section-body" id="sec-classes-body">
                <form id="classManagementForm" class="filters-form">
                    <input type="hidden" id="classId" name="id">
                    <div class="filter-group"><label for="classTitle">Title</label><input id="classTitle" name="title" required></div>
                    <div class="filter-group"><label for="classTopic">Topic</label><input id="classTopic" name="topic" required></div>
                    <div class="filter-group"><label for="classTrainerName">Trainer name</label><input id="classTrainerName" name="trainer_name" required></div>
                    <div class="filter-group"><label for="classScheduledAt">Date and time</label><input id="classScheduledAt" name="scheduled_at" type="datetime-local" required></div>
                    <div class="filter-group"><label for="classTimezone">Timezone</label><input id="classTimezone" name="timezone" value="UTC" required></div>
                    <div class="filter-group"><label for="classTeamsLink">Teams link</label><input id="classTeamsLink" name="teams_link" type="url"></div>
                    <div class="filter-group"><label for="classCapacity">Capacity</label><input id="classCapacity" name="capacity" type="number" min="1" placeholder="Unlimited"></div>
                    <div class="filter-group filter-group-checkbox">
                        <label for="classIsPaid" class="checkbox-label">
                            <input id="classIsPaid" name="is_paid" type="checkbox" value="1">
                            Is Paid
                        </label>
                    </div>
                    <div class="filter-group"><label for="classPrice">Price (INR)</label><input id="classPrice" name="price" type="number" step="0.01" min="0" placeholder="0.00" disabled></div>
                    <button type="submit" class="btn btn-primary">Save class</button>
                    <button type="button" class="btn btn-secondary" onclick="resetClassForm()">New class</button>
                </form>
                <div id="classManagementMessage" class="alert" style="display:none" role="alert"></div>
                <form method="GET" action="/organizer/dashboard.php" class="filters-form">
                    <input type="hidden" name="api_key" value="<?= sanitize($apiKey) ?>">
                    <div class="filter-group">
                        <label for="class_status">Status</label>
                        <select id="class_status" name="class_status">
                            <option value="">All statuses</option>
                            <option value="active" <?= $classStatus === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="cancelled" <?= $classStatus === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                            <option value="archived" <?= $classStatus === 'archived' ? 'selected' : '' ?>>Archived</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="class_availability">Registration</label>
                        <select id="class_availability" name="class_availability">
                            <option value="">All availability</option>
                            <option value="open" <?= $classAvailability === 'open' ? 'selected' : '' ?>>Open</option>
                            <option value="closed" <?= $classAvailability === 'closed' ? 'selected' : '' ?>>Closed</option>
                            <option value="full" <?= $classAvailability === 'full' ? 'selected' : '' ?>>Full</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width: auto; padding: 8px 20px;">Filter classes</button>
                </form>
                <table class="registrations-table">
                    <thead><tr><th>Training</th><th>Topic</th><th>Trainer</th><th>Status</th><th>Registration</th><th>Price</th><th>Actions</th></tr></thead>
                    <tbody id="classManagementRows">
                    <?php foreach ($demoClasses as $dc): ?>
                        <tr>
                            <td><?= sanitize($dc['title']) ?><br><small><?= sanitize(formatScheduledDate($dc['scheduled_at'], $dc['timezone'])) ?></small></td>
                            <td><?= sanitize($dc['topic']) ?></td>
                            <td><?= sanitize($dc['trainer_name']) ?></td>
                            <td><?= sanitize($dc['status']) ?> / <?= $dc['registration_open'] ? 'open' : 'closed' ?></td>
                            <td><?= (int)$dc['registration_count'] ?> / <?= $dc['capacity'] === null ? 'unlimited' : (int)$dc['capacity'] ?></td>
                            <td>
                                <?php if ($dc['is_paid']): ?>
                                <span class="badge badge-paid">Paid</span><br><small>₹<?= number_format((float)$dc['price'], 2) ?></small>
                                <?php else: ?>
                                <span class="badge badge-free">Free</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button class="btn btn-small btn-secondary" onclick='editClass(<?= json_encode($dc, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>Edit</button>
                                <?php if ($dc['status'] === 'active' && !$dc['registration_open']): ?><button class="btn btn-small btn-primary" onclick="changeClassStatus('<?= sanitize($dc['id']) ?>', 'open')">Open</button><?php endif; ?>
                                <?php if ($dc['status'] === 'active' && $dc['registration_open']): ?><button class="btn btn-small btn-secondary" onclick="changeClassStatus('<?= sanitize($dc['id']) ?>', 'close')">Close</button><?php endif; ?>
                                <?php if ($dc['status'] !== 'archived'): ?><button class="btn btn-small btn-danger" onclick="changeClassStatus('<?= sanitize($dc['id']) ?>', '<?= $dc['status'] === 'cancelled' ? 'archive' : 'cancel' ?>')"><?= $dc['status'] === 'cancelled' ? 'Archive' : 'Cancel' ?></button><?php endif; ?>
                                <?php if ((int)$dc['registration_total'] === 0): ?><button class="btn btn-small btn-danger" onclick="deleteClass('<?= sanitize($dc['id']) ?>', '<?= sanitize($dc['title']) ?>')">Delete</button><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-section collapsible-section" id="sec-filters">
                <h2 class="section-toggle" role="button" tabindex="0" aria-expanded="true" aria-controls="sec-filters-body">Registrations Filter<span class="section-toggle-chevron" aria-hidden="true"></span></h2>
                <div class="section-body" id="sec-filters-body">
                <form method="GET" action="/organizer/dashboard.php" class="filters-form">
                    <input type="hidden" name="api_key" value="<?= sanitize($apiKey) ?>">
                    <div class="filter-group">
                        <label for="demo_class_id">Class</label>
                        <select id="demo_class_id" name="demo_class_id">
                            <option value="">All</option>
                            <?php foreach ($demoClasses as $dc): ?>
                            <option
                                value="<?= sanitize($dc['id']) ?>"
                                <?= $filters['demo_class_id'] === $dc['id'] ? 'selected' : '' ?>
                            ><?= sanitize($dc['title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="whatsapp_consent">WhatsApp</label>
                        <select id="whatsapp_consent" name="whatsapp_consent">
                            <option value="">All</option>
                            <option value="true" <?= $filters['whatsapp_consent'] === 'true' ? 'selected' : '' ?>>Consented</option>
                            <option value="false" <?= $filters['whatsapp_consent'] === 'false' ? 'selected' : '' ?>>Not Consented</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="search">Search</label>
                        <input type="text" id="search" name="search" value="<?= sanitize($filters['search'] ?? '') ?>" placeholder="Name, email, or phone">
                    </div>

                    <button type="submit" class="btn btn-primary" style="width: auto; padding: 8px 20px;">Filter</button>
                    <a class="btn btn-secondary" style="width: auto; padding: 8px 20px;" href="/organizer/export_csv.php?api_key=<?= urlencode($apiKey) ?>&type=registrations&demo_class_id=<?= urlencode((string)($filters['demo_class_id'] ?? '')) ?>&whatsapp_consent=<?= urlencode((string)($filters['whatsapp_consent'] ?? '')) ?>&search=<?= urlencode((string)($filters['search'] ?? '')) ?>">&#11015; Export CSV</a>
                </form>
                </div>
            </div>

            <!-- Registrations -->
            <div class="registrations-section collapsible-section" id="sec-registrations">
                <h2 class="section-toggle" role="button" tabindex="0" aria-expanded="true" aria-controls="sec-registrations-body">Registrations (<?= count($registrations) ?>)<span class="section-toggle-chevron" aria-hidden="true"></span></h2>
                <div class="section-body" id="sec-registrations-body">

                <?php if (!empty($registrations)): ?>
                <table class="registrations-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Class</th>
                            <th>Registered</th>
                            <th>WhatsApp</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($registrations as $reg): ?>
                        <tr>
                            <td><strong><?= sanitize($reg['registrant_name']) ?></strong></td>
                            <td><?= sanitize($reg['registrant_email']) ?></td>
                            <td><?= sanitize($reg['phone_number']) ?></td>
                            <td><?= sanitize($reg['class_title']) ?></td>
                            <td><small><?= sanitize(formatUtcDateInTimezone($reg['created_at'] ?? null, (string)($reg['timezone'] ?? 'UTC'))) ?></small></td>
                            <td>
                                <?php if ($reg['consented_to_whatsapp']): ?>
                                <span class="badge badge-success">Yes</span>
                                <?php else: ?>
                                <span class="badge badge-warning">No</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-<?= $reg['registration_status'] ?>">
                                    <?= $reg['registration_status'] ?>
                                </span>
                            </td>
                            <td>
                                <div class="btn-group">
                                    <button class="btn btn-small btn-secondary" onclick="recordFollowUp('<?= sanitize($reg['id']) ?>')">Follow-up</button>
                                    <button class="btn btn-small btn-danger" onclick="deleteRegistration('<?= sanitize($reg['id']) ?>', '<?= sanitize($reg['registrant_name']) ?>')">Delete</button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="empty-state">
                    <p>No registrations found.</p>
                </div>
                <?php endif; ?>
                </div>
            </div>

            <!-- Revenue & Payment Logs -->
            <div class="registrations-section collapsible-section" style="margin-top: 8px;" id="sec-payments">
                <h2 class="section-toggle" role="button" tabindex="0" aria-expanded="true" aria-controls="sec-payments-body">Payments &amp; Revenue<span class="section-toggle-chevron" aria-hidden="true"></span></h2>
                <div class="section-body" id="sec-payments-body">

                <div class="revenue-cards">
                    <div class="revenue-card">
                        <span class="revenue-card-label">Total Revenue</span>
                        <span class="revenue-card-value">₹<?= number_format($revenueAnalytics['total_revenue'], 2) ?></span>
                        <small class="help-text"><?= $revenueAnalytics['success_count'] ?> successful payment(s)</small>
                    </div>
                    <div class="revenue-card">
                        <span class="revenue-card-label">Pending / Initiated</span>
                        <span class="revenue-card-value"><?= $revenueAnalytics['open_count'] ?></span>
                        <small class="help-text">awaiting confirmation</small>
                    </div>
                    <div class="revenue-card">
                        <span class="revenue-card-label">Failed</span>
                        <span class="revenue-card-value"><?= $revenueAnalytics['failed_count'] ?></span>
                        <small class="help-text"><?= $revenueAnalytics['expired_count'] ?> expired</small>
                    </div>
                    <div class="revenue-card">
                        <span class="revenue-card-label">Classes</span>
                        <span class="revenue-card-value"><?= $revenueAnalytics['paid_classes'] ?> paid</span>
                        <small class="help-text"><?= $revenueAnalytics['free_classes'] ?> free (active)</small>
                    </div>
                </div>

                <?php if (!empty($classRevenue)): ?>
                <table class="registrations-table" style="margin-bottom:16px;">
                    <thead><tr><th>Class</th><th>List price</th><th>Success</th><th>Failed</th><th>Expired</th><th>Open</th><th>Revenue</th></tr></thead>
                    <tbody>
                        <?php foreach ($classRevenue as $cr): ?>
                        <tr>
                            <td><?= sanitize($cr['title']) ?></td>
                            <td>₹<?= number_format((float)$cr['list_price'], 2) ?></td>
                            <td><?= (int)$cr['success_count'] ?></td>
                            <td><?= (int)$cr['failed_count'] ?></td>
                            <td><?= (int)$cr['expired_count'] ?></td>
                            <td><?= (int)$cr['open_count'] ?></td>
                            <td><strong>₹<?= number_format((float)$cr['revenue'], 2) ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>

                <form method="GET" action="/organizer/dashboard.php" class="filters-form">
                    <input type="hidden" name="api_key" value="<?= sanitize($apiKey) ?>">
                    <div class="filter-group">
                        <label for="payment_status">Payment status</label>
                        <select id="payment_status" name="payment_status">
                            <option value="">All</option>
                            <?php foreach (['success', 'initiated', 'pending', 'failed', 'expired'] as $st): ?>
                            <option value="<?= $st ?>" <?= $paymentStatusFilter === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="payment_search">Search</label>
                        <input type="text" id="payment_search" name="payment_search" value="<?= sanitize($paymentSearch) ?>" placeholder="User, email, txn/order ID">
                    </div>
                    <div class="filter-group">
                        <label for="payment_date_from">From</label>
                        <input type="date" id="payment_date_from" name="payment_date_from" value="<?= sanitize($paymentDateFrom) ?>">
                    </div>
                    <div class="filter-group">
                        <label for="payment_date_to">To</label>
                        <input type="date" id="payment_date_to" name="payment_date_to" value="<?= sanitize($paymentDateTo) ?>">
                    </div>
                    <button type="submit" class="btn btn-primary" style="width: auto; padding: 8px 20px;">Filter payments</button>
                    <a class="btn btn-secondary" style="width: auto; padding: 8px 20px;" href="/organizer/export_csv.php?api_key=<?= urlencode($apiKey) ?>&type=payments&payment_status=<?= urlencode($paymentStatusFilter) ?>&payment_class=<?= urlencode($paymentClassFilter) ?>&payment_search=<?= urlencode($paymentSearch) ?>&payment_date_from=<?= urlencode($paymentDateFrom) ?>&payment_date_to=<?= urlencode($paymentDateTo) ?>">&#11015; Export CSV</a>
                </form>

                <?php if (empty($paymentLogs)): ?>
                <div class="empty-state"><p>No payments found.</p></div>
                <?php else: ?>
                <table class="registrations-table">
                    <thead><tr><th>Transaction</th><th>Order ID</th><th>User</th><th>Class</th><th>Amount</th><th>Status</th><th>Date</th><th>Reconcile</th></tr></thead>
                    <tbody>
                        <?php foreach ($paymentLogs as $log): ?>
                        <tr>
                            <td><small><?= sanitize((string)($log['transaction_id'] ?? '-')) ?></small></td>
                            <td><small><?= sanitize((string)$log['merchant_order_id']) ?></small></td>
                            <td><?= sanitize((string)$log['user_name']) ?><br><small><?= sanitize((string)$log['user_email']) ?></small></td>
                            <td><?= sanitize((string)$log['class_title']) ?></td>
                            <td>₹<?= number_format((float)$log['amount'], 2) ?></td>
                            <td><span class="badge badge-<?= sanitize((string)$log['status']) ?>"><?= sanitize((string)$log['status']) ?></span></td>
                            <td><small><?= sanitize((string)$log['created_at']) ?></small></td>
                            <td>
                                <?php if (in_array($log['status'], ['initiated', 'pending'], true)): ?>
                                <div class="btn-group">
                                    <button class="btn btn-small btn-primary" onclick="reconcilePayment('<?= sanitize((string)$log['id']) ?>', 'success')">Mark Paid</button>
                                    <button class="btn btn-small btn-danger" onclick="reconcilePayment('<?= sanitize((string)$log['id']) ?>', 'failed')">Mark Failed</button>
                                </div>
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

            <!-- Training Resources -->
            <div class="registrations-section collapsible-section" style="margin-top: 8px;" id="sec-resources">
                <h2 class="section-toggle" role="button" tabindex="0" aria-expanded="true" aria-controls="sec-resources-body">Training Resources<span class="section-toggle-chevron" aria-hidden="true"></span></h2>
                <div class="section-body" id="sec-resources-body">
                <p class="help-text" style="margin-bottom: 12px;">Attach a YouTube recording and/or Google Drive slides/PDF to a training. Materials appear on the public <a href="/resources.php" target="_blank">Training Resources</a> page once the class date has passed. Drive files must be shared as &ldquo;Anyone with the link &rarr; Viewer&rdquo;.</p>

                <form id="resourceForm" class="filters-form" style="align-items: flex-end;">
                    <div class="filter-group">
                        <label for="resClassId">Training class</label>
                        <select id="resClassId" required>
                            <option value="">Select class&hellip;</option>
                            <?php foreach ($demoClasses as $dc): ?>
                            <option value="<?= sanitize((string)$dc['id']) ?>"><?= sanitize((string)$dc['title']) ?> (<?= sanitize(date('d M Y', strtotime((string)$dc['scheduled_at']))) ?><?= (int)$dc['is_paid'] === 1 ? ', Paid' : ', Free' ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="resType">Type</label>
                        <select id="resType" required>
                            <option value="recording">Recording (YouTube)</option>
                            <option value="slides">Slides (Google Drive)</option>
                            <option value="pdf">PDF (Google Drive)</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="resTitle">Title</label>
                        <input type="text" id="resTitle" required placeholder="Session 1 &mdash; Intro to Python">
                    </div>
                    <div class="filter-group res-field-group" data-for="recording">
                        <label for="resYoutubeUrl">YouTube link</label>
                        <input type="url" id="resYoutubeUrl" placeholder="https://youtu.be/&hellip; or youtube.com/watch?v=&hellip;">
                    </div>
                    <div class="filter-group res-field-group" data-for="slides pdf" hidden>
                        <label for="resDriveUrl">Google Drive link</label>
                        <input type="url" id="resDriveUrl" placeholder="https://drive.google.com/file/d/&hellip;/view">
                    </div>
                    <div class="filter-group res-field-group" data-for="slides pdf" hidden>
                        <label for="resFileName">File name (optional)</label>
                        <input type="text" id="resFileName" placeholder="intro-to-python.pdf">
                    </div>
                    <div class="filter-group res-field-group" data-for="slides pdf" hidden>
                        <label for="resFileSize">Size (optional)</label>
                        <input type="text" id="resFileSize" placeholder="2.4 MB" maxlength="20">
                    </div>
                    <div class="filter-group">
                        <label for="resPublished">Visibility</label>
                        <select id="resPublished">
                            <option value="1" selected>Published</option>
                            <option value="0">Hidden (draft)</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width: auto; padding: 8px 20px;">Add Resource</button>
                </form>
                <p id="resourceMessage" class="help-text" style="margin: 8px 0;"></p>

                <?php if (empty($allResources)): ?>
                <div class="empty-state"><p>No resources added yet. Add a recording or slides above.</p></div>
                <?php else: ?>
                <table class="registrations-table">
                    <thead><tr><th>Class</th><th>Type</th><th>Title</th><th>Visible</th><th>Downloads</th><th>Added</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($allResources as $res): ?>
                        <tr>
                            <td><?= sanitize((string)$res['class_title']) ?><br><small><?= (int)$res['is_paid'] === 1 ? 'Paid' : 'Free' ?></small></td>
                            <td><?= sanitize((string)$res['type']) ?></td>
                            <td><?= sanitize((string)$res['title']) ?></td>
                            <td><span class="badge <?= (int)$res['is_published'] === 1 ? 'badge-success' : 'badge-warning' ?>"><?= (int)$res['is_published'] === 1 ? 'Published' : 'Hidden' ?></span></td>
                            <td><?= (int)$res['download_count'] ?></td>
                            <td><small><?= sanitize((string)$res['created_at']) ?></small></td>
                            <td>
                                <div class="btn-group">
                                    <button class="btn btn-small <?= (int)$res['is_published'] === 1 ? 'btn-secondary' : 'btn-primary' ?>" onclick="toggleResourcePublish('<?= sanitize((string)$res['id']) ?>', <?= (int)$res['is_published'] === 1 ? 'false' : 'true' ?>)"><?= (int)$res['is_published'] === 1 ? 'Unpublish' : 'Publish' ?></button>
                                    <button class="btn btn-small btn-danger" onclick="deleteResource('<?= sanitize((string)$res['id']) ?>')">Delete</button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
                </div>
            </div>

            <!-- Follow-up Modal -->
            <div id="followUpModal" class="modal" style="display: none;">
                <div class="modal-content">
                    <span class="close" onclick="closeModal()">&times;</span>
                    <h3>Record Follow-up</h3>
                    <form id="followUpForm" method="POST">
                        <input type="hidden" id="registrationId" name="registration_id">

                        <div class="form-group">
                            <label for="followUpType">Type</label>
                            <select id="followUpType" name="follow_up_type" required>
                                <option value="announcement_sent">Announcement Sent</option>
                                <option value="group_invitation_attempted">Group Invitation</option>
                                <option value="teams_link_shared">Teams Link Shared</option>
                                <option value="reminder_sent">Reminder Sent</option>
                                <option value="opt_out_recorded">Opt-out Recorded</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="outcome">Outcome</label>
                            <select id="outcome" name="outcome">
                                <option value="pending">Pending</option>
                                <option value="attempted">Attempted</option>
                                <option value="completed">Completed</option>
                                <option value="blocked">Blocked</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="notes">Notes</label>
                            <textarea id="notes" name="notes" rows="3"></textarea>
                        </div>

                        <button type="submit" class="btn btn-primary">Save</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="footer">
            <p><a href="/register.php">Back to Registration</a> &middot; <a href="/logout.php">Log out</a></p>
        </div>
    </div>

    <script>window.ORGANIZER_SESSION = <?= $sessionAuth ? 'true' : 'false' ?>;</script>
    <script src="/assets/js/organizer.js"></script>
</body>
</html>
