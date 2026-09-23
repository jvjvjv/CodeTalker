<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_mcp_server_tools', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_mcp_server_id')->index();
            $table->string('remote_name');
            // Null only for a tool whose name collides with another tool on the
            // same server once sanitized — it is stored so an admin can see it,
            // but it has no name to be granted by.
            $table->string('exposed_name', 64)->nullable()->unique();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->json('input_schema')->nullable();
            $table->json('annotations')->nullable();
            $table->boolean('representable')->default(true);
            $table->text('unrepresentable_reason')->nullable();
            $table->string('definition_hash', 40);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['ai_mcp_server_id', 'remote_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_mcp_server_tools');
    }
};
