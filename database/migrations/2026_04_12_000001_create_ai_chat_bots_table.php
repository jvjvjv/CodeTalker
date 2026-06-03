<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ai_chat_bots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_system_id')->constrained('ai_systems')->restrictOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->text('prompt_template');
            $table->json('allowed_roles')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_public')->default(false);
            $table->boolean('require_visitor_identity')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_chat_bots');
    }
};
