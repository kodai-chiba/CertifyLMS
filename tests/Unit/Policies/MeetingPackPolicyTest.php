<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\MeetingPack;
use App\Models\User;
use App\Policies\MeetingPackPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeetingPackPolicyTest extends TestCase
{
    use RefreshDatabase;

    private MeetingPackPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new MeetingPackPolicy;
    }

    public function test_admin_can_access_meeting_pack_management(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = MeetingPack::factory()->draft()->create();

        $this->assertTrue($this->policy->viewAny($admin));
        $this->assertTrue($this->policy->view($admin, $plan));
        $this->assertTrue($this->policy->create($admin));
        $this->assertTrue($this->policy->update($admin, $plan));
    }

    public function test_student_cannot_access_meeting_pack_management(): void
    {
        $student = User::factory()->student()->create();
        $plan = MeetingPack::factory()->draft()->create();

        $this->assertFalse($this->policy->viewAny($student));
        $this->assertFalse($this->policy->view($student, $plan));
        $this->assertFalse($this->policy->create($student));
        $this->assertFalse($this->policy->update($student, $plan));
        $this->assertFalse($this->policy->delete($student, $plan));
        $this->assertFalse($this->policy->publish($student, $plan));
    }

    public function test_coach_cannot_access_meeting_pack_management(): void
    {
        $coach = User::factory()->coach()->create();
        $plan = MeetingPack::factory()->draft()->create();

        $this->assertFalse($this->policy->viewAny($coach));
        $this->assertFalse($this->policy->view($coach, $plan));
        $this->assertFalse($this->policy->create($coach));
        $this->assertFalse($this->policy->update($coach, $plan));
        $this->assertFalse($this->policy->delete($coach, $plan));
        $this->assertFalse($this->policy->publish($coach, $plan));
    }

    public function test_admin_can_delete_draft_pack(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = MeetingPack::factory()->draft()->create();

        $this->assertTrue($this->policy->delete($admin, $plan));
    }

    public function test_admin_cannot_delete_published_pack(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = MeetingPack::factory()->published()->create();

        $this->assertFalse($this->policy->delete($admin, $plan));
    }

    public function test_admin_can_delete_archived_pack(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = MeetingPack::factory()->archived()->create();

        $this->assertTrue($this->policy->delete($admin, $plan));
    }

    public function test_only_draft_pack_can_be_published(): void
    {
        $admin = User::factory()->admin()->create();

        $draft = MeetingPack::factory()->draft()->create();
        $published = MeetingPack::factory()->published()->create();
        $archived = MeetingPack::factory()->archived()->create();

        $this->assertTrue($this->policy->publish($admin, $draft));
        $this->assertFalse($this->policy->publish($admin, $published));
        $this->assertFalse($this->policy->publish($admin, $archived));
    }

    public function test_only_published_pack_can_be_archived(): void
    {
        $admin = User::factory()->admin()->create();

        $draft = MeetingPack::factory()->draft()->create();
        $published = MeetingPack::factory()->published()->create();
        $archived = MeetingPack::factory()->archived()->create();

        $this->assertFalse($this->policy->archive($admin, $draft));
        $this->assertTrue($this->policy->archive($admin, $published));
        $this->assertFalse($this->policy->archive($admin, $archived));
    }

    public function test_only_archived_pack_can_be_unarchived(): void
    {
        $admin = User::factory()->admin()->create();

        $draft = MeetingPack::factory()->draft()->create();
        $published = MeetingPack::factory()->published()->create();
        $archived = MeetingPack::factory()->archived()->create();

        $this->assertFalse($this->policy->unarchive($admin, $draft));
        $this->assertFalse($this->policy->unarchive($admin, $published));
        $this->assertTrue($this->policy->unarchive($admin, $archived));
    }

    public function test_student_and_coach_cannot_change_pack_status(): void
    {
        $student = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();

        $draft = MeetingPack::factory()->draft()->create();
        $published = MeetingPack::factory()->published()->create();
        $archived = MeetingPack::factory()->archived()->create();

        $this->assertFalse($this->policy->publish($student, $draft));
        $this->assertFalse($this->policy->archive($student, $published));
        $this->assertFalse($this->policy->unarchive($student, $archived));

        $this->assertFalse($this->policy->publish($coach, $draft));
        $this->assertFalse($this->policy->archive($coach, $published));
        $this->assertFalse($this->policy->unarchive($coach, $archived));
    }
}
