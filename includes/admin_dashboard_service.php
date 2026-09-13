<?php

declare(strict_types=1);

/**
 * Admin Dashboard Service
 *
 * Payment logs, revenue analytics and per-class revenue for the organizer
 * dashboard, plus manual reconciliation helpers (delegated to UpiService).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

class AdminDashboardService
{
    /** @var \PDO|\mysqli */
    private $db;

    public function __construct($db = null)
    {
        $this->db = $db ?: getDB();
    }

    /**
     * All payments with user + class context, filterable.
     *
     * @param array{status?:string,class_id?:string,search?:string,date_from?:string,date_to?:string} $filters
     * @return array<int, array<string, mixed>>
     */
    public function getPaymentLogs(array $filters = []): array
    {
        $sql = "SELECT p.*,
                       reg.name AS user_name,
                       reg.email AS user_email,
                       reg.phone_number AS user_phone,
                       dc.title AS class_title,
                       dc.is_paid AS class_is_paid,
                       r.registration_status
                FROM payments p
                JOIN registrants reg ON p.user_id = reg.id
                JOIN demo_classes dc ON p.class_id = dc.id
                LEFT JOIN registrations r ON p.registration_id = r.id
                WHERE 1=1";
        $params = [];
        $types = '';

        if (!empty($filters['status']) && in_array($filters['status'], ['initiated', 'pending', 'success', 'failed', 'expired', 'refunded'], true)) {
            $sql .= ' AND p.status = ?';
            $params[] = $filters['status'];
            $types .= 's';
        }
        if (!empty($filters['class_id'])) {
            $sql .= ' AND p.class_id = ?';
            $params[] = (string)$filters['class_id'];
            $types .= 's';
        }
        if (!empty($filters['search'])) {
            $searchTerm = '%' . (string)$filters['search'] . '%';
            $sql .= ' AND (reg.name LIKE ? OR reg.email LIKE ? OR p.transaction_id LIKE ? OR p.merchant_order_id LIKE ?)';
            array_push($params, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
            $types .= 'ssss';
        }
        if (!empty($filters['date_from'])) {
            $sql .= ' AND p.created_at >= ?';
            $params[] = (string)$filters['date_from'] . ' 00:00:00';
            $types .= 's';
        }
        if (!empty($filters['date_to'])) {
            $sql .= ' AND p.created_at <= ?';
            $params[] = (string)$filters['date_to'] . ' 23:59:59';
            $types .= 's';
        }

        $sql .= ' ORDER BY p.created_at DESC';
        $rows = $this->query($sql, $params, $types);
        foreach ($rows as &$row) {
            $row['amount'] = (float)$row['amount'];
        }
        return $rows;
    }

    /**
     * Revenue analytics: totals, per-status counts, free vs paid distribution.
     */
    public function getRevenueAnalytics(): array
    {
        $totals = $this->queryOne(
            "SELECT
                COALESCE(SUM(CASE WHEN status = 'success' THEN amount END), 0) AS total_revenue,
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS success_count,
                SUM(CASE WHEN status IN ('initiated','pending') THEN 1 ELSE 0 END) AS open_count,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
                SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) AS expired_count,
                COUNT(*) AS total_count
             FROM payments"
        ) ?? [];

        $classStats = $this->queryOne(
            "SELECT
                SUM(CASE WHEN is_paid = 1 THEN 1 ELSE 0 END) AS paid_classes,
                SUM(CASE WHEN is_paid = 0 THEN 1 ELSE 0 END) AS free_classes,
                COUNT(*) AS total_classes
             FROM demo_classes WHERE status = 'active'"
        ) ?? [];

        return [
            'total_revenue' => (float)($totals['total_revenue'] ?? 0),
            'success_count' => (int)($totals['success_count'] ?? 0),
            'open_count' => (int)($totals['open_count'] ?? 0),
            'failed_count' => (int)($totals['failed_count'] ?? 0),
            'expired_count' => (int)($totals['expired_count'] ?? 0),
            'total_count' => (int)($totals['total_count'] ?? 0),
            'paid_classes' => (int)($classStats['paid_classes'] ?? 0),
            'free_classes' => (int)($classStats['free_classes'] ?? 0),
            'total_classes' => (int)($classStats['total_classes'] ?? 0),
        ];
    }

    /**
     * Revenue per paid class (successful payments vs list price).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getClassRevenue(?string $classId = null): array
    {
        $sql = "SELECT dc.id,
                       dc.title,
                       dc.price AS list_price,
                       dc.is_paid,
                       COUNT(p.id) AS payment_count,
                       COALESCE(SUM(CASE WHEN p.status = 'success' THEN p.amount END), 0) AS revenue,
                       SUM(CASE WHEN p.status = 'success' THEN 1 ELSE 0 END) AS success_count,
                       SUM(CASE WHEN p.status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
                       SUM(CASE WHEN p.status = 'expired' THEN 1 ELSE 0 END) AS expired_count,
                       SUM(CASE WHEN p.status IN ('initiated','pending') THEN 1 ELSE 0 END) AS open_count
                FROM demo_classes dc
                LEFT JOIN payments p ON p.class_id = dc.id
                WHERE dc.is_paid = 1";
        $params = [];
        $types = '';
        if ($classId !== null && $classId !== '') {
            $sql .= ' AND dc.id = ?';
            $params[] = $classId;
            $types .= 's';
        }
        $sql .= ' GROUP BY dc.id, dc.title, dc.price, dc.is_paid ORDER BY dc.scheduled_at ASC';

        $rows = $this->query($sql, $params, $types);
        foreach ($rows as &$row) {
            $row['list_price'] = (float)$row['list_price'];
            $row['revenue'] = (float)$row['revenue'];
            $row['payment_count'] = (int)$row['payment_count'];
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
