<?php

declare(strict_types=1);
/**
 * Database Connection & Initialization
 * 
 * Supports both MySQL (via MySQLi) and SQLite (via PDO).
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/security_headers.php';

/**
 * Get a database connection (MySQLi or PDO for SQLite)
 * 
 * Throws Exception on failure (instead of die()) so callers can handle it.
 */
function getDB() {
    static $conn = null;
    
    if ($conn !== null) {
        return $conn;
    }
    
    // SQLite mode
    if (DB_HOST === 'sqlite' || DB_HOST === '') {
        $dbPath = defined('DB_SQLITE_PATH')
            ? DB_SQLITE_PATH
            : (config('DB_SQLITE_PATH', '') ?: __DIR__ . '/../data/demo_class.db');
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $conn = new PDO('sqlite:' . $dbPath);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $conn->exec('PRAGMA journal_mode=WAL');
        $conn->exec('PRAGMA foreign_keys=ON');
        return $conn;
    }
    
    // MySQL mode
    $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    if ($conn->connect_error) {
        $errorCode = $conn->connect_errno;
        $errorMsg = $conn->connect_error;
        
        // Provide helpful diagnostic info
        $hint = "";
        if ($errorCode === 2002) {
            $hint = "\n\nPossible causes:\n"
                  . "1. The MySQL host '" . DB_HOST . "' cannot be reached\n"
                  . "2. Check your DB_HOST in .env — VistaPanel hosts are NOT 'localhost'\n"
                  . "3. Find the correct host in cPanel → MySQL Databases → Remote MySQL\n"
                  . "   or check your hosting dashboard for the database server address.";
        } elseif ($errorCode === 1045) {
            $hint = "\n\nAccess denied — check DB_USER and DB_PASS in .env";
        } elseif ($errorCode === 1049) {
            $hint = "\n\nDatabase '" . DB_NAME . "' does not exist.\n"
                  . "Make sure you created it in cPanel → MySQL Databases\n"
                  . "and that DB_NAME matches exactly (including your account prefix).";
        }
        
        throw new Exception(
            "MySQL connection failed (error #$errorCode): $errorMsg" . $hint
        );
    }
    
    $conn->set_charset(DB_CHARSET);
    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
    
    return $conn;
}

function isSQLite(): bool {
    return DB_HOST === 'sqlite' || DB_HOST === '';
}

/**
 * Initialize database tables from schema.sql
 */
function initDB(): void {
    $conn = getDB();
    $schemaFile = __DIR__ . '/../sql/schema.sql';
    
    if (!file_exists($schemaFile)) {
        throw new Exception("Schema file not found: $schemaFile");
    }
    
    $schema = file_get_contents($schemaFile);
    
    $statements = array_filter(
        array_map('trim', explode(';', $schema)),
        fn($s) => !empty($s) && $s !== '--'
    );
    
    foreach ($statements as $statement) {
        $cleaned = preg_replace('/--.*$/m', '', $statement);
        $cleaned = trim($cleaned);
        if (empty($cleaned)) continue;
        
        if (isSQLite()) {
            // Skip MySQL-specific statements
            if (preg_match('/^SET\\s/i', $cleaned)) continue;
            if (strpos($cleaned, 'FOREIGN_KEY_CHECKS') !== false) continue;
            
            // Convert MySQL syntax to SQLite-compatible
            $cleaned = preg_replace('/ENUM\\([^)]+\\)/i', 'TEXT', $cleaned);
            $cleaned = preg_replace('/\\s+ON\\s+UPDATE\\s+CURRENT_TIMESTAMP/i', '', $cleaned);
            $cleaned = preg_replace('/\\s+ENGINE\\s*=\\s*\\w+/i', '', $cleaned);
            $cleaned = preg_replace('/\\s+DEFAULT\\s+CHARSET\\s*=\\s*\\w+/i', '', $cleaned);
            $cleaned = preg_replace('/\\s+COLLATE\\s*=\\s*\\w+/i', '', $cleaned);
            $cleaned = str_replace('`', '', $cleaned);
            
            // Remove inline INDEX definitions (not supported in SQLite CREATE TABLE)
            $cleaned = preg_replace('/^\\s*INDEX\\s+\\w+\\s*\\([^)]+\\)\\s*,?\\s*$/m', '', $cleaned);
            // Remove CONSTRAINT FOREIGN KEY definitions
            $cleaned = preg_replace('/^\\s*CONSTRAINT\\s+\\w+\\s+FOREIGN KEY\\s*\\([^)]+\\)\\s*REFERENCES\\s*[^,)]+\\)\\s*(?:ON DELETE[^,)]*)?\\s*(?:ON UPDATE[^,)]*)?\\s*,?\\s*$/mi', '', $cleaned);
            // Clean up trailing commas before closing paren
            $cleaned = preg_replace('/,\\s*\\)/', ')', $cleaned);
        }
        
        try {
            if ($conn instanceof PDO) {
                $conn->exec($cleaned);
            } else {
                $conn->query($cleaned);
            }
        } catch (Exception $e) {
            if (DEBUG) {
                error_log("[DB] Schema warning: " . $e->getMessage());
            }
        }
    }
    
    if (DEBUG) {
        $dbType = isSQLite() ? 'SQLite' : 'MySQL';
        error_log("[DB] Tables initialized successfully ($dbType)");
    }
}

/**
 * Sync the configured demo class (from config/.env) into the database.
 *
 * The course selected by ACTIVE_COURSE_INDEX is upserted as the single
 * active class; every other demo class is archived so registrations,
 * the API, and the organizer dashboard always reference the same course.
 */
function seedDemoClasses(): void {
    $conn = getDB();

    if ($conn instanceof PDO) {
        $conn->exec('CREATE TABLE IF NOT EXISTS app_metadata (meta_key VARCHAR(100) PRIMARY KEY, meta_value VARCHAR(255) NOT NULL)');
        $marker = $conn->query("SELECT meta_value FROM app_metadata WHERE meta_key = 'legacy_demo_class_seeded'")->fetchColumn();
    } else {
        $conn->query('CREATE TABLE IF NOT EXISTS app_metadata (meta_key VARCHAR(100) PRIMARY KEY, meta_value VARCHAR(255) NOT NULL)');
        $markerResult = $conn->query("SELECT meta_value FROM app_metadata WHERE meta_key = 'legacy_demo_class_seeded'");
        $markerRow = $markerResult ? $markerResult->fetch_assoc() : null;
        $marker = $markerRow['meta_value'] ?? null;
    }
    if ($marker !== false && $marker !== null) {
        return;
    }
    
    // Ensure legacy schema (e.g. zoom_link → teams_link) is migrated first,
    // so this function works regardless of whether the caller ran the migration.
    migrateLegacyColumns();
    
    $configured = configuredDemoClass();
    if (!$configured) {
        if (DEBUG) {
            error_log("[DB] No configured demo class — skipping seed.");
        }
        return;
    }
    
    $id = $configured['id'];
    $teamsLink = !empty($configured['teams_link']) ? $configured['teams_link'] : null;

    // Seed the legacy configured class only for a brand-new empty database.
    // Existing dashboard data, including deliberate deletions, is authoritative.
    if ($conn instanceof PDO) {
        $row = $conn->query('SELECT COUNT(*) AS class_count FROM demo_classes')->fetch(PDO::FETCH_ASSOC);
    } else {
        $result = $conn->query('SELECT COUNT(*) AS class_count FROM demo_classes');
        $row = $result ? $result->fetch_assoc() : ['class_count' => 0];
    }
    $hasExistingClasses = (int)($row['class_count'] ?? 0) > 0;
    
    if (!$hasExistingClasses) {
        // Insert the configured class
        if ($conn instanceof PDO) {
            $stmt = $conn->prepare(
                "INSERT INTO demo_classes (id, title, topic, trainer_name, scheduled_at, timezone, teams_link, status, registration_open) VALUES (?, ?, ?, ?, ?, ?, ?, 'active', 0)"
            );
            $stmt->execute([$id, $configured['title'], $configured['title'], 'Training Team', $configured['scheduled_at'], $configured['timezone'], $teamsLink]);
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO demo_classes (id, title, topic, trainer_name, scheduled_at, timezone, teams_link, status, registration_open) VALUES (?, ?, ?, ?, ?, ?, ?, 'active', 0)"
            );
            $topic = $configured['title'];
            $trainer = 'Training Team';
            $stmt->bind_param('sssssss', $id, $configured['title'], $topic, $trainer, $configured['scheduled_at'], $configured['timezone'], $teamsLink);
            $stmt->execute();
        }
    }

    if ($conn instanceof PDO) {
        $stmt = $conn->prepare("INSERT OR REPLACE INTO app_metadata (meta_key, meta_value) VALUES ('legacy_demo_class_seeded', '1')");
        $stmt->execute();
    } else {
        $stmt = $conn->prepare("INSERT INTO app_metadata (meta_key, meta_value) VALUES ('legacy_demo_class_seeded', '1') ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)");
        $stmt->execute();
    }
    
    if (DEBUG) {
        error_log("[DB] Synced configured demo class: {$configured['title']} (id: $id)");
    }
}

/**
 * Add dashboard-managed class fields to databases created before this feature.
 */
function migrateDemoClassManagement(): void {
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
    $indexExists = false;
    $indexes = $conn->query("SHOW INDEX FROM demo_classes WHERE Key_name = 'idx_public_availability'");
    if ($indexes && $indexes->num_rows > 0) {
        $indexExists = true;
    }
    if (!$indexExists) {
        $conn->query('CREATE INDEX idx_public_availability ON demo_classes (status, registration_open)');
    }
}

/**
 * Apply lightweight migrations for databases created before renames.
 *
 * Converts the legacy `zoom_link` column to `teams_link` and legacy
 * `zoom_link_shared` follow-up values to `teams_link_shared`.
 * Safe to run on fresh databases (statements fail silently).
 */
function migrateLegacyColumns(): void {
    $conn = getDB();

    try {
        if ($conn instanceof PDO) {
            // SQLite: detect the legacy column via PRAGMA
            $hasLegacyColumn = false;
            foreach ($conn->query('PRAGMA table_info(demo_classes)') as $column) {
                if (($column['name'] ?? '') === 'zoom_link') {
                    $hasLegacyColumn = true;
                    break;
                }
            }
            if ($hasLegacyColumn) {
                $conn->exec('ALTER TABLE demo_classes RENAME COLUMN zoom_link TO teams_link');
            }
            // SQLite stores follow_up_type as TEXT, so only values need updating
            $conn->exec(
                "UPDATE manual_follow_up_records SET follow_up_type = 'teams_link_shared' WHERE follow_up_type = 'zoom_link_shared'"
            );
        } else {
            // MySQL: detect the legacy column via SHOW COLUMNS
            $result = $conn->query("SHOW COLUMNS FROM demo_classes LIKE 'zoom_link'");
            if ($result && $result->num_rows > 0) {
                $conn->query('ALTER TABLE demo_classes CHANGE COLUMN zoom_link teams_link VARCHAR(500) NULL DEFAULT NULL');
            }
            $conn->query(
                "UPDATE manual_follow_up_records SET follow_up_type = 'teams_link_shared' WHERE follow_up_type = 'zoom_link_shared'"
            );
            $conn->query(
                "ALTER TABLE manual_follow_up_records MODIFY follow_up_type ENUM("
                . "'announcement_sent','group_invitation_attempted','teams_link_shared','reminder_sent','opt_out_recorded') NOT NULL"
            );
        }
    } catch (Exception $e) {
        if (DEBUG) {
            error_log('[DB] Migration warning: ' . $e->getMessage());
        }
    }
}

/**
 * Add paid-class attributes (is_paid, price) to demo_classes and extend
 * the registrations status enum with 'pending' for payment-in-progress
 * registrations (MySQL only; SQLite stores TEXT so any value is accepted).
 *
 * Idempotent: safe to run on every request.
 */
function migratePaidClasses(): void {
    $conn = getDB();

    if ($conn instanceof PDO) {
        $columns = [];
        foreach ($conn->query('PRAGMA table_info(demo_classes)') as $column) {
            $columns[] = $column['name'];
        }
        if (!in_array('is_paid', $columns, true)) {
            $conn->exec('ALTER TABLE demo_classes ADD COLUMN is_paid INTEGER NOT NULL DEFAULT 0');
        }
        if (!in_array('price', $columns, true)) {
            $conn->exec("ALTER TABLE demo_classes ADD COLUMN price DECIMAL(10,2) NOT NULL DEFAULT 0.00");
        }
        $conn->exec('CREATE INDEX IF NOT EXISTS idx_is_paid ON demo_classes (is_paid)');
        return;
    }

    $columns = [];
    $result = $conn->query('SHOW COLUMNS FROM demo_classes');
    while ($result && ($column = $result->fetch_assoc())) {
        $columns[] = $column['Field'];
    }
    if (!in_array('is_paid', $columns, true)) {
        $conn->query('ALTER TABLE demo_classes ADD COLUMN is_paid TINYINT(1) NOT NULL DEFAULT 0');
    }
    if (!in_array('price', $columns, true)) {
        $conn->query('ALTER TABLE demo_classes ADD COLUMN price DECIMAL(10,2) NOT NULL DEFAULT 0.00');
    }
    $indexExists = false;
    $indexes = $conn->query("SHOW INDEX FROM demo_classes WHERE Key_name = 'idx_is_paid'");
    if ($indexes && $indexes->num_rows > 0) {
        $indexExists = true;
    }
    if (!$indexExists) {
        $conn->query('CREATE INDEX idx_is_paid ON demo_classes (is_paid)');
    }

    // registrations.registration_status gains 'pending' for unpaid paid-class registrations.
    $statusResult = $conn->query("SHOW COLUMNS FROM registrations LIKE 'registration_status'");
    $statusRow = $statusResult ? $statusResult->fetch_assoc() : null;
    if ($statusRow && strpos((string)$statusRow['Type'], 'pending') === false) {
        $conn->query(
            "ALTER TABLE registrations MODIFY registration_status ENUM('submitted','confirmed','failed_delivery','cancelled','pending') NOT NULL DEFAULT 'submitted'"
        );
    }
}

/**
 * Create the payments table used to track UPI transactions.
 *
 * Note on transaction_id: it is NULL until the UPI network assigns the
 * transaction id via callback. MySQL UNIQUE keys allow multiple NULLs, so
 * uniqueness of real transaction ids is still enforced (data-model.md).
 *
 * Idempotent: safe to run on every request.
 */
function migratePayments(): void {
    $conn = getDB();

    if ($conn instanceof PDO) {
        $conn->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS payments (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                transaction_id VARCHAR(64) NULL DEFAULT NULL,
                merchant_order_id VARCHAR(64) NOT NULL,
                user_id VARCHAR(36) NOT NULL,
                class_id VARCHAR(36) NOT NULL,
                registration_id VARCHAR(36) NULL DEFAULT NULL,
                amount DECIMAL(10,2) NOT NULL,
                currency CHAR(3) NOT NULL DEFAULT 'INR',
                payment_method VARCHAR(20) NOT NULL DEFAULT 'UPI',
                status VARCHAR(20) NOT NULL DEFAULT 'initiated'
                    CHECK (status IN ('initiated','pending','success','failed','expired','refunded')),
                payer_vpa VARCHAR(100) NULL DEFAULT NULL,
                raw_response TEXT NULL DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                admin_note TEXT NULL DEFAULT NULL
            )
        SQL);
        $conn->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_payments_transaction_id ON payments (transaction_id)');
        $conn->exec('CREATE INDEX IF NOT EXISTS idx_payments_merchant_order_id ON payments (merchant_order_id)');
        $conn->exec('CREATE INDEX IF NOT EXISTS idx_payments_user_id ON payments (user_id)');
        $conn->exec('CREATE INDEX IF NOT EXISTS idx_payments_class_id ON payments (class_id)');
        $conn->exec('CREATE INDEX IF NOT EXISTS idx_payments_status ON payments (status)');
        $conn->exec('CREATE INDEX IF NOT EXISTS idx_payments_registration_id ON payments (registration_id)');
        $conn->exec('CREATE INDEX IF NOT EXISTS idx_payments_created_at ON payments (created_at)');
        return;
    }

    $conn->query(<<<'SQL'
        CREATE TABLE IF NOT EXISTS payments (
            id VARCHAR(36) NOT NULL PRIMARY KEY,
            transaction_id VARCHAR(64) NULL DEFAULT NULL,
            merchant_order_id VARCHAR(64) NOT NULL,
            user_id VARCHAR(36) NOT NULL,
            class_id VARCHAR(36) NOT NULL,
            registration_id VARCHAR(36) NULL DEFAULT NULL,
            amount DECIMAL(10,2) NOT NULL,
            currency CHAR(3) NOT NULL DEFAULT 'INR',
            payment_method VARCHAR(20) NOT NULL DEFAULT 'UPI',
            status ENUM('initiated','pending','success','failed','expired','refunded') NOT NULL DEFAULT 'initiated',
            payer_vpa VARCHAR(100) NULL DEFAULT NULL,
            raw_response JSON NULL DEFAULT NULL,
            admin_note TEXT NULL DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY idx_payments_transaction_id (transaction_id),
            INDEX idx_payments_merchant_order_id (merchant_order_id),
            INDEX idx_payments_user_id (user_id),
            INDEX idx_payments_class_id (class_id),
            INDEX idx_payments_status (status),
            INDEX idx_payments_registration_id (registration_id),
            INDEX idx_payments_created_at (created_at),
            CONSTRAINT fk_payments_user FOREIGN KEY (user_id) REFERENCES registrants (id) ON DELETE CASCADE,
            CONSTRAINT fk_payments_class FOREIGN KEY (class_id) REFERENCES demo_classes (id) ON DELETE CASCADE,
            CONSTRAINT fk_payments_registration FOREIGN KEY (registration_id) REFERENCES registrations (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    SQL);
}

/**
 * Roll back the paid-classes & payments migration (T043).
 *
 * Drops the payments table, the is_paid/price columns and their index.
 * Intended for manual/ops use — runStartup() never calls this.
 */
function rollbackPaidClassesAndPayments(): void {
    $conn = getDB();

    if ($conn instanceof PDO) {
        $conn->exec('DROP TABLE IF EXISTS payments');
        try {
            $conn->exec('DROP INDEX IF EXISTS idx_is_paid');
        } catch (Exception $e) {
            if (DEBUG) {
                error_log('[DB] Rollback warning: ' . $e->getMessage());
            }
        }
        $columns = [];
        foreach ($conn->query('PRAGMA table_info(demo_classes)') as $column) {
            $columns[] = $column['name'];
        }
        foreach (['price', 'is_paid'] as $column) {
            if (in_array($column, $columns, true)) {
                try {
                    // Requires SQLite >= 3.35
                    $conn->exec("ALTER TABLE demo_classes DROP COLUMN $column");
                } catch (Exception $e) {
                    if (DEBUG) {
                        error_log('[DB] Rollback warning: ' . $e->getMessage());
                    }
                }
            }
        }
        return;
    }

    $conn->query('DROP TABLE IF EXISTS payments');
    $conn->query('ALTER TABLE demo_classes DROP INDEX idx_is_paid');
    $conn->query('ALTER TABLE demo_classes DROP COLUMN price');
    $conn->query('ALTER TABLE demo_classes DROP COLUMN is_paid');
}

/**
 * Training resources (recordings + slides/PDF documents) per class.
 *
 * - recordings reference a dedicated YouTube channel video (unlisted/public);
 *   only the 11-char video id is stored, embeds point at youtube-nocookie.com
 * - slides/pdf reference a Google Drive file; drive_file_id is extracted from
 *   the share link and downloads redirect to Drive (zero server bandwidth)
 * - paid-class resources are gated to attendees by email (download.php)
 *
 * Idempotent: safe to run on every request.
 */
function migrateTrainingResources(): void {
    $conn = getDB();

    if ($conn instanceof PDO) {
        $conn->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS training_resources (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                class_id VARCHAR(36) NOT NULL,
                type VARCHAR(20) NOT NULL
                    CHECK (type IN ('recording','slides','pdf')),
                title VARCHAR(255) NOT NULL,
                youtube_video_id VARCHAR(20) NULL DEFAULT NULL,
                drive_file_id VARCHAR(64) NULL DEFAULT NULL,
                file_name VARCHAR(255) NULL DEFAULT NULL,
                file_size_label VARCHAR(20) NULL DEFAULT NULL,
                download_count INTEGER NOT NULL DEFAULT 0,
                is_published INTEGER NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        SQL);
        $conn->exec('CREATE INDEX IF NOT EXISTS idx_training_resources_class_id ON training_resources (class_id)');
        $conn->exec('CREATE INDEX IF NOT EXISTS idx_training_resources_published ON training_resources (is_published)');
        return;
    }

    $conn->query(<<<'SQL'
        CREATE TABLE IF NOT EXISTS training_resources (
            id VARCHAR(36) NOT NULL PRIMARY KEY,
            class_id VARCHAR(36) NOT NULL,
            type ENUM('recording','slides','pdf') NOT NULL,
            title VARCHAR(255) NOT NULL,
            youtube_video_id VARCHAR(20) NULL DEFAULT NULL,
            drive_file_id VARCHAR(64) NULL DEFAULT NULL,
            file_name VARCHAR(255) NULL DEFAULT NULL,
            file_size_label VARCHAR(20) NULL DEFAULT NULL,
            download_count INT NOT NULL DEFAULT 0,
            is_published TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_training_resources_class_id (class_id),
            INDEX idx_training_resources_published (is_published),
            CONSTRAINT fk_training_resources_class FOREIGN KEY (class_id) REFERENCES demo_classes (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    SQL);
}

/**
 * Roll back the training-resources migration.
 *
 * Drops the training_resources table. Intended for manual/ops use —
 * runStartup() never calls this.
 */
function rollbackTrainingResources(): void {
    $conn = getDB();

    if ($conn instanceof PDO) {
        $conn->exec('DROP TABLE IF EXISTS training_resources');
        return;
    }

    $conn->query('DROP TABLE IF EXISTS training_resources');
}

/**
 * Run startup tasks (init DB, migrations, seed data)
 */
function runStartup(): void {
    initDB();
    migrateLegacyColumns();
    migrateDemoClassManagement();
    migratePaidClasses();
    migratePayments();
    migrateTrainingResources();
    seedDemoClasses();
}
