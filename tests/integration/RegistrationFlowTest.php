<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * End-to-end registration + payment lifecycle on SQLite (quickstart scenarios).
 */
final class RegistrationFlowTest extends \Tests\Support\DatabaseTestCase
{
    public function testFreeClassFlowIsImmediate(): void
    {
        // Quickstart scenario 2 (regression)
        $db = \getDB();
        $db->exec("UPDATE demo_classes SET registration_open = 0 WHERE id = 'class-paid'");
        $db->exec("UPDATE demo_classes SET registration_open = 1 WHERE id = 'class-free'");

        $service = new \RegistrationService();
        $result = $service->createRegistration('Free Flow', 'freeflow@example.com', '9876566661');

        $this->assertSame('confirmed', $result['registration']['status']);
        $this->assertFalse($result['requires_payment']);
    }

    public function testPaidClassFullLifecycle(): void
    {
        // Quickstart scenario 3: register → QR → pay → confirmed
        $service = new \RegistrationService();
        $result = $service->createRegistration('Flow User', 'flow@example.com', '9876566662');

        $this->assertSame('pending', $result['registration']['status']);
        $orderId = $result['payment']['merchant_order_id'];

        // QR code decodes to the UPI payload with the right amount.
        $payload = $result['payment']['upi_payload'];
        $this->assertStringContainsString('am=499.00', $payload);
        $this->assertStringContainsString('tr=' . $orderId, $payload);

        $upi = new \UpiService();

        // A tampered callback must be rejected by signature verification
        // (the endpoint layer calls verifyCallback() before processCallback()).
        $forged = $this->makeCallback($orderId, 'SUCCESS');
        $forged['signature'] = str_repeat('0', 64);
        $this->assertFalse(\UpiService::verifyCallback($forged), 'Forged signature must be rejected');

        // Valid callback confirms everything.
        $db = \getDB();
        $upi->processCallback($this->makeCallback($orderId));
        $payment = $upi->getById($result['payment']['id']);
        $registration = $service->getRegistration($result['registration']['id']);
        $this->assertSame('success', $payment['status']);
        $this->assertSame('confirmed', $registration['registration_status']);

        // Payment history shows the receipt.
        $history = (new \DashboardService())->getUserPaymentHistory('flow@example.com');
        $this->assertCount(1, $history);
        $this->assertSame('success', $history[0]['status']);
    }

    public function testConcurrentCapacityLock(): void
    {
        // Quickstart scenario 7: capacity 2, third registration must fail.
        $service = new \RegistrationService();
        $service->createRegistration('Con A', 'cona@example.com', '9876566663');
        $service->createRegistration('Con B', 'conb@example.com', '9876566664');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('full');
        $service->createRegistration('Con C', 'conc@example.com', '9876566665');
    }

    public function testExpiryReleasesSeatAndAllowsRetry(): void
    {
        // Quickstart scenario 4
        $service = new \RegistrationService();
        $stale = $service->createRegistration('Exp A', 'expa@example.com', '9876566666');

        $db = \getDB();
        $db->exec("UPDATE payments SET created_at = datetime('now', '-16 minutes') WHERE id = '" . $stale['payment']['id'] . "'");

        $expired = (new \UpiService())->expirePendingPayments();
        $this->assertSame(1, $expired);

        $registration = $service->getRegistration($stale['registration']['id']);
        $this->assertSame('cancelled', $registration['registration_status']);

        // Capacity released: a fresh user can register.
        $fresh = $service->createRegistration('Exp B', 'expb@example.com', '9876566667');
        $this->assertSame('pending', $fresh['registration']['status']);
    }

    public function testReconciliationEndpointFlow(): void
    {
        // Quickstart scenario 8 variant: missed callback, admin overrides.
        $service = new \RegistrationService();
        $result = $service->createRegistration('Recon Flow', 'reconf@example.com', '9876566668');

        $upi = new \UpiService();
        $payment = $upi->reconcilePayment($result['payment']['id'], 'success', 'seen in bank statement');

        $this->assertSame('success', $payment['status']);
        $registration = $service->getRegistration($result['registration']['id']);
        $this->assertSame('confirmed', $registration['registration_status']);
    }
}
