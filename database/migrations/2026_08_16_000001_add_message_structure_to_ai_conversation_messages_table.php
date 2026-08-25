<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the structure laravel/ai's ConversationStore contract needs to replay a
 * turn: what was attached to it, which tools it called, what they returned, and
 * what it cost. Without these, history can only be rebuilt as bare text.
 *
 * All nullable — existing rows predate the columns and stay valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_conversation_messages', function (Blueprint $table): void {
            $table->string('user_id')->nullable()->after('ai_conversation_id');
            $table->string('agent')->nullable()->after('role');
            $table->json('attachments')->nullable()->after('blocks');
            $table->json('tool_calls')->nullable()->after('attachments');
            $table->json('tool_results')->nullable()->after('tool_calls');
            $table->json('usage')->nullable()->after('tool_results');
        });
    }

    public function down(): void
    {
        Schema::table('ai_conversation_messages', function (Blueprint $table): void {
            $table->dropColumn([
                'user_id',
                'agent',
                'attachments',
                'tool_calls',
                'tool_results',
                'usage',
            ]);
        });
    }
};
