<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_quotes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('security_id')->constrained('securities')->cascadeOnDelete();
            $table->dateTime('quote_time');
            $table->decimal('last_price', 18, 4)->nullable();
            $table->decimal('pre_close', 18, 4)->nullable();
            $table->decimal('open', 18, 4)->nullable();
            $table->decimal('high', 18, 4)->nullable();
            $table->decimal('low', 18, 4)->nullable();
            $table->decimal('volume', 24, 4)->nullable();
            $table->decimal('amount', 24, 4)->nullable();
            $table->decimal('turnover_rate', 12, 6)->nullable();
            $table->decimal('pct_change', 12, 6)->nullable();
            $table->string('provider', 32);
            $table->dateTime('source_timestamp')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['security_id', 'quote_time'], 'uk_security_quote_time');
            $table->index(['security_id', 'quote_time'], 'idx_security_quote_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_quotes');
    }
};
