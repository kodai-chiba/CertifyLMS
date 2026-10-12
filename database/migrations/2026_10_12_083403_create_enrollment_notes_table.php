<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 受講登録に紐づくコーチメモを管理するテーブル。
 *
 * 1 受講登録に複数のメモを登録可能。
 * 作成者は担当コーチまたは管理者。
 * 閲覧・編集・削除の権限は EnrollmentNotePolicy で制御する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollment_notes', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('enrollment_id')
                ->constrained('enrollments')
                ->cascadeOnDelete();

            $table->foreignUlid('author_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->text('body');

            $table->timestamps();

            $table->index(['enrollment_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_notes');
    }
};
