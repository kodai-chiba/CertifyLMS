<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            // キャンセル済み以外の面談だけ日時を保持する生成列
            $table->dateTime('active_scheduled_at')
                ->nullable()
                ->storedAs(
                    "CASE WHEN status = 'canceled' THEN NULL ELSE scheduled_at END"
                );

            // 同じコーチ・同じ有効日時の重複を禁止
            $table->unique(
                ['coach_id', 'active_scheduled_at'],
                'meetings_coach_active_schedule_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropUnique('meetings_coach_active_schedule_unique');
            $table->dropColumn('active_scheduled_at');
        });
    }
};
