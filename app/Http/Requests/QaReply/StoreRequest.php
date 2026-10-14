<?php

declare(strict_types=1);

namespace App\Http\Requests\QaReply;

use App\Models\QaReply;
use App\Models\QaThread;
use Illuminate\Foundation\Http\FormRequest;

class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        $qaThread = $this->route('thread');

        return $qaThread instanceof QaThread
            && ($this->user()?->can('create', [QaReply::class, $qaThread]) ?? false);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'body' => '返信本文',
        ];
    }
}
