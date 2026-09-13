<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class UpiServiceTest extends \Tests\Support\DatabaseTestCase
{
    public function testBuildUpiPayloadContainsRequiredParams(): void
    {
        // T019: valid UPI URI
        $payload = \UpiService::buildUpiPayload('ORD_TEST123', 499.0);

        $this->assertStringStartsWith('upi://pay?', $payload);
        $this->assertStringContainsString('pa=' . UPI_MERCHANT_VPA, $payload);
        $this->assertStringContainsString('am=499.00', $payload);
        $this->assertStringContainsString('cu=INR', $payload);
        $this->assertStringContainsString('tr=ORD_TEST123', $payload);
        $this->assertStringContainsString('pn=' . rawurlencode(UPI_MERCHANT_NAME), $payload);
    }

    public function testBuildUpiPayloadEncodesSpacesAsPercent20(): void
    {
        $payload = \UpiService::buildUpiPayload('ORD X', 10.0);
        $this->assertStringContainsString('tr=ORD%20X', $payload);
        $this->assertDoesNotMatchRegularExpression('/\+/', $payload, 'UPI URIs must not contain + for spaces');
    }

    public function testGenerateUpiQrCodeReturnsBase64Png(): void
    {
        $qr = \UpiService::generateUpiQrCode('ORD_QRTEST1', 499.0, 'class-paid');
        $this->assertStringStartsWith('data:image/png;base64,', $qr);

        $binary = base64_decode(substr($qr, strlen('data:image/png;base64,')), true);
        $this->assertNotFalse($binary);
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($binary, 0, 8));
        $this->assertGreaterThan(300, strlen($binary));
    }

    public function testVerifyCallbackAcceptsValidSignature(): void
    {
        // T020: HMAC-SHA256 verification
        $payload = $this->makeCallback('ORD_SIGTEST01', 'SUCCESS');
        $this->assertTrue(\UpiService::verifyCallback($payload));
    }

    public function testVerifyCallbackRejectsTamperedPayload(): void
    {
        $payload = $this->makeCallback('ORD_SIGTEST02', 'SUCCESS');
        $payload['amount'] = '999999.00'; // tamper after signing

        $this->assertFalse(\UpiService::verifyCallback($payload));
    }

    public function testVerifyCallbackRejectsMissingSignature(): void
    {
        $payload = $this->makeCallback('ORD_SIGTEST03', 'SUCCESS');
        unset($payload['signature']);
        $this->assertFalse(\UpiService::verifyCallback($payload));
    }

    public function testVerifyCallbackRejectsWrongSecret(): void
    {
        $payload = $this->makeCallback('ORD_SIGTEST04', 'SUCCESS');
        // Re-sign with a different secret to simulate a forged callback.
        $payloadString = \UpiService::callbackPayloadString($payload);
        $payload['signature'] = hash_hmac('sha256', $payloadString, 'attacker-secret');

        $this->assertFalse(\UpiService::verifyCallback($payload));
    }

    public function testProcessCallbackSuccessConfirmsRegistration(): void
    {
        $reg = $this->makePaidRegistration();
        $this->assertSame('pending', $reg['result']['registration']['status']);

        $result = (new \UpiService())->processCallback(
            $this->makeCallback($reg['order_id'])
        );

        $this->assertTrue($result['processed']);
        $this->assertSame('success', $result['status']);

        $payment = (new \UpiService())->getById($reg['payment_id']);
        $this->assertSame('success', $payment['status']);
        $this->assertNotNull($payment['transaction_id']);

        $registration = (new \RegistrationService())->getRegistration($reg['registration_id']);
        $this->assertSame('confirmed', $registration['registration_status']);
    }

    public function testProcessCallbackFailedCancelsRegistration(): void
    {
        $reg = $this->makePaidRegistration('failed@example.com');

        (new \UpiService())->processCallback(
            $this->makeCallback($reg['order_id'], 'FAILED')
        );

        $payment = (new \UpiService())->getById($reg['payment_id']);
        $this->assertSame('failed', $payment['status']);

        $registration = (new \RegistrationService())->getRegistration($reg['registration_id']);
        $this->assertSame('cancelled', $registration['registration_status']);
    }

    public function testProcessCallbackIsIdempotentForDuplicateTxn(): void
    {
        $reg = $this->makePaidRegistration('dup@example.com');
        $service = new \UpiService();

        $payload = $this->makeCallback($reg['order_id'], 'SUCCESS', null, 'UPI_TXN_DUP0001');
        $first = $service->processCallback($payload);
        $second = $service->processCallback($payload);

        $this->assertTrue($first['processed']);
        $this->assertFalse($second['processed']);
        $this->assertSame('duplicate_txnId', $second['reason']);
    }

    public function testProcessCallbackRejectsAmountMismatch(): void
    {
        $reg = $this->makePaidRegistration('mismatch@example.com');

        $result = (new \UpiService())->processCallback(
            $this->makeCallback($reg['order_id'], 'SUCCESS', '1.00')
        );

        $this->assertTrue($result['processed']);
        $this->assertSame('failed', $result['status']);
        $this->assertSame('amount_mismatch', $result['reason']);

        $registration = (new \RegistrationService())->getRegistration($reg['registration_id']);
        $this->assertSame('cancelled', $registration['registration_status']);
    }

    public function testProcessCallbackRejectsUnknownOrder(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new \UpiService())->processCallback(
            $this->makeCallback('ORD_DOES_NOT_EXIST_01')
        );
    }

    public function testProcessCallbackKeepsPendingStatePending(): void
    {
        $reg = $this->makePaidRegistration('pending@example.com');

        (new \UpiService())->processCallback(
            $this->makeCallback($reg['order_id'], 'PENDING')
        );

        $payment = (new \UpiService())->getById($reg['payment_id']);
        $this->assertSame('pending', $payment['status']);

        $registration = (new \RegistrationService())->getRegistration($reg['registration_id']);
        $this->assertSame('pending', $registration['registration_status']);
    }

    public function testExpirePendingPaymentsExpiresOldPayments(): void
    {
        // T028/T029: expiry logic
        $reg = $this->makePaidRegistration('expire@example.com');
        $db = \getDB();

        // Backdate the payment beyond the timeout window.
        $db->exec("UPDATE payments SET created_at = datetime('now', '-20 minutes') WHERE id = '" . $reg['payment_id'] . "'");

        $expired = (new \UpiService())->expirePendingPayments();
        $this->assertSame(1, $expired);

        $payment = (new \UpiService())->getById($reg['payment_id']);
        $this->assertSame('expired', $payment['status']);

        $registration = (new \RegistrationService())->getRegistration($reg['registration_id']);
        $this->assertSame('cancelled', $registration['registration_status']);
    }

    public function testExpirePendingPaymentsIgnoresFreshPayments(): void
    {
        $this->makePaidRegistration('fresh@example.com');
        $this->assertSame(0, (new \UpiService())->expirePendingPayments());
    }

    public function testReconcilePaymentMarksSuccess(): void
    {
        $reg = $this->makePaidRegistration('reconcile@example.com');
        $service = new \UpiService();

        $payment = $service->reconcilePayment($reg['payment_id'], 'success', 'bank statement confirmed');

        $this->assertSame('success', $payment['status']);
        $this->assertSame('bank statement confirmed', $payment['admin_note']);

        $registration = (new \RegistrationService())->getRegistration($reg['registration_id']);
        $this->assertSame('confirmed', $registration['registration_status']);
    }

    public function testReconcileRejectsFinalStates(): void
    {
        $reg = $this->makePaidRegistration('recon-final@example.com');
        $service = new \UpiService();
        $service->reconcilePayment($reg['payment_id'], 'success');

        $this->expectException(\RuntimeException::class);
        $service->reconcilePayment($reg['payment_id'], 'failed');
    }
}
