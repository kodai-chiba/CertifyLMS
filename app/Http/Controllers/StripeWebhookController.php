<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\PaymentStatus;
use App\Models\MeetingQuotaTransaction;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;

class StripeWebhookController extends Controller
{
    /**
     * Stripe Webhookを受信する。
     */
    public function handle(Request $request): JsonResponse
    {
        $webhookSecret = config('services.stripe.webhook_secret');
        $signature = $request->header('Stripe-Signature');

        if (! is_string($webhookSecret) || $webhookSecret === '') {
            return response()->json([
                'message' => 'Webhook secret is not configured.',
            ], 500);
        }

        if (! is_string($signature) || $signature === '') {
            return response()->json([
                'message' => 'Missing Stripe signature.',
            ], 400);
        }

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                $signature,
                $webhookSecret
            );
        } catch (UnexpectedValueException|SignatureVerificationException) {
            return response()->json([
                'message' => 'Invalid webhook signature or payload.',
            ], 400);
        }

        $supportedEvents = [
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded',
            'checkout.session.async_payment_failed',
            'checkout.session.expired',
        ];

        if (! in_array($event->type, $supportedEvents, true)) {
            return response()->json([
                'received' => true,
            ]);
        }

        $session = $event->data->object;

        if (
            ! is_string($session->id ?? null)
            || ($session->object ?? null) !== 'checkout.session'
        ) {
            return response()->json([
                'message' => 'Invalid Checkout Session.',
            ], 400);
        }

        $isSuccessfulEvent = in_array($event->type, [
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded',
        ], true);

        if (
            $isSuccessfulEvent
            && ($session->payment_status ?? null) !== 'paid'
        ) {
            return response()->json([
                'received' => true,
            ]);
        }

        $payment = DB::transaction(function () use (
            $session,
            $event,
            $isSuccessfulEvent
        ): ?Payment {
            $payment = Payment::query()
                ->where('stripe_checkout_session_id', $session->id)
                ->lockForUpdate()
                ->first();

            if ($payment === null) {
                return null;
            }

            // 決済完了後は、後続イベントで状態を変更しない。
            if ($payment->status === PaymentStatus::Paid) {
                return $payment;
            }

            if ($isSuccessfulEvent) {
                // Checkoutの購入情報を照合する。
                if (
                    (int) ($session->amount_total ?? -1) !== $payment->amount
                    || ($session->currency ?? null) !== 'jpy'
                    || (
                        isset($session->mode)
                        && $session->mode !== 'payment'
                    )
                    || ! is_string($session->client_reference_id ?? null)
                    || strtoupper($session->client_reference_id) !== strtoupper($payment->id)
                ) {
                    throw new UnexpectedValueException(
                        'Stripe payment information mismatch.'
                    );
                }

                $paymentIntentId = $session->payment_intent ?? null;

                if (is_object($paymentIntentId)) {
                    $paymentIntentId = $paymentIntentId->id ?? null;
                }

                $payment->update([
                    'status' => PaymentStatus::Paid,
                    'stripe_payment_intent_id' => $paymentIntentId,
                    'paid_at' => now(),
                ]);

                MeetingQuotaTransaction::create([
                    'user_id' => $payment->user_id,
                    'type' => MeetingQuotaTransactionType::Purchased,
                    'amount' => $payment->quantity,
                    'related_payment_id' => $payment->id,
                    'occurred_at' => now(),
                    'note' => '追加面談パック購入',
                ]);

                return $payment;
            }

            // 決済失敗・期限切れは面談回数を付与しない。
            $status = match ($event->type) {
                'checkout.session.async_payment_failed' => PaymentStatus::Failed,
                'checkout.session.expired' => PaymentStatus::Canceled,
            };

            $payment->update([
                'status' => $status,
            ]);

            return $payment;
        });

        if ($payment === null) {
            // Stripeの再送によってDB登録との競合から回復できるようにする。
            return response()->json([
                'message' => 'Payment not found.',
            ], 500);
        }

        return response()->json([
            'received' => true,
        ]);
    }
}
