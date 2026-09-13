<?php

declare(strict_types=1);

/**
 * Organizer Login
 *
 * Replaces API-key-in-URL access to the organizer dashboard with a proper
 * session login. The dashboard still accepts the API key (backwards
 * compatibility for scripts/bookmarks) but the normal flow is:
 *   login.php  ->  organizer/dashboard.php  ->  logout.php
 *
 * Brute-force protection: file-based per-IP rate limit + progressive sleep.
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/security_headers.php';

sendSecurityHeaders();

secureSessionStart();

$error = '';

if (isMethod('POST')) {
    // Brute-force guard: 10 attempts / 10 min per IP.
    if (!rateLimitRequest('login:' . clientIp(), 10, 600)) {
        $error = 'Too many attempts. Please wait 10 minutes and try again.';
    } else {
        $key = trim((string)($_POST['api_key'] ?? ''));
        if ($key !== '' && hash_equals(ORGANIZER_API_KEY, $key)) {
            session_regenerate_id(true);
            $_SESSION['organizer_authenticated'] = true;
            $_SESSION['organizer_login_time'] = time();
            redirectTo('/organizer/dashboard.php');
        }
        // Wrong key: slow the next attempt down a little.
        sleep(1);
        $error = 'Invalid API key.';
    }
}

$loggedIn = !empty($_SESSION['organizer_authenticated']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#fafafa">
    <meta name="robots" content="noindex, nofollow">
    <script src="/assets/js/theme.js"></script>
    <title>Organizer Login</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <button type="button" class="theme-toggle" aria-label="Switch to dark mode" aria-pressed="false">
        <span class="theme-icon theme-icon-moon" aria-hidden="true">&#9790;</span>
        <span class="theme-icon theme-icon-sun" aria-hidden="true">&#9728;</span>
    </button>
    <main class="container" style="max-width: 420px; margin-top: 10vh;">
        <header class="header">
            <h1>Organizer Login</h1>
            <p class="subtitle">Enter your organizer API key to manage registrations</p>
        </header>

        <?php if ($loggedIn): ?>
        <div class="card" style="text-align: center;">
            <p>You are already logged in.</p>
            <a class="btn btn-primary" href="/organizer/dashboard.php">Open Dashboard</a>
            <a class="btn btn-secondary" href="/logout.php" style="margin-left: 8px;">Log out</a>
        </div>
        <?php else: ?>
        <div class="card">
            <?php if ($error): ?><p class="alert alert-error"><?= sanitize($error) ?></p><?php endif; ?>
            <form method="POST" action="/login.php">
                <?= csrfField() ?>
                <div class="form-group">
                    <label for="api_key">API key</label>
                    <input type="password" id="api_key" name="api_key" required autofocus autocomplete="current-password" placeholder="Organizer API key">
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%;">Log in</button>
            </form>
        </div>
        <?php endif; ?>

        <footer class="footer"><a href="/">Back to home</a></footer>
    </main>
    <script src="/assets/js/theme.js"></script>
</body>
</html>
