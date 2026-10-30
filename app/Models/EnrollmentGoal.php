<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\EnrollmentGoalFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Enrollment 単位の個人学習目標を表す Model。
 *
 * 1 Enrollment に複数の目標を登録できる。
 * 達成状態は achieved_at の有無で管理し、目標自体は物理削除する。
 *
 * 関連: Enrollment
 */
class EnrollmentGoal extends Model
{
    /** @use HasFactory<EnrollmentGoalFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'enrollment_id',
        'title',
        'description',
        'target_date',
        'achieved_at',
    ];

    protected $casts = [
        'target_date' => 'date',
        'achieved_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Enrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /**
     * 個人学習目標が達成済みか判定する。
     *
     * achieved_at に日時が設定されていれば達成済み、
     * null であれば未達成として扱う。
     */
    public function isAchieved(): bool
    {
        return $this->achieved_at !== null;
    }

    /**
     * 表示順。
     *
     * 未達成を先頭にし、その中で目標期日が近い順に並べる。
     * 期日未設定は末尾、同条件では新しく作成した目標を先に表示する。
     */
    public function scopeDisplayOrder(Builder $query): Builder
    {
        return $query
            ->orderByRaw('CASE WHEN achieved_at IS NULL THEN 0 ELSE 1 END')
            ->orderByRaw('CASE WHEN target_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('target_date')
            ->orderByDesc('created_at');
    }
}
