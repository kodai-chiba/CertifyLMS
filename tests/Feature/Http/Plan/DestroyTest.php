<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Plan;

use App\Enums\PlanStatus;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DestroyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_delete_unused_draft_plan(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->create([
            'status' => PlanStatus::Draft,
        ]);

        $response = $this->actingAs($admin)->delete(
            route('admin.plans.destroy', $plan)
        );

        $response->assertRedirect(route('admin.plans.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseMissing('plans', [
            'id' => $plan->id,
        ]);
    }

    public function test_admin_cannot_delete_published_plan(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->create([
            'status' => PlanStatus::Published,
        ]);

        $response = $this->actingAs($admin)->delete(
            route('admin.plans.destroy', $plan)
        );

        $response->assertForbidden();

        $this->assertDatabaseHas('plans', [
            'id' => $plan->id,
        ]);
    }

    public function test_admin_cannot_delete_archived_plan(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->create([
            'status' => PlanStatus::Archived,
        ]);

        $response = $this->actingAs($admin)->delete(
            route('admin.plans.destroy', $plan)
        );

        $response->assertForbidden();

        $this->assertDatabaseHas('plans', [
            'id' => $plan->id,
        ]);
    }

    public function test_admin_cannot_delete_draft_plan_with_student(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->create([
            'status' => PlanStatus::Draft,
        ]);

        User::factory()->student()->create([
            'plan_id' => $plan->id,
        ]);

        $response = $this->actingAs($admin)->delete(
            route('admin.plans.destroy', $plan)
        );

        $response->assertForbidden();

        $this->assertDatabaseHas('plans', [
            'id' => $plan->id,
        ]);
    }

    public function test_coach_cannot_delete_plan(): void
    {
        $coach = User::factory()->coach()->create();
        $plan = Plan::factory()->create([
            'status' => PlanStatus::Draft,
        ]);

        $response = $this->actingAs($coach)->delete(
            route('admin.plans.destroy', $plan)
        );

        $response->assertForbidden();

        $this->assertDatabaseHas('plans', [
            'id' => $plan->id,
        ]);
    }

    public function test_student_cannot_delete_plan(): void
    {
        $student = User::factory()->student()->create();
        $plan = Plan::factory()->create([
            'status' => PlanStatus::Draft,
        ]);

        $response = $this->actingAs($student)->delete(
            route('admin.plans.destroy', $plan)
        );

        $response->assertForbidden();

        $this->assertDatabaseHas('plans', [
            'id' => $plan->id,
        ]);
    }
}
