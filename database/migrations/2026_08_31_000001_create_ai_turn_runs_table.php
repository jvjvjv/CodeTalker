<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_turn_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('ai_conversation_id')->index();
            $table->string('status', 20)->index();
            $table->text('prompt');
            // The abandonment signal: connection_aborted() reports 0 in a
            // worker, so "nobody is reading this" is the only usable stand-in
            // for the browser having gone away.
            $table->timestamp('last_polled_at')->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_turn_runs');
    }
};
