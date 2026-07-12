<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_type', 64);
            $table->string('title', 512);
            $table->text('summary')->nullable();
            $table->dateTime('occurred_at');
            $table->dateTime('detected_at');
            $table->string('importance_level', 16);
            $table->string('sentiment', 16)->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->string('status', 32)->default('detected');
            $table->foreignId('primary_security_id')->nullable()->constrained('securities')->nullOnDelete();
            $table->char('fingerprint', 64)->unique();
            $table->json('facts')->nullable();
            $table->json('ai_analysis')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->timestamps();

            $table->index('occurred_at', 'idx_occurred_at');
            $table->index('primary_security_id', 'idx_primary_security');
            $table->index(['event_type', 'importance_level'], 'idx_type_importance');
            $table->index('status', 'idx_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
