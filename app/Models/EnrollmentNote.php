<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 受講登録に紐づくコーチメモを表すModel。
 *
 * 1つの受講登録に複数のメモを登録できる。
 * 作成者は担当コーチまたは管理者。
 *
 * 閲覧・作成・編集・削除の権限はEnrollmentNotePolicyで制御する。
 */
class EnrollmentNote extends Model
{
    use HasUlids;

    protected $fillable = [
        'enrollment_id',
        'author_id',
        'body',
    ];

    /**
     * メモが属する受講登録。
     *
     * @return BelongsTo<Enrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /**
     * メモの作成者（CoachまたはAdmin）。
     *
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
