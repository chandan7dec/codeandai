<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap.
 *
 * Forces SQLite in-memory database so tests are hermetic and fast,
 * regardless of the developer's .env / .env.local settings.
 */

// Ensure project root is on the include path.
$projectRoot = dirname(__DIR__);
define('PROJECT_ROOT', $projectRoot);

// Isolate test environment: in-memory SQLite + no email + no external calls.
$_ENV_LOADED = $_ENV_LOADED ?? [];
$_ENV_LOADED['DB_HOST'] = 'sqlite';
$_ENV_LOADED['DB_SQLITE_PATH'] = ':memory:';
$_ENV_LOADED['DEBUG'] = 'false';
$_ENV_LOADED['ENABLE_EMAIL'] = 'false';
$_ENV_LOADED['UPI_MERCHANT_VPA'] = $_ENV_LOADED['UPI_MERCHANT_VPA'] ?? 'merchant@upi';
$_ENV_LOADED['UPI_MERCHANT_NAME'] = $_ENV_LOADED['UPI_MERCHANT_NAME'] ?? 'Freebuff Classes';
$_ENV_LOADED['UPI_CALLBACK_SECRET'] = $_ENV_LOADED['UPI_CALLBACK_SECRET'] ?? 'test-callback-secret';
$_ENV_LOADED['UPI_PAYMENT_TIMEOUT_MINUTES'] = $_ENV_LOADED['UPI_PAYMENT_TIMEOUT_MINUTES'] ?? '15';

require_once $projectRoot . '/config.php';

// db.php honours this constant before falling back to the .env/file default.
if (!defined('DB_SQLITE_PATH')) {
    define('DB_SQLITE_PATH', ':memory:');
}

require_once $projectRoot . '/includes/functions.php';
require_once $projectRoot . '/includes/db.php';

// When PHPUnit is genuinely installed (composer install), it autoloads its own
// classes and this file is loaded as bootstrap; nothing to do. When it is not
// installed (no composer/network), a shim is loaded by tools/run_tests.php.

// getDB() caches the connection statically, so bootstrap the schema once here.
runStartup();

if (DEBUG) {
    error_log('[TEST] Bootstrap complete: SQLite in-memory database initialized.');
}
