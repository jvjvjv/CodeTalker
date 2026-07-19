<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_provider_exchanges', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('endpoint');
            $table->string('method', 16);
            $table->boolean('streaming')->default(false);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->longText('request_body')->nullable();
            $table->longText('raw_response')->nullable();
            $table->string('model')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedBigInteger('ai_system_id')->nullable();
            $table->unsignedBigInteger('ai_conversation_id')->nullable();
            $table->unsignedBigInteger('ai_llm_message_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index('provider');
            $table->index('ai_conversation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_provider_exchanges');
    }
};
