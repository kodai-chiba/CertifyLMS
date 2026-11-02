<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingReminderDelivery extends Model
{
    use HasUlids;

    /**
     * 一括代入を許可するカラム。
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'meeting_id',
        'user_id',
        'window',
        'database_sent_at',
        'mail_sent_at',
    ];

    /**
     * 属性の型変換。
     *
     * @var array<string, string>
     */
    protected $casts = [
        'database_sent_at' => 'datetime',
        'mail_sent_at' => 'datetime',
    ];

    /**
     * 対象の面談。
     *
     * @return BelongsTo<Meeting, self>
     */
    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    /**
     * 通知先ユーザー。
     *
     * @return BelongsTo<User, self>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
