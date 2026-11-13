<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Gemini AIチャットの日別利用回数を管理するモデル。
 *
 * 受講生ごとに1日1レコードを保持する。
 * 会話履歴を削除しても質問回数は減らさない。
 * Gemini APIエラーとなった質問は利用回数に含めない。
 *
 * 1日50通の上限判定と排他制御はUseCase側で実装する。
 */
class AiChatDailyUsage extends Model
{
    use HasFactory;
    use HasUlids;

    /**
     * 一括代入を許可するカラム。
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'usage_date',
        'question_count',
    ];

    /**
     * 属性のキャスト。
     *
     * Laravel 10に対応した定義。
     *
     * @var array<string, string>
     */
    protected $casts = [
        'usage_date' => 'date',
        'question_count' => 'integer',
    ];

    /**
     * 利用回数の所有者。
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
