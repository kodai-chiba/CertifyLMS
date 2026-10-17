<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaBoard;

use App\Models\Certification;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 質問掲示板一覧 (`qa-board.index`) の N+1 非回帰を検証する Feature テスト。
 * 資格 / 投稿者 / 回答数を一括取得することで、質問の件数を増やしても
 * 発行クエリ数がほぼ増えないことを担保する。
 */
class QaBoardIndexQueryCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_query_count_does_not_grow_with_thread_count(): void
    {
        // Arrange: 受講中の受講生 + 公開資格 + 質問 2 件 (基準)
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        $this->createThreads($certification, 2);

        // Act: 基準のクエリ数を計測 → 質問を 10 件追加して再計測 (1 ページに収まる件数)
        $baseline = $this->countQueriesFor(
            fn () => $this->actingAs($student)->get(route('qa-board.index'))
        );

        $this->createThreads($certification, 10);

        $scaled = $this->countQueriesFor(
            fn () => $this->actingAs($student)->get(route('qa-board.index'))
        );

        // Assert: 質問が増えても発行クエリ数はほぼ一定 (N+1 なら件数分増える)
        $this->assertLessThanOrEqual(
            $baseline + 3,
            $scaled,
            "質問掲示板一覧で N+1 が再発している (基準 {$baseline} → 増加後 {$scaled})。資格 / 投稿者を Eager Loading、回答数を withCount で一括取得しているか確認",
        );
    }

    private function createThreads(Certification $certification, int $count): void
    {
        QaThread::factory()
            ->count($count)
            ->for($certification)
            ->create()
            ->each(function (QaThread $thread): void {
                QaReply::factory()
                    ->count(2)
                    ->for($thread, 'thread')
                    ->create();
            });
    }

    private function countQueriesFor(\Closure $closure): int
    {
        $count = 0;

        DB::listen(function () use (&$count): void {
            $count++;
        });

        $closure();

        return $count;
    }
}
