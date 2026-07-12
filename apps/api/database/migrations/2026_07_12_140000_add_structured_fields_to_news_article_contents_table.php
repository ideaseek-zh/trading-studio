<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news_article_contents', function (Blueprint $table): void {
            $table->json('structured_data')->nullable()->after('quality_issues');
            $table->string('extraction_version', 32)->nullable()->after('structured_data');
            $table->dateTime('extracted_at')->nullable()->after('extraction_version');
        });
    }

    public function down(): void
    {
        Schema::table('news_article_contents', function (Blueprint $table): void {
            $table->dropColumn(['structured_data', 'extraction_version', 'extracted_at']);
        });
    }
};
