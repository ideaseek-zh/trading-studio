<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signal_performance_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('trading_signal_id')->constrained('trading_signals')->cascadeOnDelete();
            $table->unsignedSmallInteger('horizon_days');
            $table->string('evaluation_status', 32)->default('pending');
            $table->string('benchmark_code', 32)->nullable();
            $table->date('entry_trade_date')->nullable();
            $table->date('exit_trade_date')->nullable();
            $table->unsignedSmallInteger('holding_days')->nullable();
            $table->decimal('entry_price', 18, 4)->nullable();
            $table->decimal('exit_price', 18, 4)->nullable();
            $table->decimal('return_pct', 9, 4)->nullable();
            $table->decimal('benchmark_return_pct', 9, 4)->nullable();
            $table->decimal('alpha_return_pct', 9, 4)->nullable();
            $table->decimal('max_upside_pct', 9, 4)->nullable();
            $table->decimal('max_drawdown_pct', 9, 4)->nullable();
            $table->decimal('win_probability', 5, 2)->nullable();
            $table->decimal('coverage_pct', 5, 2)->nullable();
            $table->dateTime('evaluated_at')->nullable();
            $table->json('metrics')->nullable();
            $table->timestamps();

            $table->unique(['trading_signal_id', 'horizon_days'], 'uniq_signal_performance_signal_horizon');
            $table->index(['evaluation_status', 'horizon_days'], 'idx_signal_performance_status_horizon');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signal_performance_snapshots');
    }
};
