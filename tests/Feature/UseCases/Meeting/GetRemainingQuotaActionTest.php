<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Models\MeetingQuotaTransaction;
use App\Models\User;
use App\UseCases\Meeting\GetRemainingQuotaAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GetRemainingQuotaActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_initial_quota_when_no_transactions_exist(): void
    {
        $student = User::factory()->student()->create([
            'max_meetings' => 4,
        ]);

        $remaining = app(GetRemainingQuotaAction::class)($student);

        $this->assertSame(4, $remaining);
    }

    public function test_returns_remaining_quota_after_consumption_and_refund(): void
    {
        $student = User::factory()->student()->create([
            'max_meetings' => 4,
        ]);

        MeetingQuotaTransaction::factory()->consumed()->count(2)->state([
            'user_id' => $student->id,
        ])->create();

        MeetingQuotaTransaction::factory()->refunded()->state([
            'user_id' => $student->id,
        ])->create();

        $remaining = app(GetRemainingQuotaAction::class)($student);

        $this->assertSame(3, $remaining);
    }

    public function test_returns_zero_when_quota_is_exhausted(): void
    {
        $student = User::factory()->student()->create([
            'max_meetings' => 1,
        ]);

        MeetingQuotaTransaction::factory()->consumed()->state([
            'user_id' => $student->id,
        ])->create();

        $remaining = app(GetRemainingQuotaAction::class)($student);

        $this->assertSame(0, $remaining);
    }

    public function test_excludes_other_students_transactions(): void
    {
        $student = User::factory()->student()->create([
            'max_meetings' => 4,
        ]);

        $otherStudent = User::factory()->student()->create([
            'max_meetings' => 5,
        ]);

        MeetingQuotaTransaction::factory()->consumed()->state([
            'user_id' => $otherStudent->id,
        ])->create();

        $remaining = app(GetRemainingQuotaAction::class)($student);

        $this->assertSame(4, $remaining);
    }
}
