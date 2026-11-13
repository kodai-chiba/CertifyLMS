<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * 決済レコードの初期値。
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'meeting_pack_id' => MeetingPack::factory(),
            'amount' => 5000,
            'quantity' => 1,
            'stripe_checkout_session_id' => 'cs_test_'.Str::random(32),
            'stripe_payment_intent_id' => null,
            'status' => PaymentStatus::Pending,
            'paid_at' => null,
        ];
    }

    /**
     * 決済完了状態。
     */
    public function paid(): static
    {
        return $this->state(fn (array $attributes): array => [
            'stripe_payment_intent_id' => 'pi_test_'.Str::random(32),
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);
    }

    /**
     * 決済失敗状態。
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PaymentStatus::Failed,
        ]);
    }

    /**
     * キャンセル状態。
     */
    public function canceled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PaymentStatus::Canceled,
        ]);
    }
}
