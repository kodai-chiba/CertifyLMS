<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Fortify\UpdateUserPassword;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * ログインユーザー自身のパスワード変更を管理する Controller。
 *
 * Student / Coach / Admin 共通で利用し、修了済みStudentもアクセス可能。
 * 現在のパスワード検証と新しいパスワードの検証は
 * 既存の Fortify UpdateUserPassword に委譲する。
 */
class PasswordController extends Controller
{
    public function update(
        Request $request,
        UpdateUserPassword $action,
    ): RedirectResponse {
        try {
            $action->update($request->user(), $request->all());
        } catch (ValidationException $e) {
            return redirect()
                ->route('settings.profile.show', ['tab' => 'password'])
                ->withErrors($e->validator, 'updatePassword')
                ->withInput($request->except([
                    'current_password',
                    'password',
                    'password_confirmation',
                ]));
        }

        return redirect()
            ->route('settings.profile.show', ['tab' => 'password'])
            ->with('success', 'パスワードを変更しました。');
    }
}
