<?php

declare(strict_types=1);

namespace App\UseCases\Settings;

use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * ログインユーザー自身のアバター画像を削除するユースケース。
 *
 * users.avatar_url を null に戻し、
 * public disk に保存されたアバター画像を削除する。
 *
 * DB更新が成功した後にファイルを削除する。
 */
final class DestroyAvatarAction
{
    public function __invoke(User $user): void
    {
        $oldUrl = $user->avatar_url;

        if ($oldUrl === null) {
            return;
        }

        $user->update([
            'avatar_url' => null,
        ]);

        if (str_starts_with($oldUrl, '/storage/avatars/')) {
            $oldPath = substr($oldUrl, strlen('/storage/'));

            Storage::disk('public')->delete($oldPath);
        }
    }
}
