<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_task_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('task_key', 128);
            $table->string('task_name', 128);
            $table->string('status', 32)->default('running');
            $table->string('triggered_by', 128)->nullable();
            $table->json('input')->nullable();
            $table->json('result')->nullable();
            $table->mediumText('output')->nullable();
            $table->mediumText('error')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['task_key', 'created_at'], 'idx_ops_task_runs_key_created');
            $table->index(['status', 'created_at'], 'idx_ops_task_runs_status_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_task_runs');
    }
};
