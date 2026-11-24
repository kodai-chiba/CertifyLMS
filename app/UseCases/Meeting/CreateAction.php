<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Models\Enrollment;

/**
 * 面談予約画面に必要な受講登録情報を取得する。
 *
 * 所有者・受講状態の認可は Controller 側で行う。
 */
final class CreateAction
{
    public function __invoke(Enrollment $enrollment): Enrollment
    {
        $enrollment->loadMissing('certification');

        return $enrollment;
    }
}
