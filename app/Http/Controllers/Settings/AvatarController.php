<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreAvatarRequest;
use App\UseCases\Settings\DestroyAvatarAction;
use App\UseCases\Settings\StoreAvatarAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * ログインユーザー自身のアバター画像を管理する Controller。
 *
 * Student / Coach / Admin 共通で利用し、修了済みStudentもアクセス可能。
 * 登録・削除対象は常に認証済みユーザー本人とする。
 */
class AvatarController extends Controller
{
    public function store(
        StoreAvatarRequest $request,
        StoreAvatarAction $action,
    ): RedirectResponse {
        ($action)($request->user(), $request->file('avatar'));

        return redirect()
            ->route('settings.profile.show')
            ->with('success', 'アバター画像を更新しました。');
    }

    public function destroy(
        Request $request,
        DestroyAvatarAction $action,
    ): RedirectResponse {
        ($action)($request->user());

        return redirect()
            ->route('settings.profile.show')
            ->with('success', 'アバター画像を削除しました。');
    }
}
