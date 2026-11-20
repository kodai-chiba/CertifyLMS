<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Enums\UserStatus;
use App\Models\Meeting;
use App\Models\User;
use App\Policies\MeetingPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MeetingPolicy の判定を検証する Unit テスト。
 * view は管理者 / 担当コーチ / 受講中の当事者である受講生のみ。
 * create は受講中の受講生のみ。
 * cancel は予約状態 + 当事者であり、受講生は受講中の場合のみ。
 * upsertMemo は担当 coach のみ。
 */
class MeetingPolicyTest extends TestCase
{
    use RefreshDatabase;

    private const PLAN_EXPIRED_MESSAGE = 'プラン期間が満了しました。プラン機能はご利用いただけません。プロフィール / 修了証は引き続きアクセス可能です。';

    public function test_view_only_for_admin_or_party(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->inProgress()->create();
        $otherCoach = User::factory()->coach()->create();

        $meeting = Meeting::factory()
            ->forCoach($coach)
            ->forStudent($student)
            ->reserved()
            ->create();

        $policy = new MeetingPolicy;

        $this->assertTrue($policy->view($admin, $meeting)->allowed());
        $this->assertTrue($policy->view($coach, $meeting)->allowed());
        $this->assertTrue($policy->view($student, $meeting)->allowed());

        $response = $policy->view($otherCoach, $meeting);

        $this->assertTrue($response->denied(), '他コーチは view 不可');
        $this->assertNull($response->message(), '担当外コーチには専用メッセージを返さない');
    }

    public function test_non_in_progress_students_cannot_view_own_reserved_meetings(): void
    {
        $policy = new MeetingPolicy;

        foreach ([
            UserStatus::Invited,
            UserStatus::Graduated,
            UserStatus::Withdrawn,
        ] as $status) {
            $coach = User::factory()->coach()->create();

            $student = User::factory()->student()->create([
                'status' => $status,
            ]);

            $meeting = Meeting::factory()
                ->forCoach($coach)
                ->forStudent($student)
                ->reserved()
                ->create();

            $response = $policy->view($student, $meeting);

            $this->assertTrue($response->denied());
            $this->assertSame(self::PLAN_EXPIRED_MESSAGE, $response->message());
        }
    }

    public function test_non_in_progress_students_cannot_view_own_completed_meetings(): void
    {
        $policy = new MeetingPolicy;

        foreach ([
            UserStatus::Invited,
            UserStatus::Graduated,
            UserStatus::Withdrawn,
        ] as $status) {
            $coach = User::factory()->coach()->create();

            $student = User::factory()->student()->create([
                'status' => $status,
            ]);

            $meeting = Meeting::factory()
                ->forCoach($coach)
                ->forStudent($student)
                ->completed()
                ->create();

            $response = $policy->view($student, $meeting);

            $this->assertTrue($response->denied());
            $this->assertSame(self::PLAN_EXPIRED_MESSAGE, $response->message());
        }
    }

    public function test_create_allowed_only_for_student(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->inProgress()->create();
        $policy = new MeetingPolicy;

        $this->assertTrue($policy->create($student));
        $this->assertFalse($policy->create($coach));
        $this->assertFalse($policy->create($admin));
    }

    public function test_non_in_progress_students_cannot_create_meetings(): void
    {
        $policy = new MeetingPolicy;

        foreach ([
            UserStatus::Invited,
            UserStatus::Graduated,
            UserStatus::Withdrawn,
        ] as $status) {
            $student = User::factory()->student()->create([
                'status' => $status,
            ]);

            $this->assertFalse($policy->create($student));
        }
    }

    public function test_cancel_only_for_reserved_and_party(): void
    {
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();

        $reserved = Meeting::factory()
            ->forCoach($coach)
            ->forStudent($student)
            ->reserved()
            ->create();

        $canceled = Meeting::factory()
            ->forCoach($coach)
            ->forStudent($student)
            ->canceled()
            ->create();

        $policy = new MeetingPolicy;

        $this->assertTrue($policy->cancel($student, $reserved)->allowed());
        $this->assertTrue($policy->cancel($coach, $reserved)->allowed());

        $otherStudentResponse = $policy->cancel($otherStudent, $reserved);

        $this->assertTrue($otherStudentResponse->denied(), '他人の予約はキャンセル不可');
        $this->assertNull(
            $otherStudentResponse->message(),
            '第三者には専用メッセージを返さない'
        );

        $canceledResponse = $policy->cancel($student, $canceled);

        $this->assertTrue($canceledResponse->denied(), 'すでに canceled は再キャンセル不可');
        $this->assertNull(
            $canceledResponse->message(),
            'キャンセル済み面談には専用メッセージを返さない'
        );
    }

    public function test_non_in_progress_students_cannot_cancel_own_meetings(): void
    {
        $policy = new MeetingPolicy;

        foreach ([
            UserStatus::Invited,
            UserStatus::Graduated,
            UserStatus::Withdrawn,
        ] as $status) {
            $coach = User::factory()->coach()->create();

            $student = User::factory()->student()->create([
                'status' => $status,
            ]);

            $meeting = Meeting::factory()
                ->forCoach($coach)
                ->forStudent($student)
                ->reserved()
                ->create();

            $response = $policy->cancel($student, $meeting);

            $this->assertTrue($response->denied());
            $this->assertSame(self::PLAN_EXPIRED_MESSAGE, $response->message());
        }
    }

    public function test_upsert_memo_only_for_owner_coach(): void
    {
        $coach = User::factory()->coach()->create();
        $otherCoach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();

        $reserved = Meeting::factory()
            ->forCoach($coach)
            ->forStudent($student)
            ->reserved()
            ->create();

        $policy = new MeetingPolicy;

        $this->assertTrue($policy->upsertMemo($coach, $reserved), '担当 coach はメモ upsert 可');
        $this->assertFalse($policy->upsertMemo($otherCoach, $reserved), '他コーチは不可');
        $this->assertFalse($policy->upsertMemo($student, $reserved), '受講生は不可');
    }
}
