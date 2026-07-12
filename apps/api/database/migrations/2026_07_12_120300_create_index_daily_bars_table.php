<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('index_daily_bars', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_index_id')->constrained('market_indices')->cascadeOnDelete();
            $table->date('trade_date');
            $table->decimal('open', 18, 4)->nullable();
            $table->decimal('high', 18, 4)->nullable();
            $table->decimal('low', 18, 4)->nullable();
            $table->decimal('close', 18, 4)->nullable();
            $table->decimal('pre_close', 18, 4)->nullable();
            $table->decimal('volume', 24, 4)->nullable();
            $table->decimal('amount', 24, 4)->nullable();
            $table->decimal('pct_change', 12, 6)->nullable();
            $table->string('provider', 32);
            $table->dateTime('source_timestamp')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['market_index_id', 'trade_date'], 'uk_index_trade_date');
            $table->index(['market_index_id', 'trade_date'], 'idx_index_trade_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('index_daily_bars');
    }
};
