<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signal_subscriptions', function (Blueprint $table): void {
            $table->string('priority_level', 16)->default('normal')->after('channel_type');
            $table->unsignedSmallInteger('priority_order')->default(100)->after('priority_level');

            $table->index(['enabled', 'priority_order'], 'idx_signal_subscriptions_enabled_priority');
        });
    }

    public function down(): void
    {
        Schema::table('signal_subscriptions', function (Blueprint $table): void {
            $table->dropIndex('idx_signal_subscriptions_enabled_priority');
            $table->dropColumn(['priority_level', 'priority_order']);
        });
    }
};
