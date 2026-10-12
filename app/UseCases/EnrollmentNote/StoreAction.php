<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentNote;

use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;

/**
 * 受講登録にコーチメモを追加する UseCase。
 *
 * 作成者はログイン中の担当コーチまたは管理者。
 * 認可・入力検証は StoreRequest で実施する。
 */
class StoreAction
{
    /**
     * @param array<string, mixed> $validated
     */
    public function __invoke(
        Enrollment $enrollment,
        User $author,
        array $validated,
    ): EnrollmentNote {
        return $enrollment->notes()->create([
            'author_id' => $author->id,
            'body' => $validated['body'],
        ]);
    }
}
