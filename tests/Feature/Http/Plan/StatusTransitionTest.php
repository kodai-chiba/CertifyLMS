<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Plan;

use App\Enums\PlanStatus;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatusTransitionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_publish_draft_plan(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->create([
            'status' => PlanStatus::Draft,
        ]);

        $response = $this->actingAs($admin)->post(
            route('admin.plans.publish', $plan)
        );

        $response->assertRedirect(route('admin.plans.show', $plan));

        $this->assertDatabaseHas('plans', [
            'id' => $plan->id,
            'status' => PlanStatus::Published->value,
            'updated_by_user_id' => $admin->id,
        ]);
    }

    public function test_admin_can_archive_published_plan(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->create([
            'status' => PlanStatus::Published,
        ]);

        $response = $this->actingAs($admin)->post(
            route('admin.plans.archive', $plan)
        );

        $response->assertRedirect(route('admin.plans.show', $plan));

        $this->assertDatabaseHas('plans', [
            'id' => $plan->id,
            'status' => PlanStatus::Archived->value,
            'updated_by_user_id' => $admin->id,
        ]);
    }

    public function test_admin_can_unarchive_archived_plan_to_draft(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->create([
            'status' => PlanStatus::Archived,
        ]);

        $response = $this->actingAs($admin)->post(
            route('admin.plans.unarchive', $plan)
        );

        $response->assertRedirect(route('admin.plans.show', $plan));

        $this->assertDatabaseHas('plans', [
            'id' => $plan->id,
            'status' => PlanStatus::Draft->value,
            'updated_by_user_id' => $admin->id,
        ]);
    }

    public function test_published_plan_cannot_be_published_again(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->create([
            'status' => PlanStatus::Published,
        ]);

        $response = $this->actingAs($admin)->post(
            route('admin.plans.publish', $plan)
        );

        $response->assertForbidden();

        $this->assertDatabaseHas('plans', [
            'id' => $plan->id,
            'status' => PlanStatus::Published->value,
        ]);
    }

    public function test_draft_plan_cannot_be_archived_directly(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->create([
            'status' => PlanStatus::Draft,
        ]);

        $response = $this->actingAs($admin)->post(
            route('admin.plans.archive', $plan)
        );

        $response->assertForbidden();

        $this->assertDatabaseHas('plans', [
            'id' => $plan->id,
            'status' => PlanStatus::Draft->value,
        ]);
    }

    public function test_draft_plan_cannot_be_unarchived(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->create([
            'status' => PlanStatus::Draft,
        ]);

        $response = $this->actingAs($admin)->post(
            route('admin.plans.unarchive', $plan)
        );

        $response->assertForbidden();

        $this->assertDatabaseHas('plans', [
            'id' => $plan->id,
            'status' => PlanStatus::Draft->value,
        ]);
    }

    public function test_coach_cannot_change_plan_status(): void
    {
        $coach = User::factory()->coach()->create();
        $plan = Plan::factory()->create([
            'status' => PlanStatus::Draft,
        ]);

        $response = $this->actingAs($coach)->post(
            route('admin.plans.publish', $plan)
        );

        $response->assertForbidden();

        $this->assertDatabaseHas('plans', [
            'id' => $plan->id,
            'status' => PlanStatus::Draft->value,
        ]);
    }

    public function test_student_cannot_change_plan_status(): void
    {
        $student = User::factory()->student()->create();
        $plan = Plan::factory()->create([
            'status' => PlanStatus::Draft,
        ]);

        $response = $this->actingAs($student)->post(
            route('admin.plans.publish', $plan)
        );

        $response->assertForbidden();

        $this->assertDatabaseHas('plans', [
            'id' => $plan->id,
            'status' => PlanStatus::Draft->value,
        ]);
    }
}
