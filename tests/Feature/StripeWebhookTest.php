<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\PaymentStatus;
use App\Models\MeetingQuotaTransaction;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use UnexpectedValueException;

class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set(
            'services.stripe.webhook_secret',
            self::WEBHOOK_SECRET
        );
    }

    public function test_paid_checkout_grants_meeting_quota(): void
    {
        $payment = Payment::factory()->create([
            'amount' => 5000,
            'quantity' => 3,
        ]);

        $this->sendWebhook(
            $this->checkoutSession($payment)
        )->assertOk()
            ->assertJson(['received' => true]);

        $payment->refresh();

        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame(
            'pi_test_123',
            $payment->stripe_payment_intent_id
        );

        $this->assertDatabaseHas('meeting_quota_transactions', [
            'user_id' => $payment->user_id,
            'type' => MeetingQuotaTransactionType::Purchased->value,
            'amount' => 3,
            'related_payment_id' => $payment->id,
        ]);
    }

    public function test_duplicate_webhook_does_not_grant_quota_twice(): void
    {
        $payment = Payment::factory()->create([
            'quantity' => 3,
        ]);

        $session = $this->checkoutSession($payment);

        $this->sendWebhook($session)->assertOk();
        $this->sendWebhook($session)->assertOk();

        $this->assertSame(
            1,
            $this->purchasedTransactionCount($payment)
        );
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $payload = json_encode(
            $this->webhookEvent([
                'id' => 'cs_test_invalid',
                'object' => 'checkout.session',
                'payment_status' => 'paid',
            ]),
            JSON_THROW_ON_ERROR
        );

        $this->call(
            'POST',
            '/webhooks/stripe',
            [],
            [],
            [],
            [
                'HTTP_STRIPE_SIGNATURE' => 'invalid-signature',
                'CONTENT_TYPE' => 'application/json',
            ],
            $payload
        )->assertStatus(400);

        $this->assertDatabaseCount(
            'meeting_quota_transactions',
            0
        );
    }

    public function test_unpaid_checkout_does_not_grant_quota(): void
    {
        $payment = Payment::factory()->create();

        $this->sendWebhook(
            $this->checkoutSession($payment, 'unpaid')
        )->assertOk();

        $this->assertSame(
            PaymentStatus::Pending,
            $payment->fresh()->status
        );

        $this->assertSame(
            0,
            $this->purchasedTransactionCount($payment)
        );
    }

    public function test_unknown_payment_returns_server_error_for_retry(): void
    {
        $session = [
            'id' => 'cs_test_unknown_'.Str::random(16),
            'object' => 'checkout.session',
            'mode' => 'payment',
            'client_reference_id' => (string) Str::ulid(),
            'payment_status' => 'paid',
            'amount_total' => 5000,
            'currency' => 'jpy',
            'payment_intent' => 'pi_test_unknown',
        ];

        $this->sendWebhook($session)->assertStatus(500);

        $this->assertDatabaseCount(
            'meeting_quota_transactions',
            0
        );
    }

    public function test_amount_mismatch_does_not_grant_quota(): void
    {
        $payment = Payment::factory()->create([
            'amount' => 5000,
        ]);

        $session = $this->checkoutSession($payment);
        $session['amount_total'] = 100;

        $this->assertPaymentMismatch($payment, $session);
    }

    public function test_currency_mismatch_does_not_grant_quota(): void
    {
        $payment = Payment::factory()->create();

        $session = $this->checkoutSession($payment);
        $session['currency'] = 'usd';

        $this->assertPaymentMismatch($payment, $session);
    }

    public function test_payment_mode_mismatch_does_not_grant_quota(): void
    {
        $payment = Payment::factory()->create();

        $session = $this->checkoutSession($payment);
        $session['mode'] = 'subscription';

        $this->assertPaymentMismatch($payment, $session);
    }

    public function test_client_reference_id_mismatch_does_not_grant_quota(): void
    {
        $payment = Payment::factory()->create();

        $session = $this->checkoutSession($payment);
        $session['client_reference_id'] = (string) Str::ulid();

        $this->assertPaymentMismatch($payment, $session);
    }

    public function test_async_payment_success_grants_meeting_quota(): void
    {
        $payment = Payment::factory()->create([
            'quantity' => 5,
        ]);

        $this->sendWebhook(
            $this->checkoutSession($payment),
            'checkout.session.async_payment_succeeded'
        )->assertOk();

        $this->assertSame(
            PaymentStatus::Paid,
            $payment->fresh()->status
        );

        $this->assertDatabaseHas('meeting_quota_transactions', [
            'related_payment_id' => $payment->id,
            'type' => MeetingQuotaTransactionType::Purchased->value,
            'amount' => 5,
        ]);
    }

    public function test_async_payment_failure_does_not_grant_quota(): void
    {
        $payment = Payment::factory()->create();

        $this->sendWebhook(
            $this->checkoutSession($payment, 'unpaid'),
            'checkout.session.async_payment_failed'
        )->assertOk();

        $this->assertSame(
            PaymentStatus::Failed,
            $payment->fresh()->status
        );

        $this->assertSame(
            0,
            $this->purchasedTransactionCount($payment)
        );
    }

    public function test_expired_checkout_is_canceled_without_quota(): void
    {
        $payment = Payment::factory()->create();

        $this->sendWebhook(
            $this->checkoutSession($payment, 'unpaid'),
            'checkout.session.expired'
        )->assertOk();

        $this->assertSame(
            PaymentStatus::Canceled,
            $payment->fresh()->status
        );

        $this->assertSame(
            0,
            $this->purchasedTransactionCount($payment)
        );
    }

    public function test_failure_event_does_not_override_paid_payment(): void
    {
        $payment = Payment::factory()->create([
            'quantity' => 3,
        ]);

        $session = $this->checkoutSession($payment);

        $this->sendWebhook($session)->assertOk();

        $this->sendWebhook(
            $session,
            'checkout.session.async_payment_failed'
        )->assertOk();

        $this->assertSame(
            PaymentStatus::Paid,
            $payment->fresh()->status
        );

        $this->assertSame(
            1,
            $this->purchasedTransactionCount($payment)
        );
    }

    public function test_success_after_failure_grants_quota_once(): void
    {
        $payment = Payment::factory()->create([
            'quantity' => 3,
        ]);

        $session = $this->checkoutSession($payment);

        $this->sendWebhook(
            $session,
            'checkout.session.async_payment_failed'
        )->assertOk();

        $this->assertSame(
            PaymentStatus::Failed,
            $payment->fresh()->status
        );

        $this->sendWebhook(
            $session,
            'checkout.session.async_payment_succeeded'
        )->assertOk();

        $this->assertSame(
            PaymentStatus::Paid,
            $payment->fresh()->status
        );

        $this->assertSame(
            1,
            $this->purchasedTransactionCount($payment)
        );
    }

    /**
     * 決済情報不一致時に付与されないことを検証する。
     *
     * @param array<string, mixed> $session
     */
    private function assertPaymentMismatch(
        Payment $payment,
        array $session
    ): void {
        $this->withoutExceptionHandling();

        try {
            $this->sendWebhook($session);

            $this->fail(
                '決済情報の不一致で例外が発生する必要があります。'
            );
        } catch (UnexpectedValueException $exception) {
            $this->assertSame(
                'Stripe payment information mismatch.',
                $exception->getMessage()
            );
        } finally {
            $this->withExceptionHandling();
        }

        $this->assertSame(
            PaymentStatus::Pending,
            $payment->fresh()->status
        );

        $this->assertSame(
            0,
            $this->purchasedTransactionCount($payment)
        );
    }

    /**
     * 購入に紐づく面談回数取引履歴の件数。
     */
    private function purchasedTransactionCount(Payment $payment): int
    {
        return MeetingQuotaTransaction::query()
            ->where('related_payment_id', $payment->id)
            ->where('type', MeetingQuotaTransactionType::Purchased)
            ->count();
    }

    /**
     * Stripe Checkout Sessionのテストデータ。
     *
     * @return array<string, mixed>
     */
    private function checkoutSession(
        Payment $payment,
        string $paymentStatus = 'paid'
    ): array {
        return [
            'id' => $payment->stripe_checkout_session_id,
            'object' => 'checkout.session',
            'mode' => 'payment',
            'client_reference_id' => $payment->id,
            'payment_status' => $paymentStatus,
            'amount_total' => $payment->amount,
            'currency' => 'jpy',
            'payment_intent' => 'pi_test_123',
        ];
    }

    /**
     * Stripeイベントのテストデータ。
     *
     * @param array<string, mixed> $session
     *
     * @return array<string, mixed>
     */
    private function webhookEvent(
        array $session,
        string $eventType = 'checkout.session.completed'
    ): array {
        return [
            'id' => 'evt_test_'.Str::random(16),
            'object' => 'event',
            'api_version' => '2025-12-15.clover',
            'created' => time(),
            'type' => $eventType,
            'data' => [
                'object' => $session,
            ],
        ];
    }

    /**
     * 正しいStripe署名を付与してWebhookを送信する。
     *
     * @param array<string, mixed> $session
     */
    private function sendWebhook(
        array $session,
        string $eventType = 'checkout.session.completed'
    ): TestResponse {
        $payload = json_encode(
            $this->webhookEvent($session, $eventType),
            JSON_THROW_ON_ERROR
        );

        $timestamp = time();

        $signature = hash_hmac(
            'sha256',
            $timestamp.'.'.$payload,
            self::WEBHOOK_SECRET
        );

        return $this->call(
            'POST',
            '/webhooks/stripe',
            [],
            [],
            [],
            [
                'HTTP_STRIPE_SIGNATURE' => sprintf(
                    't=%d,v1=%s',
                    $timestamp,
                    $signature
                ),
                'CONTENT_TYPE' => 'application/json',
            ],
            $payload
        );
    }
}
