<?php

declare(strict_types=1);

/**
 * Resource download endpoint.
 *
 * - Counts the download, then redirects to the resource's target
 *   (Google Drive direct-download URL today; local files if ever added).
 * - Paid-class materials are gated: the download is only allowed when the
 *   request carries the email of a confirmed registrant of that class.
 * - When no/incorrect email is given for a gated resource, a small form is
 *   shown asking for the registration email (posts back here).
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/training_resource_service.php';

runStartup();
sendSecurityHeaders();

$service = new TrainingResourceService();

function renderGateForm(string $resourceId, string $title, string $message): void
{
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    $safeTitle = sanitize($title);
    $safeId = sanitize($resourceId);
    $safeMsg = sanitize($message);
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendees only</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <main class="container" style="max-width: 560px; margin-top: 8vh;">
        <section class="card">
            <h1>&#128274; Attendees only</h1>
            <p>{$safeMsg}</p>
            <p class="subtitle">&ldquo;{$safeTitle}&rdquo;</p>
            <form method="post" action="/download.php">
                <input type="hidden" name="id" value="{$safeId}">
                <label for="attendee_email">Email used at registration</label>
                <input type="email" id="attendee_email" name="attendee_email" required
                       placeholder="you@example.com" autofocus
                       style="width: 100%; margin: 8px 0 16px; padding: 10px; border: 1px solid #ccc; border-radius: 8px;">
                <button type="submit" class="btn btn-primary">Unlock download</button>
                <a class="btn btn-secondary" href="/resources.php" style="margin-left: 8px;">Back to resources</a>
            </form>
        </section>
    </main>
</body>
</html>
HTML;
    exit;
}

$resourceId = trim((string)($_GET['id'] ?? $_POST['id'] ?? ''));
if ($resourceId === '') {
    http_response_code(400);
    header('Content-Type: text/html; charset=utf-8');
    echo '<p>Missing resource id. <a href="/resources.php">Back to resources</a></p>';
    exit;
}

$resource = $service->getById($resourceId);
if ($resource === null || (int)$resource['is_published'] !== 1) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<p>Resource not found. <a href="/resources.php">Back to resources</a></p>';
    exit;
}

$email = isset($_POST['attendee_email']) ? (string)$_POST['attendee_email'] : (string)($_GET['email'] ?? '');

if (!$service->canDownload((string)$resource['class_id'], $email)) {
    renderGateForm(
        $resourceId,
        (string)$resource['title'],
        $email !== ''
            ? 'This email does not match a confirmed registration for this paid training. Please double-check the email you registered with.'
            : 'Materials from this paid training are available to attendees only.'
    );
}

// Allowed → count it and hand off.
$service->incrementDownloadCount($resourceId);

header('Cache-Control: no-store');

if (!empty($resource['drive_download_url'])) {
    header('Location: ' . $resource['drive_download_url']);
    exit;
}

// Generic https resources (GitHub, other doc hosts) open in a new tab.
if (!empty($resource['resource_url'])) {
    header('Location: ' . $resource['resource_url']);
    exit;
}

// Future-proofing: a resource with a local file_url is streamed directly.
if (!empty($resource['file_url'])) {
    $path = realpath(__DIR__ . '/' . ltrim((string)$resource['file_url'], '/'));
    $base = realpath(__DIR__);
    if ($path !== false && $base !== false && str_starts_with($path, $base . DIRECTORY_SEPARATOR) && is_file($path)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . (string)filesize($path));
        readfile($path);
        exit;
    }
}

http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
echo '<p>File is unavailable. <a href="/resources.php">Back to resources</a></p>';
exit;
