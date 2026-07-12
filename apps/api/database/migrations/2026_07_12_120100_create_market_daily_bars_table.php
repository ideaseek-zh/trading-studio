<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_daily_bars', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('security_id')->constrained('securities')->cascadeOnDelete();
            $table->date('trade_date');
            $table->decimal('open', 18, 4)->nullable();
            $table->decimal('high', 18, 4)->nullable();
            $table->decimal('low', 18, 4)->nullable();
            $table->decimal('close', 18, 4)->nullable();
            $table->decimal('pre_close', 18, 4)->nullable();
            $table->decimal('volume', 24, 4)->nullable();
            $table->decimal('amount', 24, 4)->nullable();
            $table->decimal('turnover_rate', 12, 6)->nullable();
            $table->decimal('pct_change', 12, 6)->nullable();
            $table->string('adjust_type', 16)->default('none');
            $table->string('provider', 32);
            $table->dateTime('source_timestamp')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['security_id', 'trade_date', 'adjust_type'], 'uk_security_trade_adjust');
            $table->index(['security_id', 'trade_date'], 'idx_security_trade_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_daily_bars');
    }
};
