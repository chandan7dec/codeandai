<?php

declare(strict_types=1);
/**
 * Application Configuration
 * 
 * Equivalent to Python's config.py with pydantic-settings.
 * All settings are configurable via environment variables or a .env file.
 * 
 * IMPORTANT: Some hosts (VistaPanel, free hosting) disable putenv()/getenv().
 * This loader stores values in a static array so it works everywhere.
 */

// ── Internal .env storage (works even when putenv is disabled) ──
$_ENV_LOADED = [];

function loadEnv($path) {
    global $_ENV_LOADED;
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        if ($line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        // Remove surrounding quotes
        if (strlen($value) >= 2 && 
            (($value[0] === '"' && $value[strlen($value)-1] === '"') ||
             ($value[0] === "'" && $value[strlen($value)-1] === "'"))) {
            $value = substr($value, 1, -1);
        }
        // Store in our internal array
        $_ENV_LOADED[$key] = $value;
        // Also try putenv (may be disabled on some hosts — that's OK)
        @putenv("$key=$value");
        $_ENV[$key] = $value;
    }
}
// Commnet this code in production
// Load from .env file in project root. Prefer .env.local for local dev if present.
if (file_exists(__DIR__ . '/.env.local')) {
    loadEnv(__DIR__ . '/.env.local');
} else {
    loadEnv(__DIR__ . '/.env');
}

/**
 * Helper to get config value — reads from internal array first,
 * then falls back to getenv, then default.
 * This ensures it works even when putenv/getenv are disabled.
 */
function config($key, $default = '') {
    global $_ENV_LOADED;
    
    // 1. Try our internal .env array first (most reliable)
    if (isset($_ENV_LOADED[$key]) && $_ENV_LOADED[$key] !== '') {
        $value = $_ENV_LOADED[$key];
    }
    // 2. Try $_ENV superglobal
    elseif (isset($_ENV[$key]) && $_ENV[$key] !== '') {
        $value = $_ENV[$key];
    }
    // 3. Try $_SERVER superglobal
    elseif (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
        $value = $_SERVER[$key];
    }
    // 4. Try getenv (may be disabled)
    elseif (($envValue = @getenv($key)) !== false && $envValue !== '') {
        $value = $envValue;
    }
    else {
        return $default;
    }
    
    // Handle boolean strings
    if ($value === 'true') return true;
    if ($value === 'false') return false;
    return $value;
}

// ── Application ──────────────────────────────────────────────
define('APP_NAME', config('APP_NAME', 'Freebuff Registration'));
define('APP_VERSION', config('APP_VERSION', '0.1.0'));
define('DEBUG', config('DEBUG', false));
define('BASE_URL', config('BASE_URL', 'http://localhost:8000'));

// ── Database (MySQL) ─────────────────────────────────────────
define('DB_HOST', config('DB_HOST', 'localhost'));
define('DB_PORT', config('DB_PORT', '3306'));
define('DB_NAME', config('DB_NAME', 'demo_class'));
define('DB_USER', config('DB_USER', 'root'));
define('DB_PASS', config('DB_PASS', ''));
define('DB_CHARSET', config('DB_CHARSET', 'utf8mb4'));

// ── Security ─────────────────────────────────────────────────
define('SECRET_KEY', config('SECRET_KEY', 'change-this-in-production'));
define('ORGANIZER_API_KEY', config('ORGANIZER_API_KEY', 'organizer-secret-key'));

// ── Registration Form ────────────────────────────────────────
define('REGISTRATION_FORM_HEADING', config('REGISTRATION_FORM_HEADING', 'Sign Up for Boot Camp'));

// Index (0-based) of the active course in the DEMO_CLASSES catalog below.
// Change this flag in .env to make a different course live on the registration page.
define('ACTIVE_COURSE_INDEX', (int)config('ACTIVE_COURSE_INDEX', 0));

// ── WhatsApp ─────────────────────────────────────────────────
define('WHATSAPP_GROUP_INVITE_URL', config('WHATSAPP_GROUP_INVITE_URL', 'https://chat.whatsapp.com/DsI4j6teaeU72QknuAcX8b'));
define('WHATSAPP_GROUP_NAME', config('WHATSAPP_GROUP_NAME', 'LearnAI'));
define('WHATSAPP_REDIRECT_BASE', config('WHATSAPP_REDIRECT_BASE', 'https://wa.me'));

// ── UPI Payments (direct UPI integration, no gateway) ───────
define('UPI_MERCHANT_VPA', config('UPI_MERCHANT_VPA', 'merchant@upi'));
define('UPI_MERCHANT_NAME', config('UPI_MERCHANT_NAME', 'Freebuff Classes'));
define('UPI_CALLBACK_SECRET', config('UPI_CALLBACK_SECRET', 'change-this-callback-secret'));
define('UPI_PAYMENT_TIMEOUT_MINUTES', (int)config('UPI_PAYMENT_TIMEOUT_MINUTES', 15));
define('UPI_CALLBACK_URL', config('UPI_CALLBACK_URL', BASE_URL . '/organizer/upi-callback.php'));

// ── Email (SendGrid via SMTP) ────────────────────────────────
define('ENABLE_EMAIL', config('ENABLE_EMAIL', false));
define('SMTP_HOST', config('SMTP_HOST', 'smtp.gmail.com'));
define('SMTP_PORT', (int)config('SMTP_PORT', 587));
define('SMTP_USER', config('SMTP_USER', ''));
define('SMTP_PASS', config('SMTP_PASS', ''));
define('SMTP_USE_TLS', config('SMTP_USE_TLS', true));
define('SMTP_FROM_EMAIL', config('SMTP_FROM_EMAIL', ''));
define('SMTP_FROM_NAME', config('SMTP_FROM_NAME', 'Freebuff Registration'));

// ── Demo Classes (course catalog) ─────────────────────────────
// The full list of courses. The active one is selected by the
// ACTIVE_COURSE_INDEX flag above (0-based index into this array).
define('DEMO_CLASSES', [
    [
        'title' => 'Python for AI',
        'scheduled_at' => '2026-09-05 17:00:00',
        'timezone' => 'Asia/Kolkata',
        'teams_link' => 'https://teams.microsoft.com/l/meetup-join/19:meeting_demo1@thread.v2/0',
    ],
    [
        'title' => 'Python for Beginners',
        'scheduled_at' => '2026-08-26 11:33:22',
        'timezone' => 'America/Chicago',
        'teams_link' => 'https://teams.microsoft.com/l/meetup-join/19:meeting_demo2@thread.v2/0',
    ],
    [
        'title' => 'Web Development Bootcamp Preview',
        'scheduled_at' => '2026-08-27 11:33:22',
        'timezone' => 'America/Los_Angeles',
        'teams_link' => 'https://teams.microsoft.com/l/meetup-join/19:meeting_demo3@thread.v2/0',
    ],
    [
        'title' => 'Machine Learning Fundamentals',
        'scheduled_at' => '2026-08-28 11:33:22',
        'timezone' => 'America/New_York',
        'teams_link' => '',
    ],
    [
        'title' => 'Cloud Computing Workshop',
        'scheduled_at' => '2026-08-29 11:33:22',
        'timezone' => 'Europe/London',
        'teams_link' => 'https://teams.microsoft.com/l/meetup-join/19:meeting_demo5@thread.v2/0',
    ],
]);

/**
 * Get the currently active demo class from the course catalog.
 *
 * The live course is chosen by the ACTIVE_COURSE_INDEX flag (from .env).
 * Falls back to the first course if the index is out of range.
 * The stable id (demo-class-<index>) is what registrations reference in the DB.
 */
function configuredDemoClass(): ?array {
    $index = ACTIVE_COURSE_INDEX;
    if (!isset(DEMO_CLASSES[$index])) {
        if (DEBUG) {
            error_log("[CONFIG] ACTIVE_COURSE_INDEX=$index out of range; falling back to course 0");
        }
        $index = 0;
    }
    if (!isset(DEMO_CLASSES[$index])) {
        return null;
    }
    $class = DEMO_CLASSES[$index];
    return [
        'id' => 'demo-class-' . $index,
        'title' => $class['title'],
        'scheduled_at' => $class['scheduled_at'],
        'timezone' => $class['timezone'],
        'teams_link' => $class['teams_link'] ?? '',
    ];
}
