<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentNote;

use App\Models\EnrollmentNote;

/**
 * コーチメモを削除する UseCase。
 *
 * 削除権限の確認は Controller で実施する。
 * 現時点では物理削除を採用する。
 */
class DestroyAction
{
    public function __invoke(EnrollmentNote $note): void
    {
        $note->delete();
    }
}
