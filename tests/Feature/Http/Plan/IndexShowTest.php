<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Plan;

use App\Enums\PlanStatus;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IndexShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_plan_index(): void
    {
        $admin = User::factory()->admin()->create();

        Plan::factory()->create([
            'name' => '一覧表示テストプラン',
        ]);

        $response = $this->actingAs($admin)->get(
            route('admin.plans.index')
        );

        $response->assertOk();
        $response->assertSee('一覧表示テストプラン');
        $response->assertViewHas('plans');
    }

    public function test_admin_can_filter_plans_by_keyword(): void
    {
        $admin = User::factory()->admin()->create();

        Plan::factory()->create([
            'name' => 'スタンダードプラン',
        ]);

        Plan::factory()->create([
            'name' => 'プレミアムプラン',
        ]);

        $response = $this->actingAs($admin)->get(
            route('admin.plans.index', [
                'keyword' => 'スタンダード',
            ])
        );

        $response->assertOk();
        $response->assertSee('スタンダードプラン');
        $response->assertDontSee('プレミアムプラン');
    }

    public function test_admin_can_filter_plans_by_status(): void
    {
        $admin = User::factory()->admin()->create();

        Plan::factory()->create([
            'name' => '公開中プラン',
            'status' => PlanStatus::Published,
        ]);

        Plan::factory()->create([
            'name' => '下書きプラン',
            'status' => PlanStatus::Draft,
        ]);

        $response = $this->actingAs($admin)->get(
            route('admin.plans.index', [
                'status' => PlanStatus::Published->value,
            ])
        );

        $response->assertOk();
        $response->assertSee('公開中プラン');
        $response->assertDontSee('下書きプラン');
    }

    public function test_plan_index_is_paginated(): void
    {
        $admin = User::factory()->admin()->create();

        Plan::factory()->count(21)->create();

        $response = $this->actingAs($admin)->get(
            route('admin.plans.index')
        );

        $response->assertOk();

        $plans = $response->viewData('plans');

        $this->assertSame(20, $plans->perPage());
        $this->assertSame(21, $plans->total());
        $this->assertSame(20, $plans->count());
    }

    public function test_admin_can_view_plan_detail_with_students_and_metadata(): void
    {
        $admin = User::factory()->admin()->create();

        $plan = Plan::factory()->create([
            'name' => '詳細表示テストプラン',
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
        ]);

        $student = User::factory()->student()->create([
            'plan_id' => $plan->id,
        ]);

        $response = $this->actingAs($admin)->get(
            route('admin.plans.show', $plan)
        );

        $response->assertOk();
        $response->assertSee('詳細表示テストプラン');

        $response->assertViewHas(
            'plan',
            function (Plan $viewPlan) use ($student): bool {
                return $viewPlan->relationLoaded('users')
                    && $viewPlan->relationLoaded('createdBy')
                    && $viewPlan->relationLoaded('updatedBy')
                    && $viewPlan->users->contains('id', $student->id);
            }
        );
    }

    public function test_coach_cannot_view_plan_management(): void
    {
        $coach = User::factory()->coach()->create();

        $response = $this->actingAs($coach)->get(
            route('admin.plans.index')
        );

        $response->assertForbidden();
    }

    public function test_student_cannot_view_plan_management(): void
    {
        $student = User::factory()->student()->create();

        $response = $this->actingAs($student)->get(
            route('admin.plans.index')
        );

        $response->assertForbidden();
    }
}
