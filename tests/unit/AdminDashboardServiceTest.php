<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AdminDashboardServiceTest extends \Tests\Support\DatabaseTestCase
{
    private function seedPayments(): array
    {
        $registration = new \RegistrationService();
        $upi = new \UpiService();

        $a = $registration->createRegistration('Admin User A', 'admina@example.com', '9876555551');
        $b = $registration->createRegistration('Admin User B', 'adminb@example.com', '9876555552');

        $orderA = $a['payment']['merchant_order_id'];
        $orderB = $b['payment']['merchant_order_id'];

        $upi->processCallback($this->makeCallback($orderA, 'SUCCESS', null, 'UPI_TXN_ADMIN001'));
        $upi->processCallback($this->makeCallback($orderB, 'FAILED'));

        return ['a' => $a, 'b' => $b];
    }

    public function testPaymentLogsContainUserAndClassContext(): void
    {
        // T039
        $this->seedPayments();

        $logs = (new \AdminDashboardService())->getPaymentLogs();
        $this->assertCount(2, $logs);

        $first = $logs[0]; // most recent first
        $this->assertArrayHasKey('user_name', $first);
        $this->assertArrayHasKey('class_title', $first);
        $this->assertSame('Paid Class', $first['class_title']);
        $this->assertSame(499.0, $first['amount']);
    }

    public function testPaymentLogsFilterByStatus(): void
    {
        $this->seedPayments();

        $service = new \AdminDashboardService();
        $success = $service->getPaymentLogs(['status' => 'success']);
        $failed = $service->getPaymentLogs(['status' => 'failed']);

        $this->assertCount(1, $success);
        $this->assertCount(1, $failed);
        $this->assertSame('success', $success[0]['status']);
        $this->assertSame('failed', $failed[0]['status']);
    }

    public function testPaymentLogsSearchByUser(): void
    {
        $this->seedPayments();

        $logs = (new \AdminDashboardService())->getPaymentLogs(['search' => 'admina@example.com']);
        $this->assertCount(1, $logs);
        $this->assertSame('Admin User A', $logs[0]['user_name']);
    }

    public function testRevenueAnalyticsCounts(): void
    {
        $this->seedPayments();

        $analytics = (new \AdminDashboardService())->getRevenueAnalytics();
        $this->assertSame(499.0, $analytics['total_revenue']);
        $this->assertSame(1, $analytics['success_count']);
        $this->assertSame(1, $analytics['failed_count']);
        $this->assertSame(2, $analytics['total_count']);
        $this->assertSame(1, $analytics['paid_classes']); // fixture: class-paid only
    }

    public function testClassRevenue(): void
    {
        $this->seedPayments();

        $rows = (new \AdminDashboardService())->getClassRevenue();
        $this->assertNotEmpty($rows);

        $row = $rows[0];
        $this->assertSame('Paid Class', $row['title']);
        $this->assertSame(499.0, $row['revenue']);
        $this->assertSame(1, $row['success_count']);
        $this->assertSame(1, $row['failed_count']);
    }
}
