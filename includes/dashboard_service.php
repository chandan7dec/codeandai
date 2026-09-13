<?php

declare(strict_types=1);

/**
 * User Dashboard Service
 *
 * Queries for a user's enrolled classes and payment history.
 * "User" = a registrant, identified by email (the registration flow does not
 * create logins; the dashboard is keyed by the email used at registration).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

class DashboardService
{
    /** @var \PDO|\mysqli */
    private $db;

    public function __construct($db = null)
    {
        $this->db = $db ?: getDB();
    }

    /**
     * Classes a user is enrolled in.
     *
     * @param string $email  registrant email
     * @param string $filter one of: all, free, paid, upcoming, completed
     * @return array<int, array<string, mixed>>
     */
    public function getUserEnrolledClasses(string $email, string $filter = 'all'): array
    {
        $rows = $this->query(
            "SELECT r.id AS registration_id,
                    r.registration_status,
                    r.created_at AS registered_at,
                    dc.id AS class_id,
                    dc.title,
                    dc.topic,
                    dc.trainer_name,
                    dc.scheduled_at,
                    dc.timezone,
                    dc.is_paid,
                    dc.price
             FROM registrations r
             JOIN registrants reg ON r.registrant_id = reg.id
             JOIN demo_classes dc ON r.demo_class_id = dc.id
             WHERE reg.email = ? AND r.registration_status != 'cancelled'
             ORDER BY dc.scheduled_at ASC",
            [normalizeEmail($email)],
            's'
        );

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $result = [];
        foreach ($rows as $row) {
            try {
                $scheduled = new DateTimeImmutable($row['scheduled_at'], new DateTimeZone($row['timezone']));
            } catch (Exception $e) {
                $scheduled = $now;
            }
            $isPast = $scheduled <= $now;
            $row['is_paid'] = (int)$row['is_paid'];
            $row['price'] = (float)$row['price'];
            $row['is_past'] = $isPast;

            switch ($filter) {
                case 'free':
                    if ($row['is_paid'] !== 0) {
                        continue 2;
                    }
                    break;
                case 'paid':
                    if ($row['is_paid'] !== 1) {
                        continue 2;
                    }
                    break;
                case 'upcoming':
                    if ($isPast) {
                        continue 2;
                    }
                    break;
                case 'completed':
                    if (!$isPast) {
                        continue 2;
                    }
                    break;
            }
            $result[] = $row;
        }
        return $result;
    }

    /**
     * Payment history with class + registration context.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getUserPaymentHistory(string $email): array
    {
        $rows = $this->query(
            "SELECT p.id,
                    p.transaction_id,
                    p.merchant_order_id,
                    p.amount,
                    p.currency,
                    p.payment_method,
                    p.status,
                    p.payer_vpa,
                    p.created_at,
                    dc.title AS class_title,
                    r.id AS registration_id,
                    r.registration_status
             FROM payments p
             JOIN registrants reg ON p.user_id = reg.id
             JOIN demo_classes dc ON p.class_id = dc.id
             LEFT JOIN registrations r ON p.registration_id = r.id
             WHERE reg.email = ?
             ORDER BY p.created_at DESC",
            [normalizeEmail($email)],
            's'
        );

        foreach ($rows as &$row) {
            $row['amount'] = (float)$row['amount'];
        }
        return $rows;
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
}
