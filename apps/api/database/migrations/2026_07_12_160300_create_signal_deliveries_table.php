<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signal_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('trading_signal_id')->constrained('trading_signals')->cascadeOnDelete();
            $table->foreignId('signal_subscription_id')->constrained('signal_subscriptions')->cascadeOnDelete();
            $table->string('delivery_channel', 32)->default('webhook');
            $table->string('delivery_status', 32)->default('queued');
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->json('payload')->nullable();
            $table->dateTime('last_attempted_at')->nullable();
            $table->dateTime('next_retry_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->timestamps();

            $table->unique(['trading_signal_id', 'signal_subscription_id'], 'uniq_signal_delivery_signal_subscription');
            $table->index(['delivery_status', 'next_retry_at'], 'idx_signal_deliveries_status_retry');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signal_deliveries');
    }
};
