<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gemini AIチャットの会話を管理するテーブル。
 *
 * 受講生は複数の会話を作成できる。
 * Enrollment / Section は任意の学習コンテキストとして保持する。
 *
 * ユーザー削除時は会話も削除する。
 * Enrollment / Section 削除時は関連付けのみ解除し、会話履歴を保持する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_chat_conversations', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignUlid('enrollment_id')
                ->nullable()
                ->constrained('enrollments')
                ->nullOnDelete();

            $table->foreignUlid('section_id')
                ->nullable()
                ->constrained('sections')
                ->nullOnDelete();

            $table->string('title', 100)->default('新しい会話');

            $table->timestamp('last_message_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'last_message_at']);
            $table->index('enrollment_id');
            $table->index('section_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chat_conversations');
    }
};
