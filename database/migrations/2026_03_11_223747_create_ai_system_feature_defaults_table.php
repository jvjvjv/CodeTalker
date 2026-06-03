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
        Schema::create('ai_system_feature_defaults', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_system_id')->constrained('ai_systems')->cascadeOnDelete();
            $table->string('feature', 100)->unique();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_system_feature_defaults');
    }
};
