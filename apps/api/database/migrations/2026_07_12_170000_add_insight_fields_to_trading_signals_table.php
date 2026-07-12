<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trading_signals', function (Blueprint $table): void {
            $table->json('explanation')->nullable()->after('reasoning');
            $table->json('performance_summary')->nullable()->after('explanation');
            $table->dateTime('last_evaluated_at')->nullable()->after('performance_summary');

            $table->index('last_evaluated_at', 'idx_trading_signals_last_evaluated_at');
        });
    }

    public function down(): void
    {
        Schema::table('trading_signals', function (Blueprint $table): void {
            $table->dropIndex('idx_trading_signals_last_evaluated_at');
            $table->dropColumn(['explanation', 'performance_summary', 'last_evaluated_at']);
        });
    }
};
