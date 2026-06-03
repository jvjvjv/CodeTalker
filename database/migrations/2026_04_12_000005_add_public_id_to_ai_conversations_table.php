<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->string('public_id', 26)->nullable()->after('id');
        });

        DB::table('ai_conversations')
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function ($conversations): void {
                foreach ($conversations as $conversation) {
                    DB::table('ai_conversations')
                        ->where('id', $conversation->id)
                        ->update(['public_id' => (string) Str::ulid()]);
                }
            });

        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->unique('public_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->dropUnique(['public_id']);
            $table->dropColumn('public_id');
        });
    }
};
