<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ログインユーザー自身のアバター画像登録リクエスト。
 *
 * PNG / JPEG / WebP 形式、2MB以内の画像のみ許可する。
 * 画像のリサイズ・圧縮は行わない。
 */
class StoreAvatarRequest extends FormRequest
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
            'avatar' => [
                'required',
                'image',
                'mimes:png,jpg,jpeg,webp',
                'max:2048',
            ],
        ];
    }
}
