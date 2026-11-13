<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gemini AIチャットのメッセージを管理するテーブル。
 *
 * 受講生の質問とGeminiの回答を保存する。
 * Gemini APIへの通信に失敗した場合も質問履歴を保持する。
 *
 * AI回答には処理状態・使用モデル・トークン数・応答時間・
 * エラー情報を記録できるようにする。
 *
 * 会話削除時は関連メッセージも削除する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_chat_messages', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('conversation_id')
                ->constrained('ai_chat_conversations')
                ->cascadeOnDelete();

            $table->string('role', 20);

            $table->text('content')->nullable();

            $table->string('status', 20)
                ->default('completed');

            $table->string('model', 100)->nullable();

            $table->unsignedInteger('input_tokens')->nullable();

            $table->unsignedInteger('output_tokens')->nullable();

            $table->unsignedInteger('response_time_ms')->nullable();

            $table->text('error_detail')->nullable();

            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
            $table->index(['role', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chat_messages');
    }
};
