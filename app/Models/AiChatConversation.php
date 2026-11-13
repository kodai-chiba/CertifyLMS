<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Gemini AIチャットの会話モデル。
 *
 * 受講生ごとに複数の会話を保持する。
 * 受講登録・学習セクションは任意のコンテキストとして紐づける。
 */
class AiChatConversation extends Model
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
        'enrollment_id',
        'section_id',
        'title',
        'last_message_at',
    ];

    /**
     * 属性のキャスト。
     *
     * @var array<string, string>
     */
    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    /**
     * 会話の所有者。
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 会話に紐づく受講登録。
     *
     * @return BelongsTo<Enrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /**
     * 会話に紐づく学習セクション。
     *
     * @return BelongsTo<Section, $this>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /**
     * 会話に紐づくメッセージ。
     *
     * 古いメッセージから順番に取得する。
     *
     * @return HasMany<AiChatMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(AiChatMessage::class, 'conversation_id')
            ->orderBy('created_at')
            ->orderBy('id');
    }
}
