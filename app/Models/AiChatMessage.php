<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Gemini AIチャットのメッセージモデル。
 *
 * 受講生の質問とGeminiの回答を管理する。
 * AI回答の処理状態・トークン数・応答時間・エラー情報を保持する。
 */
class AiChatMessage extends Model
{
    use HasFactory;
    use HasUlids;

    /**
     * 一括代入を許可するカラム。
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'conversation_id',
        'role',
        'content',
        'status',
        'model',
        'input_tokens',
        'output_tokens',
        'response_time_ms',
        'error_detail',
    ];

    /**
     * 属性のキャスト。
     *
     * Laravel 10に対応したプロパティ形式。
     *
     * @var array<string, string>
     */
    protected $casts = [
        'role' => AiChatMessageRole::class,
        'status' => AiChatMessageStatus::class,
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'response_time_ms' => 'integer',
    ];

    /**
     * メッセージが所属する会話。
     *
     * @return BelongsTo<AiChatConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiChatConversation::class);
    }

    /**
     * モデルイベントを登録する。
     */
    protected static function booted(): void
    {
        static::created(function (AiChatMessage $message): void {
            $message->conversation()
                ->update([
                    'last_message_at' => $message->created_at,
                ]);
        });
    }
}
