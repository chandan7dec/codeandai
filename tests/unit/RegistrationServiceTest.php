<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class RegistrationServiceTest extends \Tests\Support\DatabaseTestCase
{
    public function testPaidClassCreatesPendingRegistrationAndInitiatedPayment(): void
    {
        // T021
        $service = new \RegistrationService();
        $result = $service->createRegistration('Test User', 'paid@example.com', '9876512345');

        $this->assertTrue($result['requires_payment']);
        $this->assertSame('pending', $result['registration']['status']);
        $this->assertSame('499.00', $result['payment']['amount']);
        $this->assertSame('initiated', $result['payment']['status']);
        $this->assertArrayHasKey('qr_code', $result['payment']);
        $this->assertArrayHasKey('upi_payload', $result['payment']);
        $this->assertStringContainsString('tr=' . $result['payment']['merchant_order_id'], $result['payment']['upi_payload']);
    }

    public function testFreeClassConfirmsImmediatelyWithoutPayment(): void
    {
        // Point the service at the free class (paid class is earliest by default).
        $db = \getDB();
        $db->exec("UPDATE demo_classes SET registration_open = 0 WHERE id = 'class-paid'");
        $db->exec("UPDATE demo_classes SET registration_open = 1 WHERE id = 'class-free'");

        $service = new \RegistrationService();
        $result = $service->createRegistration('Free User', 'free@example.com', '9876512346');

        $this->assertFalse($result['requires_payment']);
        $this->assertSame('confirmed', $result['registration']['status']);
        $this->assertArrayNotHasKey('payment', $result);

        // Regression (quickstart scenario 2): no payment row for free classes.
        $db = \getDB();
        $stmt = $db->prepare('SELECT COUNT(*) FROM payments WHERE registration_id = ?');
        $stmt->execute([$result['registration']['id']]);
        $this->assertSame(0, (int)$stmt->fetchColumn());
    }

    public function testClassSpecificRegistrationRegistersClickedClass(): void
    {
        // Two open classes exist: class-paid (earliest) and class-free (2030).
        // Registering with an explicit classId must target THAT class,
        // not silently fall back to the earliest open one.
        $service = new \RegistrationService();
        $result = $service->createRegistration('Clicked User', 'clicked@example.com', '9876512349', false, 'class-free');

        $this->assertFalse($result['requires_payment']);
        $this->assertSame('confirmed', $result['registration']['status']);
        $this->assertSame('class-free', $result['demo_class']['id']);
    }

    public function testClassSpecificRegistrationRejectsClosedClass(): void
    {
        $db = \getDB();
        $db->exec("UPDATE demo_classes SET registration_open = 0 WHERE id = 'class-free'");

        $service = new \RegistrationService();
        try {
            $service->createRegistration('Late User', 'late@example.com', '9876512350', false, 'class-free');
            $this->fail('Expected InvalidArgumentException for closed class');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('not open for registration', $e->getMessage());
        }
    }

    public function testClassSpecificRegistrationRejectsInvalidId(): void
    {
        $service = new \RegistrationService();
        $this->expectException(\InvalidArgumentException::class);
        $service->createRegistration('Bad User', 'bad@example.com', '9876512351', false, "bad' OR 1=1 --");
    }

    public function testDuplicateRegistrationRejectedWhilePending(): void
    {
        $service = new \RegistrationService();
        $service->createRegistration('Dup User', 'dup@example.com', '9876512347');

        $this->expectException(\InvalidArgumentException::class);
        $service->createRegistration('Dup User', 'dup@example.com', '9876512347');
    }

    public function testCancelledPaymentAllowsNewAttempt(): void
    {
        $service = new \RegistrationService();
        $first = $service->createRegistration('Retry User', 'retry@example.com', '9876512348');
        $orderId = $first['payment']['merchant_order_id'];

        // Simulate failure: payment fails, registration cancels, seat releases.
        (new \UpiService())->failPaymentByOrderId($orderId);

        // T029: after failure the same user can register again (retry).
        $second = $service->createRegistration('Retry User', 'retry@example.com', '9876512348');

        $this->assertTrue($second['requires_payment']);
        $this->assertSame('pending', $second['registration']['status']);
        $this->assertNotSame($orderId, $second['payment']['merchant_order_id']);
    }

    public function testCapacityCountedAgainstPendingRegistrations(): void
    {
        // class-paid has capacity 2 in the fixture.
        $service = new \RegistrationService();
        $service->createRegistration('User One', 'cap1@example.com', '9876500011');
        $service->createRegistration('User Two', 'cap2@example.com', '9876500012');

        // Both seats are held by pending payments; a third must be refused.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('full');
        $service->createRegistration('User Three', 'cap3@example.com', '9876500013');
    }

    public function testSeatReleasedAfterExpiry(): void
    {
        $service = new \RegistrationService();
        $first = $service->createRegistration('Expiry User', 'exp1@example.com', '9876500021');

        $db = \getDB();
        $db->exec("UPDATE payments SET created_at = datetime('now', '-30 minutes') WHERE id = '" . $first['payment']['id'] . "'");
        (new \UpiService())->expirePendingPayments();

        // Seat released: a new user can now take it.
        $second = $service->createRegistration('Second User', 'exp2@example.com', '9876500022');
        $this->assertSame('pending', $second['registration']['status']);
    }
}
