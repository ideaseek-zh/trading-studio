<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('news_article_id')->constrained('news_articles')->cascadeOnDelete();
            $table->string('relation_type', 32)->default('primary');
            $table->timestamps();

            $table->unique(['event_id', 'news_article_id'], 'uk_event_source');
            $table->index('news_article_id', 'idx_event_source_article');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_sources');
    }
};
