<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Plan;

use App\Enums\PlanStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_plan_as_draft(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post(route('admin.plans.store'), [
            'name' => 'スタンダードプラン',
            'description' => 'テスト用プランです。',
            'duration_days' => 365,
            'default_meeting_quota' => 12,
            'sort_order' => 10,
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('plans', [
            'name' => 'スタンダードプラン',
            'description' => 'テスト用プランです。',
            'duration_days' => 365,
            'default_meeting_quota' => 12,
            'status' => PlanStatus::Draft->value,
            'sort_order' => 10,
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
        ]);
    }

    public function test_plan_is_always_created_as_draft_even_if_status_is_submitted(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.plans.store'), [
            'name' => '公開指定プラン',
            'duration_days' => 365,
            'default_meeting_quota' => 10,
            'sort_order' => 0,
            'status' => PlanStatus::Published->value,
        ]);

        $this->assertDatabaseHas('plans', [
            'name' => '公開指定プラン',
            'status' => PlanStatus::Draft->value,
        ]);
    }

    public function test_store_validates_required_and_numeric_ranges(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->from(route('admin.plans.create'))
            ->post(route('admin.plans.store'), [
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

        $this->assertDatabaseCount('plans', 0);
    }

    public function test_duration_days_accepts_ticket_boundary_values(): void
    {
        $admin = User::factory()->admin()->create();

        foreach ([1, 3650] as $durationDays) {
            $response = $this->actingAs($admin)->post(route('admin.plans.store'), [
                'name' => "期間{$durationDays}日プラン",
                'duration_days' => $durationDays,
                'default_meeting_quota' => 0,
                'sort_order' => 0,
            ]);

            $response->assertSessionDoesntHaveErrors('duration_days');
        }
    }

    public function test_default_meeting_quota_accepts_ticket_boundary_values(): void
    {
        $admin = User::factory()->admin()->create();

        foreach ([0, 1000] as $quota) {
            $response = $this->actingAs($admin)->post(route('admin.plans.store'), [
                'name' => "面談{$quota}回プラン",
                'duration_days' => 365,
                'default_meeting_quota' => $quota,
                'sort_order' => 0,
            ]);

            $response->assertSessionDoesntHaveErrors('default_meeting_quota');
        }
    }

    public function test_coach_cannot_create_plan(): void
    {
        $coach = User::factory()->coach()->create();

        $response = $this->actingAs($coach)->post(route('admin.plans.store'), [
            'name' => '不正作成',
            'duration_days' => 365,
            'default_meeting_quota' => 10,
            'sort_order' => 0,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('plans', [
            'name' => '不正作成',
        ]);
    }

    public function test_student_cannot_create_plan(): void
    {
        $student = User::factory()->student()->create();

        $response = $this->actingAs($student)->post(route('admin.plans.store'), [
            'name' => '不正作成',
            'duration_days' => 365,
            'default_meeting_quota' => 10,
            'sort_order' => 0,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('plans', [
            'name' => '不正作成',
        ]);
    }
}
