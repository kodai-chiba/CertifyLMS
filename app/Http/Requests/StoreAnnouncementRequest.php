<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\AnnouncementTargetType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAnnouncementRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Admin;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => [
                'required',
                'string',
                'max:200',
            ],
            'body' => [
                'required',
                'string',
                'max:5000',
            ],
            'target_type' => [
                'required',
                Rule::enum(AnnouncementTargetType::class),
            ],
            'target_certification_id' => [
                'exclude_unless:target_type,certification',
                'required_if:target_type,certification',
                Rule::exists('certifications', 'id')
                    ->where('status', 'published'),
            ],
            'target_user_id' => [
                'exclude_unless:target_type,user',
                'required_if:target_type,user',
                Rule::exists('users', 'id')
                    ->where('role', UserRole::Student->value)
                    ->where('status', UserStatus::InProgress->value)
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * Get custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'タイトルを入力してください。',
            'title.max' => 'タイトルは200文字以内で入力してください。',
            'body.required' => '本文を入力してください。',
            'body.max' => '本文は5000文字以内で入力してください。',
            'target_type.required' => '配信対象を選択してください。',
            'target_type.Illuminate\Validation\Rules\Enum' => '正しい配信対象を選択してください。',
            'target_certification_id.required_if' => '配信対象の資格を選択してください。',
            'target_certification_id.exists' => '公開中の資格を選択してください。',
            'target_user_id.required_if' => '配信対象の受講生を選択してください。',
            'target_user_id.exists' => '選択された受講生は配信対象に指定できません。',
        ];
    }
}
