<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->foreignId('event_chain_id')
                ->nullable()
                ->after('primary_security_id')
                ->constrained('event_chains')
                ->nullOnDelete();
            $table->string('timeline_stage', 32)->nullable()->after('event_chain_id');
            $table->unsignedInteger('timeline_order')->nullable()->after('timeline_stage');

            $table->index(['event_chain_id', 'timeline_order'], 'idx_event_chain_timeline_order');
            $table->index('timeline_stage', 'idx_events_timeline_stage');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropIndex('idx_event_chain_timeline_order');
            $table->dropIndex('idx_events_timeline_stage');
            $table->dropConstrainedForeignId('event_chain_id');
            $table->dropColumn(['timeline_stage', 'timeline_order']);
        });
    }
};
