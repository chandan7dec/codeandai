<?php
/**
 * WhatsApp Service
 * 
 * Handles WhatsApp consent, invite generation, and redirect.
 * Supports both MySQL (MySQLi) and SQLite (PDO).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

class WhatsAppService {
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
    
    private function queryOne(string $sql, array $params = [], string $types = ''): ?array {
        $rows = $this->query($sql, $params, $types);
        return !empty($rows) ? $rows[0] : null;
    }
    
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
     * Get WhatsApp redirect URL for a registration
     */
    public function getRedirectUrl(string $registrationId): ?string {
        $registration = $this->queryOne("SELECT * FROM registrations WHERE id = ?", [$registrationId], 's');
        
        if (!$registration) {
            if (DEBUG) error_log("[WA] Registration not found: $registrationId");
            return null;
        }
        
        $registrant = $this->queryOne("SELECT * FROM registrants WHERE id = ?", [$registration['registrant_id']], 's');
        
        if (!$registrant['consented_to_whatsapp']) {
            if (DEBUG) error_log("[WA] Registration $registrationId has not consented to WhatsApp");
            return null;
        }
        
        if ($registrant['wa_consent_withdrawn_at']) {
            if (DEBUG) error_log("[WA] Registration $registrationId has withdrawn WhatsApp consent");
            return null;
        }
        
        return $this->generateInviteUrl($registration);
    }
    
    private function generateInviteUrl(array $registration): string {
        $existingRecord = $this->queryOne(
            "SELECT * FROM whatsapp_redirect_records WHERE registration_id = ?",
            [$registration['id']],
            's'
        );
        
        if ($existingRecord && !empty($existingRecord['invite_url'])) {
            return $existingRecord['invite_url'];
        }
        
        $inviteUrl = WHATSAPP_GROUP_INVITE_URL;
        $now = utcnow();
        
        if ($existingRecord) {
            $this->execute(
                "UPDATE whatsapp_redirect_records SET invite_url = ?, status = 'pending', updated_at = ? WHERE id = ?",
                [$inviteUrl, $now, $existingRecord['id']],
                'sss'
            );
        } else {
            $id = generateUUID();
            $this->execute(
                "INSERT INTO whatsapp_redirect_records (id, registration_id, invite_url, status, created_at, updated_at) 
                 VALUES (?, ?, ?, 'pending', ?, ?)",
                [$id, $registration['id'], $inviteUrl, $now, $now],
                'sssss'
            );
        }
        
        return $inviteUrl;
    }
    
    public function recordRedirectAttempt(string $registrationId, string $redirectUrl): void {
        $record = $this->queryOne(
            "SELECT * FROM whatsapp_redirect_records WHERE registration_id = ?",
            [$registrationId],
            's'
        );
        $now = utcnow();
        
        if ($record) {
            $this->execute(
                "UPDATE whatsapp_redirect_records SET redirect_url = ?, status = 'redirected', updated_at = ? WHERE id = ?",
                [$redirectUrl, $now, $record['id']],
                'sss'
            );
        } else {
            $id = generateUUID();
            $this->execute(
                "INSERT INTO whatsapp_redirect_records (id, registration_id, redirect_url, status, created_at, updated_at) 
                 VALUES (?, ?, ?, 'redirected', ?, ?)",
                [$id, $registrationId, $redirectUrl, $now, $now],
                'sssss'
            );
        }
        
        if (DEBUG) error_log("[WA] Recorded WhatsApp redirect for registration $registrationId");
    }
    
    public function recordRedirectFailure(string $registrationId, string $errorMessage): void {
        $record = $this->queryOne(
            "SELECT * FROM whatsapp_redirect_records WHERE registration_id = ?",
            [$registrationId],
            's'
        );
        $now = utcnow();
        
        if ($record) {
            $this->execute(
                "UPDATE whatsapp_redirect_records SET status = 'failed', error_message = ?, updated_at = ? WHERE id = ?",
                [$errorMessage, $now, $record['id']],
                'sss'
            );
        } else {
            $id = generateUUID();
            $this->execute(
                "INSERT INTO whatsapp_redirect_records (id, registration_id, status, error_message, created_at, updated_at) 
                 VALUES (?, ?, 'failed', ?, ?, ?)",
                [$id, $registrationId, $errorMessage, $now, $now],
                'sssss'
            );
        }
        
        if (DEBUG) error_log("[WA] Recorded WhatsApp redirect failure for registration $registrationId: $errorMessage");
    }
    
    public function getRedirectStatus(string $registrationId): array {
        $record = $this->queryOne(
            "SELECT * FROM whatsapp_redirect_records WHERE registration_id = ?",
            [$registrationId],
            's'
        );
        
        if (!$record) {
            return ['status' => 'not_found', 'message' => 'No WhatsApp redirect record found'];
        }
        
        return [
            'status' => $record['status'],
            'invite_url' => $record['invite_url'],
            'redirect_url' => $record['redirect_url'],
            'error_message' => $record['error_message'],
            'created_at' => $record['created_at'],
            'updated_at' => $record['updated_at'],
        ];
    }
    
    public function withdrawConsent(string $registrationId, ?string $reason = null): bool {
        $registration = $this->queryOne("SELECT * FROM registrations WHERE id = ?", [$registrationId], 's');
        if (!$registration) return false;
        
        $registrant = $this->queryOne("SELECT * FROM registrants WHERE id = ?", [$registration['registrant_id']], 's');
        if (!$registrant['consented_to_whatsapp']) return false;
        
        $now = utcnow();
        $this->execute(
            "UPDATE registrants SET consented_to_whatsapp = 0, wa_consent_withdrawn_at = ?, updated_at = ? WHERE id = ?",
            [$now, $now, $registrant['id']],
            'sss'
        );
        
        $id = generateUUID();
        $withdrawalReason = $reason ?: 'User withdrew consent';
        $this->execute(
            "INSERT INTO whatsapp_consent_records (id, registration_id, action, consent_state, reason, recorded_at) 
             VALUES (?, ?, 'withdrawal', 0, ?, ?)",
            [$id, $registrationId, $withdrawalReason, $now],
            'ssss'
        );
        
        if (DEBUG) error_log("[WA] Withdrew WhatsApp consent for registration $registrationId");
        return true;
    }
}
