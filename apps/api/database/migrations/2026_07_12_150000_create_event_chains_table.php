<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_chains', function (Blueprint $table): void {
            $table->id();
            $table->char('chain_key', 64)->unique();
            $table->string('chain_type', 64);
            $table->string('topic', 255);
            $table->text('summary')->nullable();
            $table->string('status', 32)->default('active');
            $table->foreignId('primary_security_id')->nullable()->constrained('securities')->nullOnDelete();
            $table->dateTime('started_at');
            $table->dateTime('latest_occurred_at');
            $table->dateTime('latest_published_at')->nullable();
            $table->string('importance_level', 16);
            $table->string('sentiment', 16)->nullable();
            $table->unsignedInteger('event_count')->default(0);
            $table->unsignedInteger('article_count')->default(0);
            $table->json('facts')->nullable();
            $table->timestamps();

            $table->index(['primary_security_id', 'chain_type'], 'idx_event_chains_security_type');
            $table->index('latest_occurred_at', 'idx_event_chains_latest_occurred_at');
            $table->index('status', 'idx_event_chains_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_chains');
    }
};
