<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_turn_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_turn_run_id')->index();
            $table->unsignedInteger('sequence');
            $table->json('payload');
            $table->timestamp('created_at')->nullable();

            // The reader asks for everything after a sequence it already
            // holds, so a duplicate would silently replay or skip output.
            $table->unique(['ai_turn_run_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_turn_events');
    }
};
