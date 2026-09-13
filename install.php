<?php

declare(strict_types=1);
/**
 * Installation Script
 * 
 * Run this once after uploading to your hosting.
 * It creates the database tables and seeds demo classes.
 * 
 * DELETE THIS FILE after installation for security!
 */

// Check the runtime before loading application files that require PHP 8 syntax.
if (version_compare(PHP_VERSION, '8.0.0', '<')) {
    http_response_code(500);
    echo '<!DOCTYPE html><html><body><h1>PHP 8.0+ is required</h1><p>Current PHP version: ' . htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8') . '</p><p>Select PHP 8.0 or newer in the hosting panel, then reload this page.</p></body></html>';
    exit;
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$success = '';
$error = '';
$details = [];
$warnings = [];

/**
 * Compatibility migration for installations where an older includes/db.php was uploaded.
 */
function installTrainingSchemaFallback(): void
{
    $conn = getDB();
    if ($conn instanceof PDO) {
        $columns = [];
        foreach ($conn->query('PRAGMA table_info(demo_classes)') as $column) {
            $columns[] = $column['name'];
        }
        if (!in_array('registration_open', $columns, true)) {
            $conn->exec('ALTER TABLE demo_classes ADD COLUMN registration_open INTEGER NOT NULL DEFAULT 0');
        }
        if (!in_array('capacity', $columns, true)) {
            $conn->exec('ALTER TABLE demo_classes ADD COLUMN capacity INTEGER NULL');
        }
        if (!in_array('topic', $columns, true)) {
            $conn->exec("ALTER TABLE demo_classes ADD COLUMN topic VARCHAR(255) NOT NULL DEFAULT ''");
        }
        if (!in_array('trainer_name', $columns, true)) {
            $conn->exec("ALTER TABLE demo_classes ADD COLUMN trainer_name VARCHAR(255) NOT NULL DEFAULT ''");
        }
        $conn->exec("UPDATE demo_classes SET topic = title WHERE topic = ''");
        $conn->exec("UPDATE demo_classes SET trainer_name = 'Training Team' WHERE trainer_name = ''");
        return;
    }

    $columns = [];
    $result = $conn->query('SHOW COLUMNS FROM demo_classes');
    while ($result && ($column = $result->fetch_assoc())) {
        $columns[] = $column['Field'];
    }
    if (!in_array('registration_open', $columns, true)) {
        $conn->query('ALTER TABLE demo_classes ADD COLUMN registration_open TINYINT(1) NOT NULL DEFAULT 0');
    }
    if (!in_array('capacity', $columns, true)) {
        $conn->query('ALTER TABLE demo_classes ADD COLUMN capacity INT NULL DEFAULT NULL');
    }
    if (!in_array('topic', $columns, true)) {
        $conn->query("ALTER TABLE demo_classes ADD COLUMN topic VARCHAR(255) NOT NULL DEFAULT ''");
    }
    if (!in_array('trainer_name', $columns, true)) {
        $conn->query("ALTER TABLE demo_classes ADD COLUMN trainer_name VARCHAR(255) NOT NULL DEFAULT ''");
    }
    $conn->query("UPDATE demo_classes SET topic = title WHERE topic = ''");
    $conn->query("UPDATE demo_classes SET trainer_name = 'Training Team' WHERE trainer_name = ''");
    $index = $conn->query("SHOW INDEX FROM demo_classes WHERE Key_name = 'idx_public_availability'");
    if (!$index || $index->num_rows === 0) {
        $conn->query('CREATE INDEX idx_public_availability ON demo_classes (status, registration_open)');
    }
}

// Check PHP version
if (version_compare(PHP_VERSION, '8.0.0', '<')) {
    $error = "PHP 8.0+ is required. Your version: " . PHP_VERSION;
}

// Check required extensions — need mysqli OR pdo_mysql for MySQL
$hasMysqli = extension_loaded('mysqli');
$hasPdoMysql = extension_loaded('pdo_mysql');
if (!$hasMysqli && !$hasPdoMysql) {
    $warnings[] = "⚠️ Neither mysqli nor pdo_mysql extension is loaded. MySQL connection may fail.";
}

// Check .env file
if (!file_exists(__DIR__ . '/.env')) {
    $error = "Missing .env file. Copy .env.production to .env and update the database credentials.";
}

// Show what config was loaded (for debugging)
if (empty($error)) {
    $details[] = "📋 <strong>DB_HOST:</strong> " . htmlspecialchars(DB_HOST);
    $details[] = "📋 <strong>DB_PORT:</strong> " . htmlspecialchars(DB_PORT);
    $details[] = "📋 <strong>DB_NAME:</strong> " . htmlspecialchars(DB_NAME);
    $details[] = "📋 <strong>DB_USER:</strong> " . htmlspecialchars(DB_USER);
    $details[] = "📋 <strong>DB_PASS:</strong> " . (DB_PASS ? '(set — hidden)' : '<em>empty</em>');
}

// Test database connection
if (empty($error)) {
    try {
        $conn = getDB();
        $details[] = "✅ <strong>Database connection successful!</strong>";
        
        // Check if SQLite or MySQL
        if ($conn instanceof PDO) {
            $details[] = "📦 Using SQLite database (local file)";
        } else {
            $details[] = "📦 Using MySQL database: " . htmlspecialchars(DB_NAME);
            $details[] = "📦 Server: " . mysqli_get_server_info($conn);
        }
    } catch (Throwable $e) {
        $error = "Database connection failed: " . htmlspecialchars($e->getMessage());
    }
}

// Run installation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error)) {
    try {
        // Initialize database tables
        initDB();
        $details[] = "✅ Database tables created successfully";
        
        // Migrate legacy schema (e.g. zoom_link → teams_link) on existing databases
        migrateLegacyColumns();
        $details[] = "✅ Legacy schema migrations applied";

        // Apply dashboard-managed training fields and indexes.
        if (function_exists('migrateDemoClassManagement')) {
            migrateDemoClassManagement();
        } else {
            installTrainingSchemaFallback();
        }
        $details[] = "✅ Training management schema applied";
        
        // Seed demo classes
        seedDemoClasses();
        $details[] = "✅ Demo classes seeded";
        
        $success = "Installation completed successfully!";
        
    } catch (Throwable $e) {
        $error = "Installation failed: " . htmlspecialchars($e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installation - Freebuff Registration</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #fafafa; color: #262626; padding: 40px 20px; }
        .container { max-width: 650px; margin: 0 auto; }
        .card { background: #fff; border: 1px solid #dbdbdb; border-radius: 12px; padding: 32px; margin-bottom: 20px; }
        h1 { font-size: 1.5rem; margin-bottom: 8px; }
        .subtitle { color: #8e8e8e; font-size: 0.875rem; margin-bottom: 24px; }
        .check { padding: 8px 0; border-bottom: 1px solid #f0f0f0; font-size: 0.875rem; }
        .check:last-child { border-bottom: none; }
        .success { background: #e6f4ea; border: 1px solid #c8e6c9; border-radius: 8px; padding: 16px; color: #137333; margin-bottom: 16px; }
        .error { background: #fff0f0; border: 1px solid #ffcdd2; border-radius: 8px; padding: 16px; color: #ed4956; margin-bottom: 16px; }
        .warning { background: #fff8e1; border: 1px solid #ffe082; border-radius: 8px; padding: 16px; color: #f57f17; margin-bottom: 16px; }
        .btn { display: inline-block; background: #0095f6; color: #fff; border: none; border-radius: 8px; padding: 12px 24px; font-size: 0.9375rem; font-weight: 600; cursor: pointer; text-decoration: none; }
        .btn:hover { background: #1877f2; }
        .btn:disabled { background: #ccc; cursor: not-allowed; }
        code { background: #f0f0f0; padding: 2px 6px; border-radius: 4px; font-size: 0.8125rem; }
        .steps { margin: 16px 0; padding-left: 20px; }
        .steps li { margin-bottom: 8px; font-size: 0.875rem; color: #555; }
        .debug-info { background: #f5f5f5; border: 1px solid #e0e0e0; border-radius: 8px; padding: 16px; margin-top: 12px; font-family: monospace; font-size: 0.8rem; color: #666; white-space: pre-wrap; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <h1>🚀 Freebuff Registration - Installer</h1>
            <p class="subtitle">Follow the steps below to complete installation</p>
            
            <?php if ($error): ?>
            <div class="error"><?= $error ?></div>
            <?php endif; ?>
            
            <?php if (!empty($warnings)): ?>
            <div class="warning">
                <?php foreach ($warnings as $w): ?>
                <div><?= $w ?></div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
            <div class="success">
                <strong>✅ <?= htmlspecialchars($success) ?></strong>
                <p style="margin-top:8px;font-size:0.875rem;">
                    Your site is ready! Visit <a href="register.php" style="color:#137333;">/register.php</a> to see it.
                </p>
                <p style="margin-top:12px;font-size:0.8125rem;color:#c62828;">
                    ⚠️ <strong>Important:</strong> Delete this <code>install.php</code> file for security!
                </p>
            </div>
            <?php endif; ?>
            
            <h3 style="margin-bottom:12px;">System Check</h3>
            
            <div class="check">
                PHP Version: <strong><?= PHP_VERSION ?></strong>
                <?= version_compare(PHP_VERSION, '8.0.0', '>=') ? '✅' : '❌ Requires 8.0+' ?>
            </div>
            
            <div class="check">
                MySQLi Extension: <strong><?= $hasMysqli ? 'Loaded ✅' : 'Missing ❌' ?></strong>
            </div>
            
            <div class="check">
                PDO MySQL: <strong><?= $hasPdoMysql ? 'Loaded ✅' : 'Missing ⚠️' ?></strong>
            </div>
            
            <div class="check">
                .env File: <strong><?= file_exists(__DIR__ . '/.env') ? 'Found ✅' : 'Missing ❌' ?></strong>
            </div>
            
            <div class="check">
                Database: <strong><?= empty($error) ? 'Connected ✅' : 'Error ❌' ?></strong>
            </div>
            
            <?php if (!empty($details)): ?>
            <h3 style="margin:16px 0 8px;">Details</h3>
            <?php foreach ($details as $detail): ?>
            <div class="check"><?= $detail ?></div>
            <?php endforeach; ?>
            <?php endif; ?>
            
            <?php if (empty($error)): ?>
            <form method="POST" style="margin-top:24px;">
                <button type="submit" class="btn">
                    <?= $success ? 'Re-run Installation' : 'Run Installation' ?>
                </button>
            </form>
            <?php endif; ?>
        </div>
        
        <div class="card">
            <h3>📝 Troubleshooting</h3>
            <p style="font-size:0.875rem;color:#555;margin-bottom:12px;">
                If the database connection fails, check these common issues:
            </p>
            <ol class="steps">
                <li><strong>DB_HOST</strong> — On VistaPanel/cPanel, this is usually something like <code>sqlXXX.freehosting.com</code>, NOT <code>localhost</code>. Find it in cPanel → MySQL Databases.</li>
                <li><strong>DB_NAME</strong> — Must include your account prefix (e.g., <code>alalr_42812649_d03944ef_demo_class</code>)</li>
                <li><strong>DB_USER</strong> — Same prefix applies. Must have ALL PRIVILEGES on the database.</li>
                <li><strong>DB_PASS</strong> — The password you set when creating the database user.</li>
            </ol>
        </div>
    </div>
</body>
</html>
