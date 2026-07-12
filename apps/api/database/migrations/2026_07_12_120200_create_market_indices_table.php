<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_indices', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 128);
            $table->string('exchange', 16)->nullable();
            $table->string('market', 16)->default('CN');
            $table->string('index_type', 32)->default('broad');
            $table->string('status', 32)->default('active');
            $table->dateTime('quote_time')->nullable();
            $table->decimal('last_price', 18, 4)->nullable();
            $table->decimal('change_amount', 18, 4)->nullable();
            $table->decimal('pct_change', 12, 6)->nullable();
            $table->decimal('open', 18, 4)->nullable();
            $table->decimal('high', 18, 4)->nullable();
            $table->decimal('low', 18, 4)->nullable();
            $table->decimal('pre_close', 18, 4)->nullable();
            $table->decimal('volume', 24, 4)->nullable();
            $table->decimal('amount', 24, 4)->nullable();
            $table->dateTime('source_timestamp')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['market', 'index_type'], 'idx_market_index_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_indices');
    }
};
