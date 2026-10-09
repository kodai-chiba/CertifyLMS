<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * EnrollmentGoal モデルの主要リレーション・Cast・達成判定・表示順 Scope を検証する Unit テスト。
 */
class EnrollmentGoalTest extends TestCase
{
    use RefreshDatabase;

    public function test_enrollment_relation_returns_parent_enrollment(): void
    {
        // Arrange
        $enrollment = Enrollment::factory()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create();

        // Act
        $parent = $goal->enrollment;

        // Assert
        $this->assertTrue($parent->is($enrollment));
    }

    public function test_target_date_cast_returns_carbon_date(): void
    {
        // Arrange
        $goal = EnrollmentGoal::factory()->create([
            'target_date' => '2026-12-01',
        ]);

        // Act
        $fresh = $goal->fresh();

        // Assert
        $this->assertInstanceOf(Carbon::class, $fresh->target_date);
        $this->assertSame('2026-12-01', $fresh->target_date->toDateString());
    }

    public function test_achieved_at_cast_returns_carbon_datetime(): void
    {
        // Arrange
        $goal = EnrollmentGoal::factory()->achieved()->create();

        // Act
        $fresh = $goal->fresh();

        // Assert
        $this->assertInstanceOf(Carbon::class, $fresh->achieved_at);
    }

    public function test_is_achieved_returns_true_when_achieved_at_is_set(): void
    {
        // Arrange
        $goal = EnrollmentGoal::factory()->achieved()->create();

        // Act
        $isAchieved = $goal->fresh()->isAchieved();

        // Assert
        $this->assertTrue($isAchieved);
    }

    public function test_is_achieved_returns_false_when_achieved_at_is_null(): void
    {
        // Arrange
        $goal = EnrollmentGoal::factory()->unachieved()->create();

        // Act
        $isAchieved = $goal->fresh()->isAchieved();

        // Assert
        $this->assertFalse($isAchieved);
    }

    public function test_display_order_places_unachieved_before_achieved(): void
    {
        // Arrange
        $enrollment = Enrollment::factory()->create();

        $achieved = EnrollmentGoal::factory()
            ->forEnrollment($enrollment)
            ->achieved()
            ->create([
                'target_date' => now()->addDay()->toDateString(),
            ]);

        $unachieved = EnrollmentGoal::factory()
            ->forEnrollment($enrollment)
            ->unachieved()
            ->create([
                'target_date' => now()->addMonth()->toDateString(),
            ]);

        // Act
        $goals = EnrollmentGoal::query()
            ->where('enrollment_id', $enrollment->id)
            ->displayOrder()
            ->get();

        // Assert
        $this->assertSame(
            [$unachieved->id, $achieved->id],
            $goals->pluck('id')->all(),
        );
    }

    public function test_display_order_sorts_target_date_ascending_with_null_last(): void
    {
        // Arrange
        $enrollment = Enrollment::factory()->create();

        $noDate = EnrollmentGoal::factory()
            ->forEnrollment($enrollment)
            ->unachieved()
            ->create([
                'target_date' => null,
            ]);

        $later = EnrollmentGoal::factory()
            ->forEnrollment($enrollment)
            ->unachieved()
            ->create([
                'target_date' => '2026-12-20',
            ]);

        $earlier = EnrollmentGoal::factory()
            ->forEnrollment($enrollment)
            ->unachieved()
            ->create([
                'target_date' => '2026-11-10',
            ]);

        // Act
        $goals = EnrollmentGoal::query()
            ->where('enrollment_id', $enrollment->id)
            ->displayOrder()
            ->get();

        // Assert
        $this->assertSame(
            [$earlier->id, $later->id, $noDate->id],
            $goals->pluck('id')->all(),
        );
    }

    public function test_display_order_uses_newest_created_at_for_same_conditions(): void
    {
        // Arrange
        $enrollment = Enrollment::factory()->create();

        $older = EnrollmentGoal::factory()
            ->forEnrollment($enrollment)
            ->unachieved()
            ->create([
                'target_date' => '2026-12-01',
                'created_at' => now()->subDay(),
                'updated_at' => now()->subDay(),
            ]);

        $newer = EnrollmentGoal::factory()
            ->forEnrollment($enrollment)
            ->unachieved()
            ->create([
                'target_date' => '2026-12-01',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        // Act
        $goals = EnrollmentGoal::query()
            ->where('enrollment_id', $enrollment->id)
            ->displayOrder()
            ->get();

        // Assert
        $this->assertSame(
            [$newer->id, $older->id],
            $goals->pluck('id')->all(),
        );
    }

    public function test_enrollment_goals_relation_uses_display_order(): void
    {
        // Arrange
        $enrollment = Enrollment::factory()->create();

        $achieved = EnrollmentGoal::factory()
            ->forEnrollment($enrollment)
            ->achieved()
            ->create([
                'target_date' => now()->toDateString(),
            ]);

        $unachieved = EnrollmentGoal::factory()
            ->forEnrollment($enrollment)
            ->unachieved()
            ->create([
                'target_date' => now()->addMonth()->toDateString(),
            ]);

        // Act
        $goals = $enrollment->fresh()->goals;

        // Assert
        $this->assertSame(
            [$unachieved->id, $achieved->id],
            $goals->pluck('id')->all(),
        );
    }
}
