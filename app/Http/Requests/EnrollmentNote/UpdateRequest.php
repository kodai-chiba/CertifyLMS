<?php

declare(strict_types=1);

namespace App\Http\Requests\EnrollmentNote;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(
            'update',
            $this->route('note')
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
