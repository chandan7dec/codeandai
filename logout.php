<?php

declare(strict_types=1);

/**
 * Organizer logout: clears the session and returns to the login page.
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/security_headers.php';

sendSecurityHeaders();

secureSessionStart();

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool)$p['secure'], (bool)$p['httponly']);
}
session_destroy();

redirectTo('/login.php');
