<?php

declare(strict_types=1);

namespace App\Http\Requests\AiChat;

use App\Models\AiChatConversation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateConversationRequest extends FormRequest
{
    /**
     * 会話タイトルの更新権限を確認する。
     */
    public function authorize(): bool
    {
        $conversation = $this->route('conversation');

        return $conversation instanceof AiChatConversation
            && Gate::allows('update', $conversation);
    }

    /**
     * 会話タイトル更新時の入力値を検証する。
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => [
                'required',
                'string',
                'max:100',
            ],
        ];
    }

    /**
     * バリデーションエラーメッセージ。
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => '会話タイトルを入力してください。',
            'title.string' => '会話タイトルは文字列で入力してください。',
            'title.max' => '会話タイトルは100文字以内で入力してください。',
        ];
    }
}
