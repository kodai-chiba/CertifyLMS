<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignUlid('meeting_pack_id')
                ->constrained('meeting_packs')
                ->restrictOnDelete();

            // 購入時点の情報を保持
            $table->unsignedInteger('amount');
            $table->unsignedSmallInteger('quantity');

            // Stripe決済情報
            $table->string('stripe_checkout_session_id', 255)
                ->nullable()
                ->unique();

            $table->string('stripe_payment_intent_id', 255)
                ->nullable()
                ->unique();

            // 決済状態
            $table->string('status', 20)->default('pending');

            $table->timestamp('paid_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
