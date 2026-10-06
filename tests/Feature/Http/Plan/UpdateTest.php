<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Plan;

use App\Enums\PlanStatus;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_update_plan_basic_information(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->create([
            'name' => '変更前プラン',
            'description' => '変更前',
            'duration_days' => 180,
            'default_meeting_quota' => 5,
            'status' => PlanStatus::Draft,
            'sort_order' => 1,
        ]);

        $response = $this->actingAs($admin)->put(
            route('admin.plans.update', $plan),
            [
                'name' => '変更後プラン',
                'description' => '変更後の説明です。',
                'duration_days' => 365,
                'default_meeting_quota' => 12,
                'sort_order' => 10,
            ]
        );

        $response->assertRedirect(route('admin.plans.show', $plan));

        $this->assertDatabaseHas('plans', [
            'id' => $plan->id,
            'name' => '変更後プラン',
            'description' => '変更後の説明です。',
            'duration_days' => 365,
            'default_meeting_quota' => 12,
            'status' => PlanStatus::Draft->value,
            'sort_order' => 10,
            'updated_by_user_id' => $admin->id,
        ]);
    }

    public function test_status_cannot_be_changed_from_update_form(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->create([
            'status' => PlanStatus::Draft,
        ]);

        $response = $this->actingAs($admin)->put(
            route('admin.plans.update', $plan),
            [
                'name' => 'ステータス変更テスト',
                'duration_days' => 365,
                'default_meeting_quota' => 10,
                'sort_order' => 0,
                'status' => PlanStatus::Published->value,
            ]
        );

        $response->assertRedirect(route('admin.plans.show', $plan));

        $this->assertDatabaseHas('plans', [
            'id' => $plan->id,
            'status' => PlanStatus::Draft->value,
        ]);
    }

    public function test_update_validates_required_and_numeric_ranges(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->create();

        $response = $this->actingAs($admin)
            ->from(route('admin.plans.edit', $plan))
            ->put(route('admin.plans.update', $plan), [
                'name' => '',
                'duration_days' => 0,
                'default_meeting_quota' => 1001,
                'sort_order' => -1,
            ]);

        $response->assertSessionHasErrors([
            'name',
            'duration_days',
            'default_meeting_quota',
            'sort_order',
        ]);
    }

    public function test_update_accepts_numeric_boundary_values(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->create();

        $response = $this->actingAs($admin)->put(
            route('admin.plans.update', $plan),
            [
                'name' => '境界値プラン',
                'duration_days' => 3650,
                'default_meeting_quota' => 1000,
                'sort_order' => 0,
            ]
        );

        $response->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('plans', [
            'id' => $plan->id,
            'duration_days' => 3650,
            'default_meeting_quota' => 1000,
        ]);
    }

    public function test_coach_cannot_update_plan(): void
    {
        $coach = User::factory()->coach()->create();
        $plan = Plan::factory()->create([
            'name' => '変更前プラン',
        ]);

        $response = $this->actingAs($coach)->put(
            route('admin.plans.update', $plan),
            [
                'name' => '不正変更',
                'duration_days' => 365,
                'default_meeting_quota' => 10,
                'sort_order' => 0,
            ]
        );

        $response->assertForbidden();

        $this->assertDatabaseHas('plans', [
            'id' => $plan->id,
            'name' => '変更前プラン',
        ]);
    }

    public function test_student_cannot_update_plan(): void
    {
        $student = User::factory()->student()->create();
        $plan = Plan::factory()->create([
            'name' => '変更前プラン',
        ]);

        $response = $this->actingAs($student)->put(
            route('admin.plans.update', $plan),
            [
                'name' => '不正変更',
                'duration_days' => 365,
                'default_meeting_quota' => 10,
                'sort_order' => 0,
            ]
        );

        $response->assertForbidden();

        $this->assertDatabaseHas('plans', [
            'id' => $plan->id,
            'name' => '変更前プラン',
        ]);
    }
}
