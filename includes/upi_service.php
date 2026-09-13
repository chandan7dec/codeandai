<?php

declare(strict_types=1);

/**
 * UPI Payment Service
 *
 * Direct UPI integration (no payment gateway): UPI payload/QR generation,
 * callback signature verification (HMAC-SHA256), callback processing with
 * idempotency, and pending-payment expiry.
 *
 * Supports both MySQL (MySQLi) and SQLite (PDO) via the db.php layer.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/lib/qr_encoder.php';
require_once __DIR__ . '/email_service.php';

use Freebuff\QR\QRCode;

class UpiService
{
    /** @var \PDO|\mysqli */
    private $db;

    public function __construct($db = null)
    {
        $this->db = $db ?: getDB();
    }

    // ── Payload & QR generation ────────────────────────────────────────

    /**
     * RFC 3986 percent-encoding for a single UPI URI parameter value.
     * Spaces use %20 (not '+') so UPI apps decode the payee name correctly.
     */
    public static function encodeUpiParam(string $value): string
    {
        return str_replace('+', '%20', urlencode($value));
    }

    /**
     * Build the standard UPI payment URI (NPCI UPI 2.0 QR format).
     */
    public static function buildUpiPayload(string $merchantOrderId, float $amount): string
    {
        $params = [
            'pa' => UPI_MERCHANT_VPA,
            'pn' => self::encodeUpiParam(UPI_MERCHANT_NAME),
            'am' => number_format($amount, 2, '.', ''),
            'cu' => 'INR',
            'tr' => self::encodeUpiParam($merchantOrderId),
        ];
        return 'upi://pay?' . implode('&', array_map(
            static fn (string $k, string $v): string => $k . '=' . $v,
            array_keys($params),
            array_values($params)
        ));
    }

    /**
     * Generate the payment QR code as a base64 data URI (PNG).
     */
    public static function generateUpiQrCode(string $merchantOrderId, float $amount, string $classId): string
    {
        $payload = self::buildUpiPayload($merchantOrderId, $amount);
        $png = QRCode::png($payload);
        return 'data:image/png;base64,' . base64_encode($png);
    }

    // ── Callback verification & processing ─────────────────────────────

    /**
     * Verify the HMAC-SHA256 signature of a callback payload.
     *
     * Payload string = fields joined by '|' in NPCI contract order:
     * txnId|merchantOrderId|amount|status|timestamp|payerVpa|payeeVpa
     */
    public static function verifyCallback(array $payload): bool
    {
        $signature = (string)($payload['signature'] ?? '');
        if ($signature === '') {
            return false;
        }
        $payloadString = self::callbackPayloadString($payload);
        $expected = hash_hmac('sha256', $payloadString, UPI_CALLBACK_SECRET);
        return hash_equals($expected, strtolower($signature));
    }

    /**
     * Canonical string that the signature is computed over.
     */
    public static function callbackPayloadString(array $payload): string
    {
        return implode('|', [
            (string)($payload['txnId'] ?? ''),
            (string)($payload['merchantOrderId'] ?? ''),
            (string)($payload['amount'] ?? ''),
            (string)($payload['status'] ?? ''),
            (string)($payload['timestamp'] ?? ''),
            (string)($payload['payerVpa'] ?? ''),
            (string)($payload['payeeVpa'] ?? ''),
        ]);
    }

    /**
     * Sign a payload (used by tests and local callback simulation).
     */
    public static function signCallback(array $payload): string
    {
        return hash_hmac('sha256', self::callbackPayloadString($payload), UPI_CALLBACK_SECRET);
    }

    /**
     * Process a verified callback. Returns update data; throws on problems.
     *
     * Handles:
     *  - unknown merchantOrderId → InvalidArgumentException
     *  - amount mismatch (against stored payment amount) → mark failed
     *  - idempotency: already-processed txnId / already-final payment → no-op
     *  - SUCCESS → confirm registration; FAILED/EXPIRED → cancel registration
     *
     * @return array{payment_id:string, status:string, processed:bool, reason?:string}
     */
    public function processCallback(array $payload): array
    {
        $txnId = trim((string)($payload['txnId'] ?? ''));
        $merchantOrderId = trim((string)($payload['merchantOrderId'] ?? ''));
        $amountRaw = (string)($payload['amount'] ?? '');
        $status = strtoupper(trim((string)($payload['status'] ?? '')));
        $payerVpa = trim((string)($payload['payerVpa'] ?? '')) ?: null;

        if ($merchantOrderId === '') {
            throw new InvalidArgumentException('merchantOrderId is required.');
        }
        if (!in_array($status, ['SUCCESS', 'FAILED', 'PENDING', 'EXPIRED'], true)) {
            throw new InvalidArgumentException('Invalid callback status.');
        }

        $this->beginTransaction();
        try {
            $payment = $this->queryOne(
                'SELECT * FROM payments WHERE merchant_order_id = ?',
                [$merchantOrderId],
                's'
            );
            if (!$payment) {
                throw new InvalidArgumentException('Unknown merchantOrderId.');
            }

            // Idempotency: this transaction was already applied to this payment.
            if ($payment['transaction_id'] !== null
                && (string)$payment['transaction_id'] === $txnId
                && $payment['status'] !== 'initiated'
                && $payment['status'] !== 'pending') {
                $this->rollback(); // no writes made; release the transaction
                return ['payment_id' => $payment['id'], 'status' => $payment['status'], 'processed' => false, 'reason' => 'duplicate_txnId'];
            }

            // Idempotency: payment already in a final state.
            if (in_array($payment['status'], ['success', 'failed', 'expired', 'refunded'], true)) {
                $this->rollback(); // no writes made; release the transaction
                return ['payment_id' => $payment['id'], 'status' => $payment['status'], 'processed' => false, 'reason' => 'already_final'];
            }

            // Amount must match what we asked the user to pay.
            if ((float)$payment['amount'] !== (float)$amountRaw) {
                $this->execute(
                    "UPDATE payments SET status = 'failed', payer_vpa = ?, raw_response = ?, updated_at = ? WHERE id = ?",
                    [$payerVpa, json_encode($payload), utcnow(), $payment['id']],
                    'ssss'
                );
                $this->cancelRegistration($payment);
                $this->commit();
                return ['payment_id' => $payment['id'], 'status' => 'failed', 'processed' => true, 'reason' => 'amount_mismatch'];
            }

            $now = utcnow();
            switch ($status) {
                case 'SUCCESS':
                    $newStatus = 'success';
                    break;
                case 'FAILED':
                    $newStatus = 'failed';
                    break;
                case 'EXPIRED':
                    $newStatus = 'expired';
                    break;
                case 'PENDING':
                default:
                    $newStatus = 'pending';
                    break;
            }

            $this->execute(
                'UPDATE payments SET status = ?, transaction_id = ?, payer_vpa = ?, raw_response = ?, updated_at = ? WHERE id = ?',
                [$newStatus, $txnId !== '' ? $txnId : null, $payerVpa, json_encode($payload), $now, $payment['id']],
                'ssssss'
            );

            if ($newStatus === 'success') {
                $this->confirmRegistration($payment);
            } elseif (in_array($newStatus, ['failed', 'expired'], true)) {
                $this->cancelRegistration($payment);
            }
            // 'pending' keeps the registration in its current (pending) state.

            $this->commit();

            // Confirmation email AFTER the commit so a rollback never follows a
            // sent mail. Non-blocking: failures are logged, never thrown.
            if ($newStatus === 'success') {
                $this->sendConfirmationEmail($payment);
            }

            return ['payment_id' => $payment['id'], 'status' => $newStatus, 'processed' => true];
        } catch (Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    // ── Payment expiry (US3) ───────────────────────────────────────────

    /**
     * Expire initiated/pending payments older than UPI_PAYMENT_TIMEOUT_MINUTES
     * and cancel their registrations (releasing seats).
     *
     * @return int number of payments expired
     */
    public function expirePendingPayments(): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - UPI_PAYMENT_TIMEOUT_MINUTES * 60);
        $expired = $this->query(
            "SELECT * FROM payments WHERE status IN ('initiated','pending') AND created_at < ?",
            [$cutoff],
            's'
        );

        $count = 0;
        foreach ($expired as $payment) {
            $this->beginTransaction();
            try {
                $this->execute(
                    "UPDATE payments SET status = 'expired', updated_at = ? WHERE id = ? AND status IN ('initiated','pending')",
                    [utcnow(), $payment['id']],
                    'ss'
                );
                $this->cancelRegistration($payment);
                $this->commit();
                $count++;
            } catch (Throwable $e) {
                $this->rollback();
                if (DEBUG) {
                    error_log('[UPI] Failed to expire payment ' . $payment['id'] . ': ' . $e->getMessage());
                }
            }
        }
        return $count;
    }

    /**
     * Mark a specific payment (and its registration) as failed, e.g. user
     * pressed "cancel" on the payment page.
     */
    public function failPaymentByOrderId(string $merchantOrderId, string $reason = 'cancelled_by_user'): array
    {
        $payment = $this->queryOne('SELECT * FROM payments WHERE merchant_order_id = ?', [$merchantOrderId], 's');
        if (!$payment) {
            throw new OutOfBoundsException('Payment not found.');
        }
        if (in_array($payment['status'], ['success', 'failed', 'expired', 'refunded'], true)) {
            return ['payment_id' => $payment['id'], 'status' => $payment['status'], 'processed' => false];
        }
        $this->execute(
            "UPDATE payments SET status = 'failed', raw_response = ?, updated_at = ? WHERE id = ?",
            [json_encode(['reason' => $reason]), utcnow(), $payment['id']],
            'sss'
        );
        $this->cancelRegistration($payment);
        return ['payment_id' => $payment['id'], 'status' => 'failed', 'processed' => true];
    }

    // ── Manual reconciliation (US5) ────────────────────────────────────

    /**
     * Admin override for missed callbacks. Only initiated/pending payments
     * can be reconciled; final states are audited, not overwritten.
     */
    public function reconcilePayment(string $paymentId, string $status, string $adminNote = ''): array
    {
        if (!in_array($status, ['success', 'failed'], true)) {
            throw new InvalidArgumentException('Reconciliation status must be success or failed.');
        }
        $payment = $this->queryOne('SELECT * FROM payments WHERE id = ?', [$paymentId], 's');
        if (!$payment) {
            throw new OutOfBoundsException('Payment not found.');
        }
        if (in_array($payment['status'], ['success', 'failed', 'expired', 'refunded'], true)) {
            throw new RuntimeException('Payment is already in a final state and cannot be reconciled.');
        }

        $this->execute(
            'UPDATE payments SET status = ?, admin_note = ?, updated_at = ? WHERE id = ?',
            [$status, $adminNote !== '' ? $adminNote : 'manual_reconciliation', utcnow(), $paymentId],
            'ssss'
        );
        if ($status === 'success') {
            $this->confirmRegistration($payment);
            $this->sendConfirmationEmail($payment);
        } else {
            $this->cancelRegistration($payment);
        }
        return $this->queryOne('SELECT * FROM payments WHERE id = ?', [$paymentId], 's') ?? [];
    }

    // ── Queries ─────────────────────────────────────────────────────────

    public function getByMerchantOrderId(string $merchantOrderId): ?array
    {
        return $this->queryOne('SELECT * FROM payments WHERE merchant_order_id = ?', [$merchantOrderId], 's');
    }

    public function getById(string $id): ?array
    {
        return $this->queryOne('SELECT * FROM payments WHERE id = ?', [$id], 's');
    }

    // ── Internals ───────────────────────────────────────────────────────

    /**
     * Send the registration confirmation email for a paid-class payment.
     * Non-blocking: any failure is logged and swallowed (constitution V).
     */
    private function sendConfirmationEmail(array $payment): void
    {
        try {
            $row = $this->queryOne(
                'SELECT reg.name, reg.email, dc.title, dc.topic, dc.trainer_name, dc.scheduled_at, dc.timezone, dc.teams_link
                 FROM payments p
                 JOIN registrants reg ON p.user_id = reg.id
                 JOIN demo_classes dc ON p.class_id = dc.id
                 WHERE p.id = ?',
                [$payment['id']],
                's'
            );
            if (!$row) {
                return;
            }
            EmailService::sendRegistrationConfirmation(
                toEmail: (string)$row['email'],
                name: (string)$row['name'],
                classTitle: (string)$row['title'],
                scheduledDate: (string)$row['scheduled_at'],
                timezone: (string)$row['timezone'],
                topic: (string)($row['topic'] ?? ''),
                trainerName: (string)($row['trainer_name'] ?? ''),
                teamsLink: (string)($row['teams_link'] ?? ''),
                whatsappGroupUrl: WHATSAPP_GROUP_INVITE_URL
            );
        } catch (Throwable $e) {
            if (DEBUG) {
                error_log('[UPI] Confirmation email failed for payment ' . $payment['id'] . ': ' . $e->getMessage());
            }
        }
    }

    private function confirmRegistration(array $payment): void
    {
        $registrationId = $payment['registration_id'] ?? null;
        if ($registrationId) {
            $this->execute(
                "UPDATE registrations SET registration_status = 'confirmed', confirmation_message = 'Registration confirmed - payment received', updated_at = ? WHERE id = ? AND registration_status = 'pending'",
                [utcnow(), $registrationId],
                'ss'
            );
            return;
        }
        // Fallback: confirm the newest pending registration for this user+class
        // (registration_id should always exist for payments created by the flow).
        $reg = $this->queryOne(
            "SELECT id FROM registrations WHERE registrant_id = ? AND demo_class_id = ? AND registration_status = 'pending' ORDER BY created_at DESC LIMIT 1",
            [$payment['user_id'], $payment['class_id']],
            'ss'
        );
        if ($reg) {
            $this->execute(
                "UPDATE registrations SET registration_status = 'confirmed', confirmation_message = 'Registration confirmed - payment received', updated_at = ? WHERE id = ?",
                [utcnow(), $reg['id']],
                's'
            );
        }
    }

    private function cancelRegistration(array $payment): void
    {
        $registrationId = $payment['registration_id'] ?? null;
        if ($registrationId) {
            $this->execute(
                "UPDATE registrations SET registration_status = 'cancelled', updated_at = ? WHERE id = ? AND registration_status = 'pending'",
                [utcnow(), $registrationId],
                'ss'
            );
            return;
        }
        $reg = $this->queryOne(
            "SELECT id FROM registrations WHERE registrant_id = ? AND demo_class_id = ? AND registration_status = 'pending' ORDER BY created_at DESC LIMIT 1",
            [$payment['user_id'], $payment['class_id']],
            'ss'
        );
        if ($reg) {
            $this->execute(
                "UPDATE registrations SET registration_status = 'cancelled', updated_at = ? WHERE id = ?",
                [utcnow(), $reg['id']],
                's'
            );
        }
    }

    private function beginTransaction(): void
    {
        if ($this->db instanceof PDO) {
            if (!$this->db->inTransaction()) {
                $this->db->beginTransaction();
            }
            return;
        }
        if (!$this->db->begin_transaction()) {
            throw new RuntimeException('Failed to begin database transaction.');
        }
    }

    private function commit(): void
    {
        if ($this->db instanceof PDO) {
            if ($this->db->inTransaction()) {
                $this->db->commit();
            }
            return;
        }
        $this->db->commit();
    }

    private function rollback(): void
    {
        if ($this->db instanceof PDO) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return;
        }
        $this->db->rollback();
    }

    private function query(string $sql, array $params = [], string $types = ''): array
    {
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

    private function queryOne(string $sql, array $params = [], string $types = ''): ?array
    {
        $rows = $this->query($sql, $params, $types);
        return $rows[0] ?? null;
    }

    private function execute(string $sql, array $params = [], string $types = ''): int
    {
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
}
