<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news_articles', function (Blueprint $table): void {
            $table->dateTime('source_timestamp')->nullable()->after('fetched_at');
            $table->string('language', 16)->default('zh-CN')->after('status');
            $table->string('copyright_status', 32)->default('restricted')->after('language');
            $table->string('quality_status', 32)->default('pending')->after('copyright_status');
            $table->unsignedTinyInteger('quality_score')->nullable()->after('quality_status');
            $table->string('parser_version', 32)->nullable()->after('quality_score');
            $table->string('request_id', 64)->nullable()->after('parser_version');
            $table->char('checksum', 64)->nullable()->after('request_id');

            $table->index(['quality_status', 'published_at'], 'idx_quality_status_published_at');
            $table->index('checksum', 'idx_checksum');
        });
    }

    public function down(): void
    {
        Schema::table('news_articles', function (Blueprint $table): void {
            $table->dropIndex('idx_quality_status_published_at');
            $table->dropIndex('idx_checksum');
            $table->dropColumn([
                'source_timestamp',
                'language',
                'copyright_status',
                'quality_status',
                'quality_score',
                'parser_version',
                'request_id',
                'checksum',
            ]);
        });
    }
};
