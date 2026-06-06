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
        Schema::table('ai_feature_memories', function (Blueprint $table) {
            // Add user_id for logged-in users to scope memories per user across
            // integer, UUID, ULID, and custom string-based host app keys.
            $table->string('user_id', 191)
                ->nullable()
                ->after('source_conversation_id');

            // Add visitor_email for anonymous visitors who provide their email
            $table->string('visitor_email', 255)
                ->nullable()
                ->after('user_id');

            // Index for efficient user-based memory lookups
            $table->index(['feature', 'user_id', 'is_active']);
            $table->index(['feature', 'visitor_email', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_feature_memories', function (Blueprint $table) {
            $table->dropIndex(['feature', 'user_id', 'is_active']);
            $table->dropIndex(['feature', 'visitor_email', 'is_active']);
            $table->dropColumn(['user_id', 'visitor_email']);
        });
    }
};