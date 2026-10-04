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
 * 質問掲示板詳細 (`qa-board.show`) の N+1 非回帰を検証する Feature テスト。
 * 質問 / 資格 / 投稿者 / 回答投稿者を一括取得することで、回答件数を増やしても
 * 発行クエリ数がほぼ増えないことを担保する。
 */
class QaBoardShowQueryCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_query_count_does_not_grow_with_reply_count(): void
    {
        // Arrange: 受講中の受講生 + 公開資格 + 質問 + 回答 2 件 (基準)
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()
            ->for($certification)
            ->create();

        $this->createReplies($thread, 2);

        // Act: 基準のクエリ数を計測 → 回答を 10 件追加して再計測
        $baseline = $this->countQueriesFor(
            fn () => $this->actingAs($student)->get(route('qa-board.show', $thread))
        );

        $this->createReplies($thread, 10);

        $scaled = $this->countQueriesFor(
            fn () => $this->actingAs($student)->get(route('qa-board.show', $thread))
        );

        // Assert: 回答が増えても発行クエリ数はほぼ一定 (N+1 なら回答件数分増える)
        $this->assertLessThanOrEqual(
            $baseline + 3,
            $scaled,
            "質問掲示板詳細で N+1 が再発している (基準 {$baseline} → 増加後 {$scaled})。回答投稿者を Eager Loading で一括取得しているか確認",
        );
    }

    private function createReplies(QaThread $thread, int $count): void
    {
        QaReply::factory()
            ->count($count)
            ->for($thread, 'thread')
            ->create();
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
