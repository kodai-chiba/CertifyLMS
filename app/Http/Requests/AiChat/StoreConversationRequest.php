<?php

declare(strict_types=1);

namespace App\Http\Requests\AiChat;

use App\Enums\ContentStatus;
use App\Models\AiChatConversation;
use App\Models\Section;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreConversationRequest extends FormRequest
{
    /**
     * AIチャットの新規会話作成権限を確認する。
     */
    public function authorize(): bool
    {
        return Gate::allows('create', AiChatConversation::class);
    }

    /**
     * 新規会話作成時の入力値を検証する。
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'source' => [
                'sometimes',
                'string',
                Rule::in(['widget', 'full-screen']),
            ],
            'section_id' => [
                'nullable',
                'string',
                Rule::exists('sections', 'id'),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null || $value === '') {
                        return;
                    }

                    $section = Section::query()
                        ->with('chapter.part')
                        ->find($value);

                    if ($section === null) {
                        return;
                    }

                    $chapter = $section->chapter;
                    $part = $chapter?->part;

                    if ($section->status !== ContentStatus::Published
                        || $chapter === null
                        || $chapter->status !== ContentStatus::Published
                        || $part === null
                        || $part->status !== ContentStatus::Published
                        || ! Gate::allows('learning.section.view', $section)) {
                        $fail('指定された教材を利用できません。');
                    }
                },
            ],
            'message' => [
                'nullable',
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
            'source.string' => '会話の作成元が不正です。',
            'source.in' => '会話の作成元が不正です。',
            'section_id.string' => '教材の指定が不正です。',
            'section_id.exists' => '指定された教材が見つかりません。',
            'message.string' => '質問内容は文字列で入力してください。',
            'message.max' => '質問内容は4,000文字以内で入力してください。',
        ];
    }
}
