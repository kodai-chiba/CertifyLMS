<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Models\Meeting;
use App\Models\User;
use App\UseCases\Meeting\ShowAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_loads_required_meeting_relations(): void
    {
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();

        $meeting = Meeting::factory()
            ->reserved()
            ->forCoach($coach)
            ->forStudent($student)
            ->create();

        $result = app(ShowAction::class)($meeting);

        $this->assertTrue($result->is($meeting));
        $this->assertTrue($result->relationLoaded('enrollment'));
        $this->assertTrue($result->enrollment->relationLoaded('certification'));
        $this->assertTrue($result->relationLoaded('coach'));
        $this->assertTrue($result->relationLoaded('student'));
        $this->assertTrue($result->relationLoaded('canceledBy'));
        $this->assertTrue($result->relationLoaded('meetingMemo'));
    }

    public function test_preserves_already_loaded_relations(): void
    {
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();

        $meeting = Meeting::factory()
            ->reserved()
            ->forCoach($coach)
            ->forStudent($student)
            ->create();

        $meeting->load('coach');

        $loadedCoach = $meeting->getRelation('coach');

        $result = app(ShowAction::class)($meeting);

        $this->assertSame($loadedCoach, $result->getRelation('coach'));
        $this->assertTrue($result->relationLoaded('enrollment'));
        $this->assertTrue($result->relationLoaded('meetingMemo'));
    }
}
