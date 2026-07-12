<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trading_signals', function (Blueprint $table): void {
            $table->id();
            $table->char('signal_key', 64)->unique();
            $table->foreignId('signal_rule_id')->nullable()->constrained('signal_rules')->nullOnDelete();
            $table->foreignId('event_chain_id')->nullable()->constrained('event_chains')->nullOnDelete();
            $table->foreignId('latest_event_id')->nullable()->constrained('events')->nullOnDelete();
            $table->foreignId('primary_security_id')->nullable()->constrained('securities')->nullOnDelete();
            $table->string('signal_type', 64);
            $table->string('direction', 16);
            $table->string('horizon_label', 32);
            $table->string('status', 32)->default('active');
            $table->string('title', 255);
            $table->text('summary')->nullable();
            $table->decimal('signal_score', 5, 2);
            $table->decimal('confidence_score', 5, 2);
            $table->decimal('urgency_score', 5, 2);
            $table->decimal('impact_score', 5, 2);
            $table->decimal('risk_score', 5, 2);
            $table->dateTime('triggered_at');
            $table->dateTime('published_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->json('reasoning')->nullable();
            $table->json('facts')->nullable();
            $table->timestamps();

            $table->index(['primary_security_id', 'status', 'signal_score'], 'idx_signals_security_status_score');
            $table->index(['event_chain_id', 'status'], 'idx_signals_chain_status');
            $table->index(['signal_type', 'direction'], 'idx_signals_type_direction');
            $table->index('published_at', 'idx_signals_published_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trading_signals');
    }
};
