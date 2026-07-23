<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('strategy_workspaces', function (Blueprint $table): void {
            $table->id();
            $table->string('workspace_key', 128)->unique();
            $table->string('name', 128);
            $table->string('owner_key', 128)->default('default');
            $table->string('workspace_type', 32)->default('watchlist');
            $table->string('risk_profile', 32)->default('balanced');
            $table->string('base_currency', 8)->default('CNY');
            $table->foreignId('default_signal_subscription_id')
                ->nullable()
                ->constrained('signal_subscriptions')
                ->nullOnDelete();
            $table->json('settings')->nullable();
            $table->boolean('enabled')->default(true);
            $table->dateTime('last_reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['owner_key', 'enabled'], 'idx_strategy_workspaces_owner_enabled');
            $table->index(['workspace_type', 'enabled'], 'idx_strategy_workspaces_type_enabled');
        });

        Schema::create('strategy_workspace_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('strategy_workspace_id')->constrained('strategy_workspaces')->cascadeOnDelete();
            $table->foreignId('security_id')->constrained('securities')->cascadeOnDelete();
            $table->string('item_type', 32)->default('watch');
            $table->string('status', 32)->default('active');
            $table->decimal('position_quantity', 24, 4)->nullable();
            $table->decimal('average_cost', 18, 4)->nullable();
            $table->decimal('target_price', 18, 4)->nullable();
            $table->decimal('stop_loss_price', 18, 4)->nullable();
            $table->decimal('alert_score_threshold', 5, 2)->default(70);
            $table->integer('position_weight_bps')->nullable();
            $table->string('review_cadence', 32)->default('daily');
            $table->text('notes')->nullable();
            $table->json('tags')->nullable();
            $table->json('alert_preferences')->nullable();
            $table->dateTime('last_reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['strategy_workspace_id', 'security_id'], 'uk_strategy_workspace_security');
            $table->index(['strategy_workspace_id', 'status'], 'idx_strategy_items_workspace_status');
            $table->index(['security_id', 'status'], 'idx_strategy_items_security_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strategy_workspace_items');
        Schema::dropIfExists('strategy_workspaces');
    }
};
