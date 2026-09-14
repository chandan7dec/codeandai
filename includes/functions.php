<?php

declare(strict_types=1);
/**
 * Helper Functions
 * 
 * Common utilities used across the application.
 */

/**
 * Generate a UUID v4
 */
function generateUUID(): string {
    $data = random_bytes(16);
    
    // Set version to 0100
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    // Set variant to 10xx
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Sanitize input for XSS prevention
 */
function sanitize(string $input): string {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Get current UTC datetime string
 */
function utcnow(): string {
    return gmdate('Y-m-d H:i:s');
}

/**
 * Normalize phone number for duplicate comparison
 * Equivalent to RegistrationService.normalize_phone()
 */
function normalizePhone(string $phone): string {
    // Remove all non-digit characters except leading +
    $cleaned = preg_replace('/[^\d+]/', '', $phone);
    // Remove leading + if present
    if (strpos($cleaned, '+') === 0) {
        $cleaned = substr($cleaned, 1);
    }
    // Remove leading 00 or 011 (international dialing prefixes)
    if (strpos($cleaned, '00') === 0) {
        $cleaned = substr($cleaned, 2);
    } elseif (strpos($cleaned, '011') === 0) {
        $cleaned = substr($cleaned, 3);
    }
    return $cleaned;
}

/**
 * Normalize email for duplicate comparison
 */
function normalizeEmail(string $email): string {
    return strtolower(trim($email));
}

/**
 * Generate duplicate key for registration prevention
 */
function generateDuplicateKey(string $email, string $phone, string $demoClassId): string {
    $normalizedEmail = normalizeEmail($email);
    $normalizedPhone = normalizePhone($phone);
    $keyString = "$normalizedEmail:$normalizedPhone:$demoClassId";
    return hash('sha256', $keyString);
}

/**
 * Validate email format
 */
function isValidEmail(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate phone number (at least 7 digits after cleaning)
 */
function isValidPhone(string $phone): bool {
    $cleaned = preg_replace('/[^\d+]/', '', $phone);
    return strlen($cleaned) >= 7;
}

/**
 * Shared site footer.
 *
 * Line 1: navigation links to every public page (the current page's own
 *         link is omitted automatically).
 * Line 2: centered Instagram-style gradient icon + "Code & AI" wordmark.
 *
 * $extra: optional HTML injected above the nav (flow pages use it for
 *         context notes like "Need help? Contact the organizer").
 */
function siteFooter(string $extra = ''): void {
    $links = [
        '/' => 'Home',
        '/training-calendar.php' => 'Training Calendar',
        '/resources.php' => 'Resources',
        '/certification.php' => 'Certification',
        '/trainers.php' => 'Our Trainers',
        '/register.php' => 'Register',
        '/dashboard.php' => 'My Dashboard',
    ];
    $current = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $base = '/' . basename($current);
    $nav = [];
    foreach ($links as $href => $label) {
        if ($href === $current || ($base === $href && $href !== '/')) {
            continue; // don't link the page the visitor is already on
        }
        $nav[] = '<a href="' . $href . '">' . $label . '</a>';
    }
    echo '<footer class="footer site-footer">';
    if ($extra !== '') {
        echo $extra;
    }
    echo '<nav class="footer-nav" aria-label="Site footer">'
        . implode(' <span class="footer-sep" aria-hidden="true">&middot;</span> ', $nav)
        . '</nav>';
    echo '<div class="footer-brand">'
        . '<svg class="footer-mark" width="18" height="18" viewBox="0 0 48 48" fill="none" aria-hidden="true">'
        . '<defs><linearGradient id="footerGrad" x1="0" y1="48" x2="48" y2="0">'
        . '<stop offset="0%" stop-color="#f09433"/><stop offset="50%" stop-color="#dc2743"/><stop offset="100%" stop-color="#bc1888"/></linearGradient></defs>'
        . '<rect x="2" y="2" width="44" height="44" rx="12" stroke="url(#footerGrad)" stroke-width="4" fill="none"/>'
        . '<circle cx="24" cy="24" r="10" stroke="url(#footerGrad)" stroke-width="4" fill="none"/>'
        . '<circle cx="37" cy="11" r="2.5" fill="url(#footerGrad)"/></svg>'
        . '<span class="footer-wordmark">Code <span class="text-gradient">&amp;</span> AI</span>'
        . '</div>';
    echo '</footer>';
}

/**
 * Format scheduled date for display
 */
function formatScheduledDate(string $scheduledAt, string $timezone): string {
    try {
        $dt = new DateTime($scheduledAt, new DateTimeZone($timezone));
        return $dt->format('M d, Y \a\t h:i A T');
    } catch (Exception $e) {
        return $scheduledAt;
    }
}

/**
 * Format a UTC timestamp in a given timezone (for registration/payment dates).
 * Timestamps are stored in UTC (utcnow()); the class timezone is used for display.
 */
function formatUtcDateInTimezone(?string $utcTimestamp, string $timezone): string {
    if ($utcTimestamp === null || $utcTimestamp === '') {
        return '-';
    }
    try {
        $dt = new DateTimeImmutable($utcTimestamp, new DateTimeZone('UTC'));
        return $dt->setTimezone(new DateTimeZone($timezone))->format('d M Y, h:i A');
    } catch (Exception $e) {
        return $utcTimestamp;
    }
}

/**
 * CSRF token helpers (per-session token, verified on POST).
 *
 * Sessions are already started by the public pages; these are no-ops if not.
 */
function csrfToken(): string {
    secureSessionStart();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_token'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . sanitize(csrfToken()) . '">';
}

function csrfVerify(?string $token): bool {
    secureSessionStart();
    return is_string($token)
        && $token !== ''
        && !empty($_SESSION['csrf_token'])
        && hash_equals((string)$_SESSION['csrf_token'], $token);
}

/**
 * File-based per-IP rate limiter (sliding window).
 *
 * Same storage approach as the UPI callback limiter, but generic and shared:
 * state lives in data/rate-limit/<sha256(ip)>.json.
 *
 * @return bool true when the request is allowed (and counted)
 */
function rateLimitRequest(string $identifier, int $limit = 10, int $windowSeconds = 600): bool {
    $dir = __DIR__ . '/../data/rate-limit';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $file = $dir . '/' . hash('sha256', $identifier) . '.json';
    $now = time();
    $hits = [];
    if (is_file($file)) {
        $hits = json_decode((string)file_get_contents($file), true) ?: [];
    }
    $hits = array_values(array_filter($hits, static fn ($t): bool => (int)$t > $now - $windowSeconds));
    if (count($hits) >= $limit) {
        return false;
    }
    $hits[] = $now;
    file_put_contents($file, json_encode($hits), LOCK_EX);
    return true;
}

function clientIp(): string {
    return (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

/**
 * Session bootstrap with hardened cookie flags.
 *
 * Call instead of bare session_start() everywhere. The params call is a
 * no-op when headers were already sent (e.g. mid-output diagnostics).
 */
function secureSessionStart(): void {
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }
    if (!headers_sent()) {
        @session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
                          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
        ]);
    }
    session_start();
}

/**
 * Organizer authentication: session login OR API key (header / query param).
 * Used by the organizer dashboard and its API endpoints.
 */
function organizerAuthenticated(): bool {
    secureSessionStart();
    if (!empty($_SESSION['organizer_authenticated'])) {
        return true;
    }
    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? '';
    return is_string($apiKey) && $apiKey !== '' && hash_equals(ORGANIZER_API_KEY, $apiKey);
}

/**
 * Google Calendar "add event" link for a training session.
 *
 * @param string $scheduledAt UTC datetime string (Y-m-d H:i:s)
 * @param string $timezone    class timezone (used only to compute the duration window)
 * @param array  $class       title/topic/trainer/scheduled_at/timezone fields
 */
function googleCalendarLink(array $class): string {
    try {
        $start = new DateTimeImmutable($class['scheduled_at'], new DateTimeZone($class['timezone']));
    } catch (Exception $e) {
        return '';
    }
    $utc = new DateTimeZone('UTC');
    $start = $start->setTimezone($utc);
    $end = $start->modify('+2 hours')->setTimezone($utc);
    $fmt = 'Ymd\THis\Z';
    $params = http_build_query([
        'action' => 'TEMPLATE',
        'text'   => $class['title'],
        'dates'  => $start->format($fmt) . '/' . $end->format($fmt),
        'details' => trim(($class['topic'] ? 'Topic: ' . $class['topic'] . "\n" : '')
            . ($class['trainer_name'] ? 'Trainer: ' . $class['trainer_name'] . "\n" : '')),
        'location' => $class['teams_link'] ?? '',
    ]);
    return 'https://calendar.google.com/calendar/render?' . $params;
}

/**
 * Send JSON response
 */
function jsonResponse(array $data, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Send HTML error response
 */
function htmlError(string $message, int $statusCode = 500): void {
    http_response_code($statusCode);
    echo "<h1>Error</h1><p>" . sanitize($message) . "</p>";
    exit;
}

/**
 * Redirect to URL
 */
function redirectTo(string $url): void {
    header("Location: $url");
    exit;
}

/**
 * Get the current request URI path
 */
function getRequestPath(): string {
    $uri = $_SERVER['REQUEST_URI'];
    // Remove query string
    $path = parse_url($uri, PHP_URL_PATH);
    return $path;
}

/**
 * Check if request method matches
 */
function isMethod(string $method): bool {
    return $_SERVER['REQUEST_METHOD'] === strtoupper($method);
}

/**
 * Get request body as associative array (for JSON requests)
 */
function getJsonBody(): array {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    return is_array($data) ? $data : [];
}
