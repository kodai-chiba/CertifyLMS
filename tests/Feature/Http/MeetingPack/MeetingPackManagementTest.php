<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeetingPackManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_meeting_pack_index(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = MeetingPack::factory()->create([
            'name' => 'テスト面談パック',
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.meeting-packs.index'));

        $response->assertOk();
        $response->assertSee('テスト面談パック');
        $response->assertViewIs('meeting-pack.management.index');
        $response->assertViewHas('plans');
    }

    public function test_admin_can_view_meeting_pack_detail(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = MeetingPack::factory()->create([
            'name' => '詳細確認パック',
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.meeting-packs.show', $plan));

        $response->assertOk();
        $response->assertSee('詳細確認パック');
        $response->assertViewIs('meeting-pack.management.show');
    }

    public function test_student_cannot_access_meeting_pack_management(): void
    {
        $student = User::factory()->student()->create();

        $response = $this->actingAs($student)
            ->get(route('admin.meeting-packs.index'));

        $response->assertForbidden();
    }

    public function test_coach_cannot_access_meeting_pack_management(): void
    {
        $coach = User::factory()->coach()->create();

        $response = $this->actingAs($coach)
            ->get(route('admin.meeting-packs.index'));

        $response->assertForbidden();
    }

    public function test_admin_can_create_meeting_pack_as_draft(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->post(route('admin.meeting-packs.store'), [
                'name' => '新規面談パック',
                'description' => 'テスト用の面談パックです。',
                'meeting_count' => 3,
                'price' => 9000,
                'stripe_price_id' => 'price_test',
                'sort_order' => 10,
            ]);

        $plan = MeetingPack::query()
            ->where('name', '新規面談パック')
            ->firstOrFail();

        $response->assertRedirect(route('admin.meeting-packs.show', $plan));

        $this->assertSame(MeetingPackStatus::Draft, $plan->status);
        $this->assertSame(3, $plan->meeting_count);
        $this->assertSame(9000, $plan->price);
        $this->assertSame($admin->id, $plan->created_by_user_id);
        $this->assertSame($admin->id, $plan->updated_by_user_id);
    }

    public function test_status_from_request_is_ignored_when_creating_pack(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.meeting-packs.store'), [
                'name' => '公開指定パック',
                'description' => null,
                'meeting_count' => 1,
                'price' => 3000,
                'sort_order' => 10,
                'status' => MeetingPackStatus::Published->value,
            ]);

        $plan = MeetingPack::query()
            ->where('name', '公開指定パック')
            ->firstOrFail();

        $this->assertSame(MeetingPackStatus::Draft, $plan->status);
    }

    public function test_admin_can_update_basic_information_without_changing_status(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = MeetingPack::factory()->published()->create([
            'name' => '更新前パック',
            'meeting_count' => 1,
            'price' => 3000,
        ]);

        $response = $this->actingAs($admin)
            ->patch(route('admin.meeting-packs.update', $plan), [
                'name' => '更新後パック',
                'description' => '更新後の説明です。',
                'meeting_count' => 5,
                'price' => 12000,
                'stripe_price_id' => 'price_updated',
                'sort_order' => 20,
                'status' => MeetingPackStatus::Archived->value,
            ]);

        $response->assertRedirect(route('admin.meeting-packs.show', $plan));

        $plan->refresh();

        $this->assertSame('更新後パック', $plan->name);
        $this->assertSame(5, $plan->meeting_count);
        $this->assertSame(12000, $plan->price);
        $this->assertSame(MeetingPackStatus::Published, $plan->status);
        $this->assertSame($admin->id, $plan->updated_by_user_id);
    }

    public function test_admin_can_publish_draft_pack(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = MeetingPack::factory()->draft()->create();

        $response = $this->actingAs($admin)
            ->post(route('admin.meeting-packs.publish', $plan));

        $response->assertRedirect(route('admin.meeting-packs.show', $plan));

        $plan->refresh();

        $this->assertSame(MeetingPackStatus::Published, $plan->status);
        $this->assertSame($admin->id, $plan->updated_by_user_id);
    }

    public function test_admin_can_archive_published_pack(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = MeetingPack::factory()->published()->create();

        $response = $this->actingAs($admin)
            ->post(route('admin.meeting-packs.archive', $plan));

        $response->assertRedirect(route('admin.meeting-packs.show', $plan));

        $plan->refresh();

        $this->assertSame(MeetingPackStatus::Archived, $plan->status);
        $this->assertSame($admin->id, $plan->updated_by_user_id);
    }

    public function test_admin_can_unarchive_archived_pack_to_draft(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = MeetingPack::factory()->archived()->create();

        $response = $this->actingAs($admin)
            ->post(route('admin.meeting-packs.unarchive', $plan));

        $response->assertRedirect(route('admin.meeting-packs.show', $plan));

        $plan->refresh();

        $this->assertSame(MeetingPackStatus::Draft, $plan->status);
        $this->assertSame($admin->id, $plan->updated_by_user_id);
    }

    public function test_invalid_status_transition_is_forbidden(): void
    {
        $admin = User::factory()->admin()->create();

        $published = MeetingPack::factory()->published()->create();
        $archived = MeetingPack::factory()->archived()->create();
        $draft = MeetingPack::factory()->draft()->create();

        $this->actingAs($admin)
            ->post(route('admin.meeting-packs.publish', $published))
            ->assertForbidden();

        $this->actingAs($admin)
            ->post(route('admin.meeting-packs.publish', $archived))
            ->assertForbidden();

        $this->actingAs($admin)
            ->post(route('admin.meeting-packs.archive', $draft))
            ->assertForbidden();

        $this->actingAs($admin)
            ->post(route('admin.meeting-packs.unarchive', $published))
            ->assertForbidden();

        $this->assertSame(
            MeetingPackStatus::Published,
            $published->fresh()->status
        );

        $this->assertSame(
            MeetingPackStatus::Archived,
            $archived->fresh()->status
        );

        $this->assertSame(
            MeetingPackStatus::Draft,
            $draft->fresh()->status
        );
    }

    public function test_published_pack_cannot_be_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = MeetingPack::factory()->published()->create();

        $response = $this->actingAs($admin)
            ->delete(route('admin.meeting-packs.destroy', $plan));

        $response->assertForbidden();

        $this->assertDatabaseHas('meeting_packs', [
            'id' => $plan->id,
        ]);
    }

    public function test_draft_pack_can_be_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = MeetingPack::factory()->draft()->create();

        $response = $this->actingAs($admin)
            ->delete(route('admin.meeting-packs.destroy', $plan));

        $response->assertRedirect(route('admin.meeting-packs.index'));

        $this->assertDatabaseMissing('meeting_packs', [
            'id' => $plan->id,
        ]);
    }

    public function test_archived_pack_can_be_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = MeetingPack::factory()->archived()->create();

        $response = $this->actingAs($admin)
            ->delete(route('admin.meeting-packs.destroy', $plan));

        $response->assertRedirect(route('admin.meeting-packs.index'));

        $this->assertDatabaseMissing('meeting_packs', [
            'id' => $plan->id,
        ]);
    }

    public function test_index_can_filter_by_keyword(): void
    {
        $admin = User::factory()->admin()->create();

        MeetingPack::factory()->create([
            'name' => 'スペシャル5回パック',
        ]);

        MeetingPack::factory()->create([
            'name' => '通常1回パック',
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.meeting-packs.index', [
                'keyword' => 'スペシャル',
            ]));

        $response->assertOk();
        $response->assertSee('スペシャル5回パック');
        $response->assertDontSee('通常1回パック');
    }

    public function test_index_can_filter_by_status(): void
    {
        $admin = User::factory()->admin()->create();

        MeetingPack::factory()->draft()->create([
            'name' => '下書きパック',
        ]);

        MeetingPack::factory()->published()->create([
            'name' => '公開パック',
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.meeting-packs.index', [
                'status' => MeetingPackStatus::Published->value,
            ]));

        $response->assertOk();
        $response->assertSee('公開パック');
        $response->assertDontSee('下書きパック');
    }

    public function test_store_validates_required_fields_and_ranges(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->from(route('admin.meeting-packs.create'))
            ->post(route('admin.meeting-packs.store'), [
                'name' => '',
                'description' => str_repeat('a', 2001),
                'meeting_count' => 101,
                'price' => 1000001,
                'stripe_price_id' => str_repeat('a', 256),
                'sort_order' => -1,
            ]);

        $response->assertRedirect(route('admin.meeting-packs.create'));

        $response->assertSessionHasErrors([
            'name',
            'description',
            'meeting_count',
            'price',
            'stripe_price_id',
            'sort_order',
        ]);
    }
}
