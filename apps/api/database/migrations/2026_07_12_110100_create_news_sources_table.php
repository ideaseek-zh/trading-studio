<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('news_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('source_key', 64)->unique();
            $table->string('source_name', 128);
            $table->string('source_type', 32);
            $table->string('provider', 32);
            $table->string('access_mode', 32);
            $table->string('base_url', 1024)->nullable();
            $table->string('copyright_status', 32)->default('public');
            $table->boolean('robots_checked')->default(false);
            $table->unsignedInteger('rate_limit_per_minute')->nullable();
            $table->unsignedInteger('timeout_seconds')->default(10);
            $table->unsignedInteger('retry_times')->default(3);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_sources');
    }
};
