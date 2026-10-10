<?php

declare(strict_types=1);

namespace App\UseCases\Settings;

use App\Enums\UserRole;
use App\Models\User;

/**
 * ログインユーザー自身のプロフィール情報を更新するユースケース。
 *
 * 氏名・自己紹介は全ロール共通で更新可能。
 * 固定面談URLはCoachのみ更新可能。
 * メールアドレス・ロール・アカウント状態は更新対象に含めない。
 */
final class UpdateProfileAction
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function __invoke(User $user, array $attributes): void
    {
        $data = [
            'name' => $attributes['name'],
            'bio' => $attributes['bio'] ?? null,
        ];

        if ($user->role === UserRole::Coach) {
            $data['meeting_url'] = $attributes['meeting_url'] ?? null;
        }

        $user->update($data);
    }
}
