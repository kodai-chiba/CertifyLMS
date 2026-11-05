<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Paymentテーブルが存在しない環境ではFK追加をスキップする。
        if (! Schema::hasTable('payments')) {
            return;
        }

        Schema::table('meeting_quota_transactions', function (Blueprint $table) {
            $table->foreign('related_payment_id')
                ->references('id')
                ->on('payments')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('payments')) {
            return;
        }

        Schema::table('meeting_quota_transactions', function (Blueprint $table) {
            $table->dropForeign(['related_payment_id']);
        });
    }
};
