<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signal_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->string('subscriber_key', 128);
            $table->string('subscriber_name', 128)->nullable();
            $table->string('channel_type', 32)->default('webhook');
            $table->string('endpoint_url', 1024);
            $table->string('secret_token', 128)->nullable();
            $table->decimal('min_signal_score', 5, 2)->default(60);
            $table->boolean('enabled')->default(true);
            $table->json('filters')->nullable();
            $table->dateTime('last_notified_at')->nullable();
            $table->timestamps();

            $table->index(['subscriber_key', 'enabled'], 'idx_signal_subscriptions_subscriber_enabled');
            $table->index('channel_type', 'idx_signal_subscriptions_channel');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signal_subscriptions');
    }
};
