<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Str;
use RuntimeException;
use Stripe\StripeClient;
use Throwable;

class StripeCheckoutService
{
    /**
     * Stripe Checkout Sessionを作成し、決済情報を保存する。
     *
     * @return array{payment: Payment, checkout_url: string}
     */
    public function createSession(
        User $user,
        MeetingPack $meetingPack,
        string $successUrl,
        string $cancelUrl,
    ): array {
        $secret = config('services.stripe.secret');

        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('Stripe秘密鍵が設定されていません。');
        }

        $paymentId = (string) Str::ulid();

        $stripe = new StripeClient($secret);

        $session = $stripe->checkout->sessions->create(
            [
                'mode' => 'payment',
                'client_reference_id' => $paymentId,
                'line_items' => [
                    [
                        'price_data' => [
                            'currency' => 'jpy',
                            'unit_amount' => $meetingPack->price,
                            'product_data' => [
                                'name' => $meetingPack->name,
                                'description' => "{$meetingPack->meeting_count}回分の追加面談",
                            ],
                        ],
                        'quantity' => 1,
                    ],
                ],
                'metadata' => [
                    'payment_id' => $paymentId,
                    'user_id' => $user->id,
                    'meeting_pack_id' => $meetingPack->id,
                    'meeting_count' => (string) $meetingPack->meeting_count,
                ],
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
            ],
            [
                'idempotency_key' => 'meeting_quota_checkout_'.$paymentId,
            ]
        );

        try {
            if (
                ! is_string($session->url)
                || $session->url === ''
            ) {
                throw new RuntimeException(
                    'Stripe Checkout URLを取得できませんでした。'
                );
            }

            $payment = Payment::create([
                'id' => $paymentId,
                'user_id' => $user->id,
                'meeting_pack_id' => $meetingPack->id,
                'amount' => $meetingPack->price,
                'quantity' => $meetingPack->meeting_count,
                'stripe_checkout_session_id' => $session->id,
                'status' => PaymentStatus::Pending,
            ]);
        } catch (Throwable $exception) {
            // DB保存失敗時などに、未使用のCheckout Sessionを無効化する。
            try {
                $stripe->checkout->sessions->expire($session->id);
            } catch (Throwable $expirationException) {
                report($expirationException);
            }

            throw $exception;
        }

        return [
            'payment' => $payment,
            'checkout_url' => $session->url,
        ];
    }
}
