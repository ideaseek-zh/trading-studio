<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('securities', function (Blueprint $table): void {
            $table->id();
            $table->string('canonical_symbol', 32)->unique();
            $table->string('symbol', 16);
            $table->string('exchange', 16);
            $table->string('market', 16)->default('CN');
            $table->string('security_type', 32)->default('stock');
            $table->string('name', 128);
            $table->string('short_name', 64)->nullable();
            $table->string('pinyin', 128)->nullable();
            $table->date('list_date')->nullable();
            $table->date('delist_date')->nullable();
            $table->string('status', 32)->default('active');
            $table->string('currency', 8)->default('CNY');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['symbol', 'exchange'], 'idx_symbol_exchange');
            $table->index('name', 'idx_name');
            $table->index(['status', 'security_type'], 'idx_status_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('securities');
    }
};
