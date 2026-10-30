<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\QaThreadStatus;
use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * QaThread のリレーション、キャスト、一覧取得用 scope を検証する Unit テスト。
 */
class QaThreadTest extends TestCase
{
    use RefreshDatabase;

    public function test_thread_has_expected_relationships_and_casts(): void
    {
        $certification = Certification::factory()->published()->create();
        $student = User::factory()->student()->create();

        $thread = QaThread::factory()
            ->for($certification)
            ->for($student)
            ->resolved()
            ->create();

        $reply = QaReply::factory()
            ->for($thread, 'thread')
            ->create();

        $this->assertTrue($thread->certification->is($certification));
        $this->assertTrue($thread->user->is($student));
        $this->assertTrue($thread->replies->contains($reply));
        $this->assertSame(QaThreadStatus::Resolved, $thread->status);
        $this->assertNotNull($thread->resolved_at);
    }

    public function test_for_user_returns_all_published_threads_for_student(): void
    {
        $student = User::factory()->student()->create();

        $publishedCertification = Certification::factory()->published()->create();
        $draftCertification = Certification::factory()->draft()->create();

        $publishedThread = QaThread::factory()
            ->for($publishedCertification)
            ->create();

        QaThread::factory()
            ->for($draftCertification)
            ->create();

        $results = QaThread::query()
            ->forUser($student)
            ->get();

        $this->assertCount(1, $results);
        $this->assertTrue($results->first()->is($publishedThread));
    }

    public function test_for_user_returns_only_assigned_published_threads_for_coach(): void
    {
        $coach = User::factory()->coach()->create();

        $assignedCertification = Certification::factory()->published()->create();
        $unassignedCertification = Certification::factory()->published()->create();
        $assignedDraftCertification = Certification::factory()->draft()->create();

        CertificationCoachAssignment::factory()->create([
            'certification_id' => $assignedCertification->id,
            'user_id' => $coach->id,
        ]);

        CertificationCoachAssignment::factory()->create([
            'certification_id' => $assignedDraftCertification->id,
            'user_id' => $coach->id,
        ]);

        $assignedThread = QaThread::factory()
            ->for($assignedCertification)
            ->create();

        QaThread::factory()
            ->for($unassignedCertification)
            ->create();

        QaThread::factory()
            ->for($assignedDraftCertification)
            ->create();

        $results = QaThread::query()
            ->forUser($coach)
            ->get();

        $this->assertCount(1, $results);
        $this->assertTrue($results->first()->is($assignedThread));
    }

    public function test_for_user_returns_all_threads_for_admin(): void
    {
        $admin = User::factory()->admin()->create();

        $publishedThread = QaThread::factory()
            ->for(Certification::factory()->published())
            ->create();

        $draftThread = QaThread::factory()
            ->for(Certification::factory()->draft())
            ->create();

        $results = QaThread::query()
            ->forUser($admin)
            ->get();

        $this->assertCount(2, $results);
        $this->assertTrue($results->contains($publishedThread));
        $this->assertTrue($results->contains($draftThread));
    }

    public function test_certification_scope_filters_by_certification(): void
    {
        $targetCertification = Certification::factory()->published()->create();
        $otherCertification = Certification::factory()->published()->create();

        $targetThread = QaThread::factory()
            ->for($targetCertification)
            ->create();

        QaThread::factory()
            ->for($otherCertification)
            ->create();

        $results = QaThread::query()
            ->certification($targetCertification->id)
            ->get();

        $this->assertCount(1, $results);
        $this->assertTrue($results->first()->is($targetThread));
    }

    public function test_status_scope_filters_by_status(): void
    {
        $unresolvedThread = QaThread::factory()->create();

        QaThread::factory()
            ->resolved()
            ->create();

        $results = QaThread::query()
            ->status(QaThreadStatus::Unresolved)
            ->get();

        $this->assertCount(1, $results);
        $this->assertTrue($results->first()->is($unresolvedThread));
    }

    public function test_keyword_scope_matches_title_body_and_reply_body(): void
    {
        $titleMatchedThread = QaThread::factory()->create([
            'title' => 'Laravelについての質問',
            'body' => '本文には対象となる単語がありません。',
        ]);

        $bodyMatchedThread = QaThread::factory()->create([
            'title' => 'ルーティングについての質問',
            'body' => 'Laravelのルーティングについて教えてください。',
        ]);

        $replyMatchedThread = QaThread::factory()->create([
            'title' => 'フレームワークについての質問',
            'body' => '使用方法について教えてください。',
        ]);

        QaReply::factory()
            ->for($replyMatchedThread, 'thread')
            ->create([
                'body' => 'Laravelを使う方法があります。',
            ]);

        $unmatchedThread = QaThread::factory()->create([
            'title' => '別の質問',
            'body' => 'PHPの基本について教えてください。',
        ]);

        QaReply::factory()
            ->for($unmatchedThread, 'thread')
            ->create([
                'body' => 'PHPの基本構文について説明します。',
            ]);

        $results = QaThread::query()
            ->keyword('Laravel')
            ->get();

        $this->assertCount(3, $results);
        $this->assertTrue($results->contains($titleMatchedThread));
        $this->assertTrue($results->contains($bodyMatchedThread));
        $this->assertTrue($results->contains($replyMatchedThread));
        $this->assertFalse($results->contains($unmatchedThread));
    }

    public function test_optional_filter_scopes_do_not_filter_when_value_is_empty(): void
    {
        QaThread::factory()->count(2)->create();

        $results = QaThread::query()
            ->certification(null)
            ->status(null)
            ->keyword(null)
            ->get();

        $this->assertCount(2, $results);
    }
}
