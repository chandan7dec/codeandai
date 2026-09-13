<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ClassManagementServiceTest extends \Tests\Support\DatabaseTestCase
{
    public function testCreatePaidClassWithPositivePrice(): void
    {
        // T009: price > 0 when is_paid = 1
        $service = new \ClassManagementService();
        $class = $service->create([
            'title' => 'Advanced Python for AI',
            'topic' => 'Deep Learning',
            'trainer_name' => 'Dr. Smith',
            'scheduled_at' => '2030-03-01 10:00',
            'timezone' => 'Asia/Kolkata',
            'capacity' => '20',
            'is_paid' => '1',
            'price' => '999.00',
        ]);

        $this->assertSame(1, $class['is_paid']);
        $this->assertSame(999.0, $class['price']);
    }

    public function testCreateFreeClassHasZeroPrice(): void
    {
        // T010: price = 0 when is_paid = 0
        $service = new \ClassManagementService();
        $class = $service->create([
            'title' => 'Free Intro',
            'topic' => 'Basics',
            'trainer_name' => 'Trainer',
            'scheduled_at' => '2030-03-01 10:00',
            'timezone' => 'UTC',
            'is_paid' => '0',
            'price' => '0',
        ]);

        $this->assertSame(0, $class['is_paid']);
        $this->assertSame(0.0, $class['price']);
    }

    public function testPaidClassRequiresPriceGreaterThanZero(): void
    {
        $service = new \ClassManagementService();
        $this->expectException(\InvalidArgumentException::class);
        $service->create([
            'title' => 'Bad Paid',
            'topic' => 'X',
            'trainer_name' => 'T',
            'scheduled_at' => '2030-03-01 10:00',
            'timezone' => 'UTC',
            'is_paid' => '1',
            'price' => '0.00',
        ]);
    }

    public function testPaidClassRequiresPriceField(): void
    {
        $service = new \ClassManagementService();
        $this->expectException(\InvalidArgumentException::class);
        $service->create([
            'title' => 'No Price',
            'topic' => 'X',
            'trainer_name' => 'T',
            'scheduled_at' => '2030-03-01 10:00',
            'timezone' => 'UTC',
            'is_paid' => '1',
        ]);
    }

    public function testFreeClassRejectsNonZeroPrice(): void
    {
        $service = new \ClassManagementService();
        $this->expectException(\InvalidArgumentException::class);
        $service->create([
            'title' => 'Free Priced',
            'topic' => 'X',
            'trainer_name' => 'T',
            'scheduled_at' => '2030-03-01 10:00',
            'timezone' => 'UTC',
            'is_paid' => '0',
            'price' => '100',
        ]);
    }

    public function testPriceWithMoreThanTwoDecimalsRejected(): void
    {
        $service = new \ClassManagementService();
        $this->expectException(\InvalidArgumentException::class);
        $service->create([
            'title' => 'Too Precise',
            'topic' => 'X',
            'trainer_name' => 'T',
            'scheduled_at' => '2030-03-01 10:00',
            'timezone' => 'UTC',
            'is_paid' => '1',
            'price' => '10.999',
        ]);
    }

    public function testNegativePriceRejected(): void
    {
        $service = new \ClassManagementService();
        $this->expectException(\InvalidArgumentException::class);
        $service->create([
            'title' => 'Negative',
            'topic' => 'X',
            'trainer_name' => 'T',
            'scheduled_at' => '2030-03-01 10:00',
            'timezone' => 'UTC',
            'is_paid' => '1',
            'price' => '-5',
        ]);
    }

    public function testCheckboxTruthyValuesNormalize(): void
    {
        $this->assertSame(1, \ClassManagementService::normalizeIsPaid(['is_paid' => 'on']));
        $this->assertSame(1, \ClassManagementService::normalizeIsPaid(['is_paid' => 'true']));
        $this->assertSame(1, \ClassManagementService::normalizeIsPaid(['is_paid' => true]));
        $this->assertSame(0, \ClassManagementService::normalizeIsPaid([]));
        $this->assertSame(0, \ClassManagementService::normalizeIsPaid(['is_paid' => '0']));
    }

    public function testUpdatePrice(): void
    {
        $service = new \ClassManagementService();
        $class = $service->create([
            'title' => 'Editable',
            'topic' => 'X',
            'trainer_name' => 'T',
            'scheduled_at' => '2030-03-01 10:00',
            'timezone' => 'UTC',
            'is_paid' => '1',
            'price' => '500.00',
        ]);
        $updated = $service->update($class['id'], [
            'title' => 'Editable',
            'topic' => 'X',
            'trainer_name' => 'T',
            'scheduled_at' => '2030-03-01 10:00',
            'timezone' => 'UTC',
            'is_paid' => '1',
            'price' => '1500.00',
        ]);
        $this->assertSame(1500.0, $updated['price']);
    }

    public function testPriceChangeBlockedWithSuccessfulPayments(): void
    {
        $service = new \ClassManagementService();
        $class = $service->create([
            'title' => 'Locked Price',
            'topic' => 'X',
            'trainer_name' => 'T',
            'scheduled_at' => '2030-03-01 10:00',
            'timezone' => 'UTC',
            'is_paid' => '1',
            'price' => '500.00',
        ]);

        // A successful payment pins the price.
        $registration = new \RegistrationService();
        $result = $registration->createRegistration('Buyer', 'buyer@example.com', '9876500001');
        $payload = [
            'txnId' => 'UPI_TXN_LOCKPRICE1',
            'merchantOrderId' => $result['payment']['merchant_order_id'],
            'amount' => '499.00',
            'status' => 'SUCCESS',
            'timestamp' => '2026-09-09T10:30:00+05:30',
            'payerVpa' => 'payer@bank',
            'payeeVpa' => 'merchant@upi',
        ];
        $payload['signature'] = \UpiService::signCallback($payload);
        (new \UpiService())->processCallback($payload);

        $this->expectException(\RuntimeException::class);
        $service->update($class['id'], [
            'title' => 'Locked Price',
            'topic' => 'X',
            'trainer_name' => 'T',
            'scheduled_at' => '2030-03-01 10:00',
            'timezone' => 'UTC',
            'is_paid' => '1',
            'price' => '999.00',
        ]);
    }

    public function testGetAllClassesIncludesPaidFields(): void
    {
        $service = new \ClassManagementService();
        $classes = $service->getAllClasses();
        $paid = array_values(array_filter($classes, static fn ($c) => $c['id'] === 'class-paid'));
        $this->assertCount(1, $paid);
        $this->assertSame(1, $paid[0]['is_paid']);
        $this->assertSame(499.0, $paid[0]['price']);
    }

    public function testGetByIdIncludesPaidFields(): void
    {
        $service = new \ClassManagementService();
        $class = $service->getById('class-paid');
        $this->assertNotNull($class);
        $this->assertSame(1, $class['is_paid']);
        $this->assertSame(499.0, $class['price']);
    }
}
