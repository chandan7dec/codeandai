<?php
/**
 * Database Connection & Initialization
 * 
 * Supports both MySQL (via MySQLi) and SQLite (via PDO).
 */

require_once __DIR__ . '/../config.php';

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
        $dbPath = defined('DB_SQLITE_PATH') ? DB_SQLITE_PATH : __DIR__ . '/../data/demo_class.db';
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
 * Run startup tasks (init DB, migrations, seed data)
 */
function runStartup(): void {
    initDB();
    migrateLegacyColumns();
    migrateDemoClassManagement();
    seedDemoClasses();
}
