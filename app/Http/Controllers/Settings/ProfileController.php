<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateProfileRequest;
use App\UseCases\Settings\UpdateProfileAction;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * ログインユーザー自身のプロフィール設定を管理する Controller。
 *
 * Student / Coach / Admin 共通で利用し、修了済みStudentもアクセス可能。
 * 表示・更新対象は常に認証済みユーザー本人とする。
 */
class ProfileController extends Controller
{
    public function show(Request $request): View
    {
        return view('settings.profile', [
            'user' => $request->user(),
        ]);
    }

    public function update(
        UpdateProfileRequest $request,
        UpdateProfileAction $action,
    ): RedirectResponse {
        ($action)($request->user(), $request->validated());

        return redirect()
            ->route('settings.profile.show')
            ->with('success', 'プロフィールを更新しました。');
    }
}
