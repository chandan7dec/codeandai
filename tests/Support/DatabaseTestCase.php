<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * Base test case: resets the shared SQLite in-memory database between tests.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected function setUp(): void
    {
        $db = \getDB();
        foreach ([
            'payments',
            'whatsapp_consent_records',
            'manual_follow_up_records',
            'campaign_sources',
            'whatsapp_redirect_records',
            'registrations',
            'registrants',
            'demo_classes',
            'app_metadata',
        ] as $table) {
            $db->exec("DELETE FROM $table");
        }

        // A stable open class for tests that register users.
        // class-paid is scheduled EARLIEST so the service's "earliest open
        // active class" selection targets the paid class by default; tests
        // that need the free class close the paid one first.
        $db->exec(
            "INSERT INTO demo_classes (id, title, topic, trainer_name, scheduled_at, timezone, status, registration_open, capacity, is_paid, price)
             VALUES ('class-paid', 'Paid Class', 'Paid Topic', 'Trainer', '2030-01-01 10:00:00', 'UTC', 'active', 1, 2, 1, 499.00)"
        );
        $db->exec(
            "INSERT INTO demo_classes (id, title, topic, trainer_name, scheduled_at, timezone, status, registration_open, capacity, is_paid, price)
             VALUES ('class-free', 'Free Class', 'Free Topic', 'Trainer', '2030-06-01 10:00:00', 'UTC', 'active', 1, NULL, 0, 0.00)"
        );
    }

    protected function makePaidRegistration(string $email = 'payer@example.com', string $name = 'Payer'): array
    {
        $service = new \RegistrationService();
        $result = $service->createRegistration($name, $email, '9876500000');

        return [
            'result' => $result,
            'order_id' => $result['payment']['merchant_order_id'],
            'registration_id' => $result['registration']['id'],
            'payment_id' => $result['payment']['id'],
        ];
    }

    protected function makeCallback(string $orderId, string $status = 'SUCCESS', ?string $amount = null, ?string $txnId = null): array
    {
        $payload = [
            'txnId' => $txnId ?? 'UPI_TXN_' . strtoupper(bin2hex(random_bytes(6))),
            'merchantOrderId' => $orderId,
            'amount' => $amount ?? '499.00',
            'status' => $status,
            'timestamp' => '2026-09-09T10:30:00+05:30',
            'payerVpa' => 'payer@bank',
            'payeeVpa' => 'merchant@upi',
        ];
        $payload['signature'] = \UpiService::signCallback($payload);

        return $payload;
    }
}
