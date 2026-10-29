<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('meeting_reminder_deliveries', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('meeting_id')
                ->constrained('meetings')
                ->cascadeOnDelete();

            $table->foreignUlid('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // eve: 前日 / one_hour_before: 1時間前
            $table->string('window', 30);

            // アプリ内通知とメールの配信状況を個別に管理する
            $table->timestamp('database_sent_at')->nullable();
            $table->timestamp('mail_sent_at')->nullable();

            $table->timestamps();

            // 同じ面談・通知先・タイミングの重複登録を禁止
            $table->unique(
                ['meeting_id', 'user_id', 'window'],
                'meeting_reminder_deliveries_unique'
            );

            // 配信履歴の検索用
            $table->index(['window', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meeting_reminder_deliveries');
    }
};
