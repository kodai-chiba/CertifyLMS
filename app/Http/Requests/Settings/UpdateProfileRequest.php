<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ログインユーザー自身のプロフィール更新リクエスト。
 *
 * 全ロール共通で氏名・自己紹介を更新可能。
 * 固定面談URLはCoachのみ更新可能とし、他ロールからの入力は拒否する。
 * メールアドレスは既存Bladeのreadonly入力欄から送信されるため、
 * 受け付けるが更新対象には含めない。
 * ロール・アカウント状態の変更は許可しない。
 */
class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50'],
            'bio' => ['nullable', 'string', 'max:1000'],
            'meeting_url' => [
                Rule::prohibitedIf($this->user()?->role !== UserRole::Coach),
                'nullable',
                'url',
                'max:500',
            ],
            'email' => ['nullable', 'string', 'email'],
            'role' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }
}
