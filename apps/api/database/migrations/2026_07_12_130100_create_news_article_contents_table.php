<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('news_article_contents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('news_article_id')->unique()->constrained('news_articles')->cascadeOnDelete();
            $table->longText('content_text');
            $table->longText('content_html')->nullable();
            $table->json('attachments')->nullable();
            $table->json('images')->nullable();
            $table->json('tags')->nullable();
            $table->json('raw_payload')->nullable();
            $table->json('quality_issues')->nullable();
            $table->dateTime('cleaned_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_article_contents');
    }
};
