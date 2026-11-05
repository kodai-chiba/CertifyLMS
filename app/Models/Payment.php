<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'id',
        'user_id',
        'meeting_pack_id',
        'amount',
        'quantity',
        'stripe_checkout_session_id',
        'stripe_payment_intent_id',
        'status',
        'paid_at',
    ];

    protected $casts = [
        'amount' => 'integer',
        'quantity' => 'integer',
        'status' => PaymentStatus::class,
        'paid_at' => 'datetime',
    ];

    /**
     * 決済を行った受講生。
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 購入された面談パック。
     *
     * @return BelongsTo<MeetingPack, $this>
     */
    public function meetingPack(): BelongsTo
    {
        return $this->belongsTo(MeetingPack::class);
    }

    /**
     * 決済に紐づく面談回数取引履歴。
     *
     * @return HasMany<MeetingQuotaTransaction, $this>
     */
    public function meetingQuotaTransactions(): HasMany
    {
        return $this->hasMany(
            MeetingQuotaTransaction::class,
            'related_payment_id'
        );
    }
}
