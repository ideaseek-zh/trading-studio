<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('template_key', 128)->unique();
            $table->string('name', 128);
            $table->string('channel_type', 32)->default('webhook');
            $table->string('message_format', 32)->default('markdown');
            $table->text('subject_template')->nullable();
            $table->longText('body_template');
            $table->json('config')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['channel_type', 'enabled'], 'idx_notification_templates_channel_enabled');
        });

        Schema::create('notification_channel_credentials', function (Blueprint $table): void {
            $table->id();
            $table->string('credential_key', 128)->unique();
            $table->string('name', 128);
            $table->string('channel_type', 32)->default('webhook');
            $table->longText('endpoint_url')->nullable();
            $table->longText('secret_token')->nullable();
            $table->longText('signing_secret')->nullable();
            $table->json('config')->nullable();
            $table->boolean('enabled')->default(true);
            $table->dateTime('last_verified_at')->nullable();
            $table->timestamps();

            $table->index(['channel_type', 'enabled'], 'idx_notification_credentials_channel_enabled');
        });

        Schema::table('signal_subscriptions', function (Blueprint $table): void {
            $table->foreignId('notification_template_id')
                ->nullable()
                ->after('secret_token')
                ->constrained('notification_templates')
                ->nullOnDelete();
            $table->foreignId('notification_channel_credential_id')
                ->nullable()
                ->after('notification_template_id')
                ->constrained('notification_channel_credentials')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('signal_subscriptions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('notification_template_id');
            $table->dropConstrainedForeignId('notification_channel_credential_id');
        });

        Schema::dropIfExists('notification_channel_credentials');
        Schema::dropIfExists('notification_templates');
    }
};
