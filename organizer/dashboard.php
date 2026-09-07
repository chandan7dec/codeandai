<?php
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

// Ensure DB is initialized
runStartup();

// Verify API key
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? '';
if ($apiKey !== ORGANIZER_API_KEY) {
    http_response_code(403);
    echo "<h1>403 Forbidden</h1><p>Invalid API key. Pass ?api_key=organizer-secret-key in the URL.</p>";
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
            <div class="class-management-section">
                <h2>Training Details</h2>
                <form id="classManagementForm" class="filters-form">
                    <input type="hidden" id="classId" name="id">
                    <div class="filter-group"><label for="classTitle">Title</label><input id="classTitle" name="title" required></div>
                    <div class="filter-group"><label for="classTopic">Topic</label><input id="classTopic" name="topic" required></div>
                    <div class="filter-group"><label for="classTrainerName">Trainer name</label><input id="classTrainerName" name="trainer_name" required></div>
                    <div class="filter-group"><label for="classScheduledAt">Date and time</label><input id="classScheduledAt" name="scheduled_at" type="datetime-local" required></div>
                    <div class="filter-group"><label for="classTimezone">Timezone</label><input id="classTimezone" name="timezone" value="UTC" required></div>
                    <div class="filter-group"><label for="classTeamsLink">Teams link</label><input id="classTeamsLink" name="teams_link" type="url"></div>
                    <div class="filter-group"><label for="classCapacity">Capacity</label><input id="classCapacity" name="capacity" type="number" min="1" placeholder="Unlimited"></div>
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
                    <thead><tr><th>Training</th><th>Topic</th><th>Trainer</th><th>Status</th><th>Registration</th><th>Actions</th></tr></thead>
                    <tbody id="classManagementRows">
                    <?php foreach ($demoClasses as $dc): ?>
                        <tr>
                            <td><?= sanitize($dc['title']) ?><br><small><?= sanitize(formatScheduledDate($dc['scheduled_at'], $dc['timezone'])) ?></small></td>
                            <td><?= sanitize($dc['topic']) ?></td>
                            <td><?= sanitize($dc['trainer_name']) ?></td>
                            <td><?= sanitize($dc['status']) ?> / <?= $dc['registration_open'] ? 'open' : 'closed' ?></td>
                            <td><?= (int)$dc['registration_count'] ?> / <?= $dc['capacity'] === null ? 'unlimited' : (int)$dc['capacity'] ?></td>
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

            <!-- Filters -->
            <div class="filters-section">
                <form method="GET" action="/organizer/dashboard.php" class="filters-form">
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
                </form>
            </div>

            <!-- Registrations -->
            <div class="registrations-section">
                <h2>Registrations (<?= count($registrations) ?>)</h2>

                <?php if (!empty($registrations)): ?>
                <table class="registrations-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Class</th>
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
            <p><a href="/register.php">Back to Registration</a></p>
        </div>
    </div>

    <script src="/assets/js/organizer.js"></script>
</body>
</html>
