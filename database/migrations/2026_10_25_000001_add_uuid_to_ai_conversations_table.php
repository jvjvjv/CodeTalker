<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * AiConversation writes `uuid` on create and generateChatHash() hashes it,
     * but no earlier migration created the column. Hosts that added it by
     * hand keep their column; only the missing values are filled.
     *
     * Rows are backfilled one at a time because each needs a distinct value.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('ai_conversations', 'uuid')) {
            Schema::table('ai_conversations', function (Blueprint $table) {
                $table->string('uuid', 36)->nullable()->unique()->after('id');
            });
        }

        DB::table('ai_conversations')
            ->whereNull('uuid')
            ->select('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('ai_conversations')
                        ->where('id', $row->id)
                        ->update(['uuid' => (string) Str::uuid()]);
                }
            });

        // A memory written for an authenticated user belongs to that user
        // alone; a visitor_email on the same row would let a visitor who
        // types that address read it.
        DB::table('ai_feature_memories')
            ->whereNotNull('user_id')
            ->whereNotNull('visitor_email')
            ->update(['visitor_email' => null]);
    }

    /**
     * The column is left in place: a host that created it by hand before this
     * migration existed would lose data it already relied on.
     */
    public function down(): void
    {
    }
};
