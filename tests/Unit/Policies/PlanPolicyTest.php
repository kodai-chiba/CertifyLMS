<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Enums\PlanStatus;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserPlanLog;
use App\Policies\PlanPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_create_and_update_plans(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->create();
        $policy = new PlanPolicy;

        $this->assertTrue($policy->viewAny($admin));
        $this->assertTrue($policy->view($admin, $plan));
        $this->assertTrue($policy->create($admin));
        $this->assertTrue($policy->update($admin, $plan));
    }

    public function test_coach_and_student_cannot_manage_plans(): void
    {
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();
        $plan = Plan::factory()->create();
        $policy = new PlanPolicy;

        foreach ([$coach, $student] as $user) {
            $this->assertFalse($policy->viewAny($user));
            $this->assertFalse($policy->view($user, $plan));
            $this->assertFalse($policy->create($user));
            $this->assertFalse($policy->update($user, $plan));
            $this->assertFalse($policy->delete($user, $plan));
            $this->assertFalse($policy->publish($user, $plan));
            $this->assertFalse($policy->archive($user, $plan));
            $this->assertFalse($policy->unarchive($user, $plan));
        }
    }

    public function test_admin_can_delete_draft_plan_without_students(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->create([
            'status' => PlanStatus::Draft,
        ]);
        $policy = new PlanPolicy;

        $this->assertTrue($policy->delete($admin, $plan));
    }

    public function test_admin_cannot_delete_published_plan(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->create([
            'status' => PlanStatus::Published,
        ]);
        $policy = new PlanPolicy;

        $this->assertFalse($policy->delete($admin, $plan));
    }

    public function test_admin_cannot_delete_archived_plan(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->create([
            'status' => PlanStatus::Archived,
        ]);
        $policy = new PlanPolicy;

        $this->assertFalse($policy->delete($admin, $plan));
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
        $policy = new PlanPolicy;

        $this->assertFalse($policy->delete($admin, $plan));
    }

    public function test_admin_cannot_delete_draft_plan_with_usage_history(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->create([
            'status' => PlanStatus::Draft,
        ]);

        UserPlanLog::factory()->create([
            'plan_id' => $plan->id,
        ]);

        $policy = new PlanPolicy;

        $this->assertFalse($policy->delete($admin, $plan));
    }

    public function test_admin_can_publish_only_draft_plan(): void
    {
        $admin = User::factory()->admin()->create();
        $draft = Plan::factory()->create(['status' => PlanStatus::Draft]);
        $published = Plan::factory()->create(['status' => PlanStatus::Published]);
        $archived = Plan::factory()->create(['status' => PlanStatus::Archived]);
        $policy = new PlanPolicy;

        $this->assertTrue($policy->publish($admin, $draft));
        $this->assertFalse($policy->publish($admin, $published));
        $this->assertFalse($policy->publish($admin, $archived));
    }

    public function test_admin_can_archive_only_published_plan(): void
    {
        $admin = User::factory()->admin()->create();
        $draft = Plan::factory()->create(['status' => PlanStatus::Draft]);
        $published = Plan::factory()->create(['status' => PlanStatus::Published]);
        $archived = Plan::factory()->create(['status' => PlanStatus::Archived]);
        $policy = new PlanPolicy;

        $this->assertFalse($policy->archive($admin, $draft));
        $this->assertTrue($policy->archive($admin, $published));
        $this->assertFalse($policy->archive($admin, $archived));
    }

    public function test_admin_can_unarchive_only_archived_plan(): void
    {
        $admin = User::factory()->admin()->create();
        $draft = Plan::factory()->create(['status' => PlanStatus::Draft]);
        $published = Plan::factory()->create(['status' => PlanStatus::Published]);
        $archived = Plan::factory()->create(['status' => PlanStatus::Archived]);
        $policy = new PlanPolicy;

        $this->assertFalse($policy->unarchive($admin, $draft));
        $this->assertFalse($policy->unarchive($admin, $published));
        $this->assertTrue($policy->unarchive($admin, $archived));
    }
}
