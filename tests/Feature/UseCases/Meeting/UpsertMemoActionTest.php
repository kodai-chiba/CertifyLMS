<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Exceptions\Mentoring\MeetingStatusTransitionException;
use App\Models\Meeting;
use App\Models\MeetingMemo;
use App\Models\User;
use App\UseCases\Meeting\UpsertMemoAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpsertMemoActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_memo_for_reserved_meeting(): void
    {
        $student = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();

        $meeting = Meeting::factory()
            ->reserved()
            ->forCoach($coach)
            ->forStudent($student)
            ->create();

        app(UpsertMemoAction::class)($meeting, '初回面談メモ');

        $this->assertDatabaseHas('meeting_memos', [
            'meeting_id' => $meeting->id,
            'body' => '初回面談メモ',
        ]);

        $this->assertSame(
            1,
            MeetingMemo::query()->where('meeting_id', $meeting->id)->count()
        );
    }

    public function test_creates_memo_for_completed_meeting(): void
    {
        $student = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();

        $meeting = Meeting::factory()
            ->completed()
            ->forCoach($coach)
            ->forStudent($student)
            ->create();

        app(UpsertMemoAction::class)($meeting, '完了後の面談メモ');

        $this->assertDatabaseHas('meeting_memos', [
            'meeting_id' => $meeting->id,
            'body' => '完了後の面談メモ',
        ]);
    }

    public function test_updates_existing_memo_without_creating_duplicate(): void
    {
        $student = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();

        $meeting = Meeting::factory()
            ->completed()
            ->forCoach($coach)
            ->forStudent($student)
            ->create();

        $action = app(UpsertMemoAction::class);

        $action($meeting, '更新前のメモ');
        $action($meeting, '更新後のメモ');

        $this->assertDatabaseHas('meeting_memos', [
            'meeting_id' => $meeting->id,
            'body' => '更新後のメモ',
        ]);

        $this->assertDatabaseMissing('meeting_memos', [
            'meeting_id' => $meeting->id,
            'body' => '更新前のメモ',
        ]);

        $this->assertSame(
            1,
            MeetingMemo::query()->where('meeting_id', $meeting->id)->count()
        );
    }

    public function test_rejects_memo_for_canceled_meeting(): void
    {
        $student = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();

        $meeting = Meeting::factory()
            ->canceled()
            ->forCoach($coach)
            ->forStudent($student)
            ->create();

        $this->expectException(MeetingStatusTransitionException::class);

        try {
            app(UpsertMemoAction::class)($meeting, '保存できないメモ');
        } finally {
            $this->assertDatabaseMissing('meeting_memos', [
                'meeting_id' => $meeting->id,
            ]);
        }
    }
}
