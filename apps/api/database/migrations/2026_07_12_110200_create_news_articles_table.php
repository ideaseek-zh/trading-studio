<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('news_articles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_id')->constrained('news_sources')->cascadeOnDelete();
            $table->string('source_item_id', 128)->nullable();
            $table->string('title', 512);
            $table->text('summary')->nullable();
            $table->string('canonical_url', 1024)->nullable();
            $table->string('author', 128)->nullable();
            $table->dateTime('published_at');
            $table->dateTime('fetched_at');
            $table->string('category', 64)->nullable();
            $table->string('importance_level', 16)->default('C');
            $table->string('sentiment', 16)->nullable();
            $table->string('status', 32)->default('published');
            $table->char('title_hash', 64);
            $table->char('content_hash', 64)->nullable();
            $table->unsignedBigInteger('simhash')->nullable();
            $table->unsignedBigInteger('cluster_id')->nullable();
            $table->dateTime('ai_processed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['source_id', 'source_item_id'], 'uk_source_item');
            $table->index('published_at', 'idx_published_at');
            $table->index('title_hash', 'idx_title_hash');
            $table->index(['category', 'status'], 'idx_category_status');
            $table->index('cluster_id', 'idx_cluster_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_articles');
    }
};
