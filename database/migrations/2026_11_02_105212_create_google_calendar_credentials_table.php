<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_calendar_credentials', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('user_id')
                ->unique()
                ->constrained('users')
                ->cascadeOnDelete();

            // OAuthトークンはモデルの暗号化キャストで暗号化して保存する
            $table->text('access_token');
            $table->text('refresh_token')->nullable();

            // アクセストークンの有効期限
            $table->timestamp('expires_at')->nullable();

            // Google Calendarの連携先
            $table->string('calendar_id')->default('primary');

            // Googleアカウントとの連携日時
            $table->timestamp('connected_at');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_calendar_credentials');
    }
};
