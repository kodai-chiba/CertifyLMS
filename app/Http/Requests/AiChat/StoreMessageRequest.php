<?php

declare(strict_types=1);

namespace App\Http\Requests\AiChat;

use App\Models\AiChatConversation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreMessageRequest extends FormRequest
{
    /**
     * AIチャットへの質問送信権限を確認する。
     */
    public function authorize(): bool
    {
        $conversation = $this->route('conversation');

        return $conversation instanceof AiChatConversation
            && Gate::allows('sendMessage', $conversation);
    }

    /**
     * 質問送信時の入力値を検証する。
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'content' => [
                'required',
                'string',
                'max:4000',
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
            'content.required' => '質問内容を入力してください。',
            'content.string' => '質問内容は文字列で入力してください。',
            'content.max' => '質問内容は4,000文字以内で入力してください。',
        ];
    }
}
