<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CertificationStatus;
use App\Enums\QaThreadStatus;
use App\Enums\UserRole;
use Database\Factories\QaThreadFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QaThread extends Model
{
    /** @use HasFactory<QaThreadFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'certification_id',
        'user_id',
        'title',
        'body',
        'status',
        'resolved_at',
    ];

    protected $casts = [
        'status' => QaThreadStatus::class,
        'resolved_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Certification, $this>
     */
    public function certification(): BelongsTo
    {
        return $this->belongsTo(Certification::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<QaReply, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(QaReply::class);
    }

    /**
     * 操作者ロールに応じて一覧表示する質問を絞り込む。
     * student は公開資格の全質問、coach は公開中の担当資格のみ、admin は全質問を閲覧可能。
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return match ($user->role) {
            UserRole::Student => $query->whereHas(
                'certification',
                fn (Builder $q) => $q->where('status', CertificationStatus::Published->value),
            ),
            UserRole::Coach => $query->whereHas(
                'certification',
                fn (Builder $q) => $q
                    ->where('status', CertificationStatus::Published->value)
                    ->assignedTo($user),
            ),
            UserRole::Admin => $query,
            default => $query->whereRaw('1 = 0'),
        };
    }

    public function scopeCertification(Builder $query, ?string $certificationId): Builder
    {
        if ($certificationId === null || $certificationId === '') {
            return $query;
        }

        return $query->where('certification_id', $certificationId);
    }

    public function scopeStatus(Builder $query, ?QaThreadStatus $status): Builder
    {
        if ($status === null) {
            return $query;
        }

        return $query->where('status', $status->value);
    }

    public function scopeKeyword(Builder $query, ?string $keyword): Builder
    {
        if ($keyword === null || $keyword === '') {
            return $query;
        }

        return $query->where('body', 'LIKE', '%'.$keyword.'%');
    }
}
