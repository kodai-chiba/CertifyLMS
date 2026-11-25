<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MockExam;

use App\Models\Certification;
use App\Models\MockExam;
use App\Models\MockExamQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 模試マスタ一覧 (`admin.mock-exams.index`) の N+1 非回帰を検証する Feature テスト。
 * 所属資格 / 作成者 / 更新者 / 問題数を一括取得することで、模試の件数を増やしても
 * 発行クエリ数がほぼ増えない (各行の関連参照で遅延ロードが発火しない) ことを担保する。
 */
class MockExamIndexQueryCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_query_count_does_not_grow_with_mock_exam_count(): void
    {
        // Arrange: 管理者 + 共通資格に紐づく模試 2 件 (基準)
        $admin = User::factory()->admin()->create();
        $certification = Certification::factory()->published()->create();
        $this->createMockExams($certification, 2);

        // Act: 基準のクエリ数を計測 → 模試を 10 件追加して再計測 (1 ページに収まる件数)
        $baseline = $this->countQueriesFor(
            fn () => $this->actingAs($admin)->get(route('admin.mock-exams.index'))->assertOk()
        );

        $this->createMockExams($certification, 10);

        $scaled = $this->countQueriesFor(
            fn () => $this->actingAs($admin)->get(route('admin.mock-exams.index'))->assertOk()
        );

        // Assert: 模試が増えても発行クエリ数はほぼ一定 (N+1 なら件数分増える)
        $this->assertLessThanOrEqual(
            $baseline + 3,
            $scaled,
            "模試マスタ一覧で N+1 が再発している (基準 {$baseline} → 増加後 {$scaled})。所属資格 / 作成者 / 更新者 / 問題数を Eager Loading + withCount で一括取得しているか確認",
        );
    }

    public function test_coach_index_query_count_does_not_grow_with_mock_exam_count(): void
    {
        // Arrange: コーチ + 担当資格に紐づく模試 2 件 (基準)
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $certification = Certification::factory()->published()->create();

        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);

        $this->createMockExams($certification, 2);

        // Act: 基準のクエリ数を計測 → 模試を 10 件追加して再計測
        $baseline = $this->countQueriesFor(
            fn () => $this->actingAs($coach)->get(route('admin.mock-exams.index'))->assertOk()
        );

        $this->createMockExams($certification, 10);

        $scaled = $this->countQueriesFor(
            fn () => $this->actingAs($coach)->get(route('admin.mock-exams.index'))->assertOk()
        );

        // Assert: コーチでも模試の件数増加に伴う N+1 が発生しない
        $this->assertLessThanOrEqual(
            $baseline + 3,
            $scaled,
            "コーチの模試マスタ一覧で N+1 が再発している (基準 {$baseline} → 増加後 {$scaled})。所属資格 / 作成者 / 更新者 / 問題数を Eager Loading + withCount で一括取得しているか確認",
        );
    }

    private function createMockExams(Certification $certification, int $count): void
    {
        $mockExams = MockExam::factory()
            ->count($count)
            ->forCertification($certification)
            ->create();

        foreach ($mockExams as $mockExam) {
            MockExamQuestion::factory()
                ->count(3)
                ->forMockExam($mockExam)
                ->create();
        }
    }

    private function countQueriesFor(\Closure $closure): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $closure();

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }
}
