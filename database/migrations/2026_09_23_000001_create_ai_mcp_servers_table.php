<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_mcp_servers', function (Blueprint $table): void {
            $table->id();
            // Unique across soft-deleted rows too: the slug is baked into every
            // exposed tool name and every grant, so it is never reused.
            $table->string('slug', 24)->unique();
            $table->string('name');
            $table->string('transport', 20)->default('http');
            $table->string('url', 2048);
            $table->text('auth')->nullable();
            $table->unsignedSmallInteger('timeout_seconds')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_sync_error')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_mcp_servers');
    }
};
