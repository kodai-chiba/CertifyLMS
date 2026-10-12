<?php

declare(strict_types=1);

namespace App\Http\Requests\EnrollmentNote;

use App\Models\EnrollmentNote;
use Illuminate\Foundation\Http\FormRequest;

class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(
            'create',
            [EnrollmentNote::class, $this->route('enrollment')]
        );
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:2000', 'not_regex:/^\s*$/u'],
        ];
    }

    public function messages(): array
    {
        return [
            'body.required' => 'メモ本文を入力してください。',
            'body.string' => 'メモ本文は文字列で入力してください。',
            'body.max' => 'メモ本文は2000文字以内で入力してください。',
            'body.not_regex' => 'メモ本文には空白以外の文字を入力してください。',
        ];
    }
}
