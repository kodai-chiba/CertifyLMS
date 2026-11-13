<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gemini AIチャットの日別利用回数を管理するテーブル。
 *
 * 受講生ごとに1日1レコードを保持する。
 * 会話履歴を削除しても質問回数は減らさない。
 *
 * 質問回数の上限判定・更新時には、
 * アプリケーション側でトランザクションと排他制御を行う。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_chat_daily_usages', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->date('usage_date');

            $table->unsignedInteger('question_count')->default(0);

            $table->timestamps();

            $table->unique(
                ['user_id', 'usage_date'],
                'ai_chat_daily_usages_user_date_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chat_daily_usages');
    }
};
