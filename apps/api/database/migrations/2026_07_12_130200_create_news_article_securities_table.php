<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('news_article_securities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('news_article_id')->constrained('news_articles')->cascadeOnDelete();
            $table->foreignId('security_id')->constrained('securities')->cascadeOnDelete();
            $table->string('mention_type', 32)->default('mentioned');
            $table->string('matched_text', 128)->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['news_article_id', 'security_id'], 'uk_news_article_security');
            $table->index(['security_id', 'is_primary'], 'idx_security_primary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_article_securities');
    }
};
