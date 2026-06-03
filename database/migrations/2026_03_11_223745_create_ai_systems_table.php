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
        Schema::create('ai_systems', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('provider', 50);
            $table->text('api_key');
            $table->string('model');
            $table->string('base_url')->nullable();
            $table->string('api_version', 50)->nullable();
            $table->integer('max_tokens')->default(4096);
            $table->decimal('temperature', 3, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('config')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_systems');
    }
};
