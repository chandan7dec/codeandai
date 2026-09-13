<?php

declare(strict_types=1);
/**
 * Registration Service
 * 
 * Business logic for registrations.
 * Supports both MySQL (MySQLi) and SQLite (PDO).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/class_management_service.php';
require_once __DIR__ . '/upi_service.php';

class RegistrationService {
    private $db;
    
    public function __construct() {
        $this->db = getDB();
    }
    
    /**
     * Execute a query and return results as array
     */
    private function query(string $sql, array $params = [], string $types = ''): array {
        if ($this->db instanceof PDO) {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // MySQLi
        $stmt = $this->db->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }
    
    /**
     * Execute a query and return single row
     */
    private function queryOne(string $sql, array $params = [], string $types = ''): ?array {
        $rows = $this->query($sql, $params, $types);
        return !empty($rows) ? $rows[0] : null;
    }
    
    /**
     * Execute an insert/update/delete and return affected rows
     */
    private function execute(string $sql, array $params = [], string $types = ''): int {
        if ($this->db instanceof PDO) {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        }
        
        $stmt = $this->db->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        return $stmt->affected_rows;
    }
    
    /**
     * Get the currently active demo class
     */
    public function getActiveDemoClass(): ?array {
        return $this->queryOne(
            "SELECT * FROM demo_classes WHERE status = 'active' ORDER BY scheduled_at ASC LIMIT 1"
        );
    }
    
    /**
     * Get all active demo classes
     */
    public function getActiveDemoClasses(): array {
        return $this->query("SELECT * FROM demo_classes WHERE status = 'active' ORDER BY scheduled_at ASC");
    }
    
    /**
     * Get all demo classes (for organizer dashboard)
     */
    public function getAllDemoClasses(): array {
        return $this->query("SELECT * FROM demo_classes ORDER BY scheduled_at ASC");
    }
    
    /**
     * Check for duplicate registration
     */
    public function checkDuplicate(string $email, string $phone, string $demoClassId): ?array {
        $duplicateKey = generateDuplicateKey($email, $phone, $demoClassId);
        return $this->queryOne(
            "SELECT * FROM registrations WHERE duplicate_key = ? AND registration_status != 'cancelled'",
            [$duplicateKey],
            's'
        );
    }
    
    /**
     * Get or create registrant
     */
    public function getOrCreateRegistrant(string $name, string $email, string $phone, bool $whatsappConsent): array {
        $normalizedEmail = normalizeEmail($email);
        $normalizedPhone = normalizePhone($phone);
        
        // Try to find existing registrant
        $registrant = $this->queryOne(
            "SELECT * FROM registrants WHERE email = ? OR phone_number = ? LIMIT 1",
            [$normalizedEmail, $normalizedPhone],
            'ss'
        );
        
        if ($registrant) {
            // Update existing registrant
            $consentAt = $whatsappConsent && !$registrant['wa_consent_at'] ? utcnow() : $registrant['wa_consent_at'];
            $this->execute(
                "UPDATE registrants SET name = ?, consented_to_whatsapp = ?, wa_consent_at = ? WHERE id = ?",
                [$name, $whatsappConsent ? 1 : 0, $consentAt, $registrant['id']],
                'siss'
            );
            
            // Return updated registrant
            return $this->queryOne("SELECT * FROM registrants WHERE id = ?", [$registrant['id']], 's');
        }
        
        // Create new registrant
        $id = generateUUID();
        $consentAt = $whatsappConsent ? utcnow() : null;
        $now = utcnow();
        
        $this->execute(
            "INSERT INTO registrants (id, name, email, phone_number, consented_to_whatsapp, wa_consent_at, privacy_notice_accepted_at, created_at, updated_at) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$id, $name, $normalizedEmail, $normalizedPhone, $whatsappConsent ? 1 : 0, $consentAt, $now, $now, $now],
            'sssssssss'
        );
        
        return $this->queryOne("SELECT * FROM registrants WHERE id = ?", [$id], 's');
    }
    
    /**
     * Create a new registration
     */
    public function createRegistration(string $name, string $email, string $phone, bool $whatsappConsent = false): array {
        // Validate input data
        $errors = $this->validateRegistrationData($name, $email, $phone);
        if (!empty($errors)) {
            throw new InvalidArgumentException(implode('; ', $errors));
        }
        
        $transactionStarted = false;
        try {
            if ($this->db instanceof PDO) {
                $this->db->beginTransaction();
            } else {
                $this->db->begin_transaction();
            }
            $transactionStarted = true;

            // Lock the selected class row so concurrent requests cannot pass the same capacity check.
            $classLock = $this->db instanceof PDO ? '' : ' FOR UPDATE';
            $demoClass = $this->queryOne(
                "SELECT * FROM demo_classes WHERE status = 'active' AND registration_open = 1 ORDER BY scheduled_at ASC LIMIT 1$classLock"
            );
            if (!$demoClass) {
                throw new InvalidArgumentException("No active demo class is currently available. Please contact the organizer.");
            }

            $countRow = $this->queryOne(
                "SELECT COUNT(*) AS registration_count FROM registrations WHERE demo_class_id = ? AND registration_status != 'cancelled'",
                [$demoClass['id']],
                's'
            );
            if ($demoClass['capacity'] !== null && (int)$countRow['registration_count'] >= (int)$demoClass['capacity']) {
                throw new InvalidArgumentException('This class is full. Please choose another class.');
            }

            // Check for duplicate registration (cancelled ones allow retry)
            $existing = $this->checkDuplicate($email, $phone, $demoClass['id']);
            if ($existing) {
                throw new InvalidArgumentException(
                    "You are already registered for this class. Please check your email for confirmation details."
                );
            }

            // Paid classes hold the seat with a pending registration until the
            // UPI payment succeeds; free classes are confirmed immediately.
            $isPaid = (int)($demoClass['is_paid'] ?? 0) === 1;
            $price = (float)($demoClass['price'] ?? 0);
            $registrationStatus = $isPaid ? 'pending' : 'confirmed';
            $confirmationMessage = $isPaid ? 'Payment pending - complete the UPI payment to confirm your seat' : 'Registration confirmed successfully';

            // Get or create registrant
            $registrant = $this->getOrCreateRegistrant($name, $email, $phone, $whatsappConsent);

            // Generate duplicate key
            $duplicateKey = generateDuplicateKey($email, $phone, $demoClass['id']);

            // Create registration
            $registrationId = generateUUID();
            $now = utcnow();

            $this->execute(
                "INSERT INTO registrations (id, registrant_id, demo_class_id, registration_status, confirmation_message, duplicate_key, submitted_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$registrationId, $registrant['id'], $demoClass['id'], $registrationStatus, $confirmationMessage, $duplicateKey, $now, $now, $now],
                'ssssssss'
            );

            // For paid classes also create the payment record inside the same
            // transaction so capacity locking covers the seat + payment together.
            $payment = null;
            if ($isPaid) {
                $paymentId = generateUUID();
                $merchantOrderId = 'ORD_' . strtoupper(bin2hex(random_bytes(9)));
                $this->execute(
                    "INSERT INTO payments (id, transaction_id, merchant_order_id, user_id, class_id, registration_id, amount, currency, payment_method, status, created_at, updated_at)
                     VALUES (?, NULL, ?, ?, ?, ?, ?, 'INR', 'UPI', 'initiated', ?, ?)",
                    [$paymentId, $merchantOrderId, $registrant['id'], $demoClass['id'], $registrationId, number_format($price, 2, '.', ''), $now, $now],
                    'ssssssss'
                );
                $payment = [
                    'id' => $paymentId,
                    'merchant_order_id' => $merchantOrderId,
                    'amount' => number_format($price, 2, '.', ''),
                    'status' => 'initiated',
                ];
            }
        
        // Record consent if given
        if ($whatsappConsent) {
            $consentId = generateUUID();
            $this->execute(
                "INSERT INTO whatsapp_consent_records (id, registration_id, action, consent_state, reason, recorded_at) 
                 VALUES (?, ?, 'consent', 1, 'User opted in during registration', ?)",
                [$consentId, $registrationId, $now],
                'sss'
            );
        }
        
        // Format scheduled date
        $formattedDate = formatScheduledDate($demoClass['scheduled_at'], $demoClass['timezone']);
        
            $result = [
            'registration' => [
                'id' => $registrationId,
                'status' => $registrationStatus,
                'message' => $confirmationMessage,
            ],
            'demo_class' => [
                'id' => $demoClass['id'],
                'title' => $demoClass['title'],
                'scheduled_at' => $formattedDate,
                'timezone' => $demoClass['timezone'],
                'teams_link' => $demoClass['teams_link'],
            ],
            'whatsapp_consent' => $whatsappConsent,
            'whatsapp_redirect_url' => "/whatsapp/redirect.php?id=$registrationId",
            'requires_payment' => $isPaid,
            ];

            if ($isPaid && $payment !== null) {
                // QR generation happens after the DB work (CPU-heavy, no I/O).
                $result['payment'] = $payment + [
                    'qr_code' => UpiService::generateUpiQrCode($payment['merchant_order_id'], $price, $demoClass['id']),
                    'upi_payload' => UpiService::buildUpiPayload($payment['merchant_order_id'], $price),
                    'callback_url' => UPI_CALLBACK_URL,
                    'timeout_minutes' => UPI_PAYMENT_TIMEOUT_MINUTES,
                ];
            }
            if ($this->db instanceof PDO) {
                $this->db->commit();
            } else {
                $this->db->commit();
            }
            return $result;
        } catch (Throwable $exception) {
            if ($transactionStarted) {
                if ($this->db instanceof PDO) {
                    if ($this->db->inTransaction()) $this->db->rollBack();
                } else {
                    $this->db->rollback();
                }
            }
            throw $exception;
        }
    }
    
    /**
     * Validate registration data
     */
    private function validateRegistrationData(string $name, string $email, string $phone): array {
        $errors = [];
        
        if (empty(trim($name))) {
            $errors[] = "Name is required";
        }
        
        if (empty(trim($email))) {
            $errors[] = "Email is required";
        } elseif (!isValidEmail($email)) {
            $errors[] = "Invalid email format";
        }
        
        if (empty(trim($phone))) {
            $errors[] = "Phone number is required";
        } elseif (!isValidPhone($phone)) {
            $errors[] = "Invalid phone number";
        }
        
        return $errors;
    }
    
    /**
     * Get registrations with filters (for organizer dashboard)
     */
    public function getRegistrations(?string $demoClassId = null, ?bool $whatsappConsent = null, ?string $search = null, ?string $status = null): array {
        $sql = "SELECT r.*, reg.name as registrant_name, reg.email as registrant_email, reg.phone_number, 
                       reg.consented_to_whatsapp, dc.title as class_title, dc.timezone
                FROM registrations r
                JOIN registrants reg ON r.registrant_id = reg.id
                JOIN demo_classes dc ON r.demo_class_id = dc.id
                WHERE 1=1";
        
        $params = [];
        $types = '';
        
        if ($demoClassId) {
            $sql .= " AND r.demo_class_id = ?";
            $params[] = $demoClassId;
            $types .= 's';
        }
        
        if ($status) {
            $sql .= " AND r.registration_status = ?";
            $params[] = $status;
            $types .= 's';
        }
        
        if ($search) {
            $searchTerm = "%$search%";
            $sql .= " AND (reg.name LIKE ? OR reg.email LIKE ? OR reg.phone_number LIKE ?)";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $types .= 'sss';
        }
        
        $sql .= " ORDER BY r.created_at DESC";
        
        $registrations = $this->query($sql, $params, $types);
        
        // Filter by WhatsApp consent if specified (done in PHP since it's in a related table)
        if ($whatsappConsent !== null) {
            $registrations = array_filter($registrations, function($row) use ($whatsappConsent) {
                return (bool)$row['consented_to_whatsapp'] === $whatsappConsent;
            });
            $registrations = array_values($registrations);
        }
        
        return $registrations;
    }
    
    /**
     * Get a single registration by ID
     */
    public function getRegistration(string $id): ?array {
        return $this->queryOne("SELECT * FROM registrations WHERE id = ?", [$id], 's');
    }
    
    /**
     * Record a manual follow-up action
     */
    public function recordFollowUp(string $registrationId, string $followUpType, string $outcome = 'pending', ?string $notes = null): array {
        // Validate registration exists
        $registration = $this->getRegistration($registrationId);
        if (!$registration) {
            throw new InvalidArgumentException("Registration not found");
        }
        
        // Validate follow-up type
        $validTypes = ['announcement_sent', 'group_invitation_attempted', 'teams_link_shared', 'reminder_sent', 'opt_out_recorded'];
        if (!in_array($followUpType, $validTypes)) {
            throw new InvalidArgumentException("Invalid follow-up type. Must be one of: " . implode(', ', $validTypes));
        }
        
        // Validate outcome
        $validOutcomes = ['pending', 'attempted', 'completed', 'blocked'];
        if (!in_array($outcome, $validOutcomes)) {
            throw new InvalidArgumentException("Invalid outcome. Must be one of: " . implode(', ', $validOutcomes));
        }
        
        // Check consent status for WhatsApp-related follow-ups
        $whatsappTypes = ['group_invitation_attempted', 'announcement_sent', 'reminder_sent'];
        if (in_array($followUpType, $whatsappTypes)) {
            $registrant = $this->queryOne("SELECT * FROM registrants WHERE id = ?", [$registration['registrant_id']], 's');
            
            if (!$registrant['consented_to_whatsapp']) {
                throw new InvalidArgumentException(
                    "Cannot perform WhatsApp follow-up: registrant has not consented to WhatsApp communication"
                );
            }
            if ($registrant['wa_consent_withdrawn_at']) {
                throw new InvalidArgumentException(
                    "Cannot perform WhatsApp follow-up: registrant has withdrawn WhatsApp consent"
                );
            }
        }
        
        // Create follow-up record
        $id = generateUUID();
        $now = utcnow();
        
        $this->execute(
            "INSERT INTO manual_follow_up_records (id, registration_id, follow_up_type, outcome, notes, created_at) 
             VALUES (?, ?, ?, ?, ?, ?)",
            [$id, $registrationId, $followUpType, $outcome, $notes, $now],
            'ssssss'
        );
        
        return [
            'id' => $id,
            'registration_id' => $registrationId,
            'type' => $followUpType,
            'outcome' => $outcome,
            'created_at' => $now,
        ];
    }
    
    /**
     * Delete a registration and all related records
     */
    public function deleteRegistration(string $registrationId): bool {
        $registration = $this->getRegistration($registrationId);
        if (!$registration) {
            return false;
        }
        
        // Delete related records first
        $this->execute("DELETE FROM whatsapp_consent_records WHERE registration_id = ?", [$registrationId], 's');
        $this->execute("DELETE FROM manual_follow_up_records WHERE registration_id = ?", [$registrationId], 's');
        $this->execute("DELETE FROM campaign_sources WHERE registration_id = ?", [$registrationId], 's');
        $this->execute("DELETE FROM whatsapp_redirect_records WHERE registration_id = ?", [$registrationId], 's');
        
        // Delete the registration itself
        $this->execute("DELETE FROM registrations WHERE id = ?", [$registrationId], 's');
        
        if (DEBUG) {
            error_log("[REG] Deleted registration $registrationId");
        }
        
        return true;
    }
    
    /**
     * Get registration count
     */
    public function getRegistrationCount(): int {
        $row = $this->queryOne("SELECT COUNT(*) as cnt FROM registrations");
        return (int)($row['cnt'] ?? 0);
    }
}
