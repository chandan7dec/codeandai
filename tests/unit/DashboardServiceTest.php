<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DashboardServiceTest extends \Tests\Support\DatabaseTestCase
{
    public function testUserEnrolledClassesListsPaidAndFree(): void
    {
        // T035
        $registration = new \RegistrationService();
        $registration->createRegistration('Dash User', 'dash@example.com', '9876544444');

        // Give the user a free-class registration too (free class is open).
        $db = \getDB();
        $db->exec("UPDATE demo_classes SET registration_open = 1 WHERE id = 'class-free' AND title = 'Free Class'");
        // The service picks the earliest open class; open only the free one.
        $db->exec("UPDATE demo_classes SET registration_open = 0 WHERE id = 'class-paid'");
        $registration->createRegistration('Dash User', 'dash@example.com', '9876544444');
        $db->exec("UPDATE demo_classes SET registration_open = 1 WHERE id = 'class-paid'");

        $service = new \DashboardService();
        $classes = $service->getUserEnrolledClasses('dash@example.com');

        $this->assertCount(2, $classes);
        $paid = array_values(array_filter($classes, static fn ($c) => (int)$c['is_paid'] === 1));
        $free = array_values(array_filter($classes, static fn ($c) => (int)$c['is_paid'] === 0));
        $this->assertCount(1, $paid);
        $this->assertCount(1, $free);
    }

    public function testUserEnrolledClassesFilterByPaid(): void
    {
        $registration = new \RegistrationService();
        $registration->createRegistration('Filter User', 'filter@example.com', '9876544445');

        $service = new \DashboardService();
        $paid = $service->getUserEnrolledClasses('filter@example.com', 'paid');
        $free = $service->getUserEnrolledClasses('filter@example.com', 'free');

        $this->assertCount(1, $paid);
        $this->assertCount(0, $free);
    }

    public function testUserEnrolledClassesCancelledRegistrationsHidden(): void
    {
        $registration = new \RegistrationService();
        $first = $registration->createRegistration('Cancel User', 'cdash@example.com', '9876544446');
        (new \UpiService())->failPaymentByOrderId($first['payment']['merchant_order_id']);

        $service = new \DashboardService();
        $classes = $service->getUserEnrolledClasses('cdash@example.com');
        $this->assertCount(0, $classes, 'Cancelled registrations must not appear as enrollments');
    }

    public function testUserPaymentHistory(): void
    {
        $registration = new \RegistrationService();
        $result = $registration->createRegistration('History User', 'history@example.com', '9876544447');
        (new \UpiService())->processCallback($this->makeCallback($result['payment']['merchant_order_id']));

        $service = new \DashboardService();
        $history = $service->getUserPaymentHistory('history@example.com');

        $this->assertCount(1, $history);
        $this->assertSame('success', $history[0]['status']);
        $this->assertSame(499.0, $history[0]['amount']);
        $this->assertSame('Paid Class', $history[0]['class_title']);
        $this->assertNotNull($history[0]['transaction_id']);
    }

    public function testPaymentHistoryEmptyForUnknownUser(): void
    {
        $service = new \DashboardService();
        $this->assertSame([], $service->getUserPaymentHistory('nobody@example.com'));
        $this->assertSame([], $service->getUserEnrolledClasses('nobody@example.com'));
    }
}
