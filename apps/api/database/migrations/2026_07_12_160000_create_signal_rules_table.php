<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signal_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('rule_key', 64)->unique();
            $table->string('name', 128);
            $table->text('description')->nullable();
            $table->string('scope_type', 32)->default('event_chain');
            $table->string('chain_type', 64)->nullable();
            $table->string('signal_type', 64);
            $table->string('default_direction', 16);
            $table->string('horizon_label', 32);
            $table->unsignedSmallInteger('horizon_days')->default(5);
            $table->decimal('min_signal_score', 5, 2)->default(60);
            $table->boolean('enabled')->default(true);
            $table->json('weight_profile')->nullable();
            $table->json('trigger_conditions')->nullable();
            $table->timestamps();

            $table->index(['enabled', 'chain_type'], 'idx_signal_rules_enabled_chain_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signal_rules');
    }
};
