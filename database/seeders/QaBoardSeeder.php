<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CertificationStatus;
use App\Enums\QaThreadStatus;
use App\Models\Certification;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * 開発用 質問掲示板シーダー。
 *
 * 公開資格ごとに未解決 / 解決済みの質問を作成し、
 * 回答数・投稿日時にばらつきを持たせることで、
 * 一覧・絞り込み・回答数表示・解決状態を安定して確認できる状態を作る。
 *
 * 質問投稿者には固定受講生 student@certify-lms.test を使用し、
 * 回答者には固定受講生と各資格の担当コーチを使用する。
 *
 * 依存順序:
 * UserSeeder → CertificationSeeder → 本 Seeder
 */
final class QaBoardSeeder extends Seeder
{
    public function run(): void
    {
        $student = User::query()
            ->where('email', 'student@certify-lms.test')
            ->first();

        if ($student === null) {
            $this->command?->warn(
                'QaBoardSeeder: 固定受講生が存在しません。先に UserSeeder を実行してください。'
            );

            return;
        }

        $certifications = Certification::query()
            ->where('status', CertificationStatus::Published->value)
            ->with('coaches')
            ->orderBy('name')
            ->get();

        if ($certifications->isEmpty()) {
            $this->command?->warn(
                'QaBoardSeeder: 公開資格が存在しません。先に CertificationSeeder を実行してください。'
            );

            return;
        }

        foreach ($certifications->values() as $index => $certification) {
            $this->createUnresolvedThread(
                $certification,
                $student,
                $index
            );

            $this->createResolvedThread(
                $certification,
                $student,
                $index
            );
        }
    }

    private function createUnresolvedThread(
        Certification $certification,
        User $student,
        int $index
    ): void {
        $createdAt = Carbon::now()->subDays($index + 1)->subHours(2);

        $thread = QaThread::create([
            'certification_id' => $certification->id,
            'user_id' => $student->id,
            'title' => "{$certification->name}の学習方法について質問です",
            'body' => "現在、{$certification->name}を学習しています。"
                .'効率よく理解を深めるために、優先して取り組むべき分野やおすすめの学習順序があれば教えてください。',
            'status' => QaThreadStatus::Unresolved->value,
            'resolved_at' => null,
        ]);

        $thread->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();

        // 未解決スレッドは資格ごとに回答数を 0〜2 件へ分散させる。
        $replyCount = $index % 3;

        $this->createReplies(
            $thread,
            $student,
            $certification,
            $replyCount,
            $createdAt
        );
    }

    private function createResolvedThread(
        Certification $certification,
        User $student,
        int $index
    ): void {
        $createdAt = Carbon::now()->subDays($index + 6)->subHours(4);
        $resolvedAt = $createdAt->copy()->addHours(6);

        $thread = QaThread::create([
            'certification_id' => $certification->id,
            'user_id' => $student->id,
            'title' => "{$certification->name}の復習方法を教えてください",
            'body' => "一通り{$certification->name}の教材を学習しましたが、"
                .'復習するときにどのような順番で進めると知識が定着しやすいでしょうか。',
            'status' => QaThreadStatus::Resolved->value,
            'resolved_at' => $resolvedAt,
        ]);

        $thread->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $resolvedAt,
        ])->save();

        // 解決済みスレッドには 1〜3 件の回答を用意する。
        $replyCount = ($index % 3) + 1;

        $this->createReplies(
            $thread,
            $student,
            $certification,
            $replyCount,
            $createdAt
        );
    }

    private function createReplies(
        QaThread $thread,
        User $student,
        Certification $certification,
        int $replyCount,
        Carbon $threadCreatedAt
    ): void {
        if ($replyCount === 0) {
            return;
        }

        $coaches = $certification->coaches->values();

        for ($i = 0; $i < $replyCount; $i++) {
            $coach = $coaches->get($i % max($coaches->count(), 1));

            $author = $coach ?? $student;

            $body = $coach !== null
                ? "まずは{$certification->name}の頻出分野から復習するのがおすすめです。"
                    .'間違えた問題を中心に、理由まで説明できる状態を目指してみてください。'
                : '私も同じ資格を勉強しています。間違えた問題を記録して、数日後にもう一度解く方法が役立ちました。';

            $createdAt = $threadCreatedAt
                ->copy()
                ->addHours($i + 1);

            $reply = QaReply::create([
                'qa_thread_id' => $thread->id,
                'user_id' => $author->id,
                'body' => $body,
            ]);

            $reply->forceFill([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ])->save();
        }
    }
}
