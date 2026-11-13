<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\MeetingPackStatus;
use App\Enums\MeetingQuotaTransactionType;
use App\Enums\PaymentStatus;
use App\Models\MeetingPack;
use App\Models\MeetingQuotaTransaction;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PaymentSeeder extends Seeder
{
    public function run(): void
    {
        $student = User::query()
            ->where('email', 'student@certify-lms.test')
            ->first();

        if ($student === null) {
            $this->command?->warn(
                'PaymentSeeder: 固定受講生が存在しません。先にUserSeederを実行してください。'
            );

            return;
        }

        $packs = MeetingPack::query()
            ->where('status', MeetingPackStatus::Published->value)
            ->whereIn('meeting_count', [1, 5, 10])
            ->get()
            ->keyBy('meeting_count');

        if (! $packs->has(1) || ! $packs->has(5) || ! $packs->has(10)) {
            $this->command?->warn(
                'PaymentSeeder: 必要な公開中の面談パックが存在しません。先にMeetingPackSeederを実行してください。'
            );

            return;
        }

        DB::transaction(function () use ($student, $packs): void {
            $paidPack = $packs->get(5);

            $paidPayment = Payment::query()->firstOrCreate(
                [
                    'stripe_checkout_session_id' => 'cs_test_seed_paid_meeting_pack',
                ],
                [
                    'user_id' => $student->id,
                    'meeting_pack_id' => $paidPack->id,
                    'amount' => $paidPack->price,
                    'quantity' => $paidPack->meeting_count,
                    'stripe_payment_intent_id' => 'pi_test_seed_paid_meeting_pack',
                    'status' => PaymentStatus::Paid,
                    'paid_at' => now()->subDays(7),
                ]
            );

            MeetingQuotaTransaction::query()->firstOrCreate(
                [
                    'related_payment_id' => $paidPayment->id,
                    'type' => MeetingQuotaTransactionType::Purchased->value,
                ],
                [
                    'user_id' => $student->id,
                    'amount' => $paidPayment->quantity,
                    'occurred_at' => $paidPayment->paid_at,
                ]
            );

            $pendingPack = $packs->get(1);

            Payment::query()->firstOrCreate(
                [
                    'stripe_checkout_session_id' => 'cs_test_seed_pending_meeting_pack',
                ],
                [
                    'user_id' => $student->id,
                    'meeting_pack_id' => $pendingPack->id,
                    'amount' => $pendingPack->price,
                    'quantity' => $pendingPack->meeting_count,
                    'status' => PaymentStatus::Pending,
                ]
            );

            $failedPack = $packs->get(10);

            Payment::query()->firstOrCreate(
                [
                    'stripe_checkout_session_id' => 'cs_test_seed_failed_meeting_pack',
                ],
                [
                    'user_id' => $student->id,
                    'meeting_pack_id' => $failedPack->id,
                    'amount' => $failedPack->price,
                    'quantity' => $failedPack->meeting_count,
                    'status' => PaymentStatus::Failed,
                ]
            );
        });
    }
}
