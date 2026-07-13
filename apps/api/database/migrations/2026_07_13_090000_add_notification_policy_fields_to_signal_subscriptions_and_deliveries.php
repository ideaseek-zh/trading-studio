<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signal_subscriptions', function (Blueprint $table): void {
            $table->json('channel_routes')->nullable()->after('secret_token');
            $table->json('quiet_hours')->nullable()->after('filters');
            $table->json('escalation_rules')->nullable()->after('quiet_hours');
            $table->unsignedSmallInteger('debounce_window_minutes')->default(5)->after('escalation_rules');
            $table->unsignedSmallInteger('merge_window_minutes')->default(0)->after('debounce_window_minutes');
            $table->unsignedSmallInteger('max_merge_signals')->default(5)->after('merge_window_minutes');
        });

        Schema::table('signal_deliveries', function (Blueprint $table): void {
            $table->string('batch_key', 128)->nullable()->after('delivery_status');
            $table->string('suppression_reason', 64)->nullable()->after('batch_key');
            $table->json('dispatch_context')->nullable()->after('payload');

            $table->index('batch_key', 'idx_signal_deliveries_batch_key');
            $table->index(['suppression_reason', 'next_retry_at'], 'idx_signal_deliveries_suppression_retry');
        });
    }

    public function down(): void
    {
        Schema::table('signal_deliveries', function (Blueprint $table): void {
            $table->dropIndex('idx_signal_deliveries_batch_key');
            $table->dropIndex('idx_signal_deliveries_suppression_retry');
            $table->dropColumn([
                'batch_key',
                'suppression_reason',
                'dispatch_context',
            ]);
        });

        Schema::table('signal_subscriptions', function (Blueprint $table): void {
            $table->dropColumn([
                'channel_routes',
                'quiet_hours',
                'escalation_rules',
                'debounce_window_minutes',
                'merge_window_minutes',
                'max_merge_signals',
            ]);
        });
    }
};
