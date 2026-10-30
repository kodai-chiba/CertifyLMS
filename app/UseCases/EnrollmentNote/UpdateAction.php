<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentNote;

use App\Models\EnrollmentNote;

/**
 * コーチメモの本文を更新する UseCase。
 *
 * 編集権限の確認・入力検証は UpdateRequest で実施する。
 */
class UpdateAction
{
    /**
     * @param array<string, mixed> $validated
     */
    public function __invoke(
        EnrollmentNote $note,
        array $validated,
    ): EnrollmentNote {
        $note->update([
            'body' => $validated['body'],
        ]);

        return $note;
    }
}
