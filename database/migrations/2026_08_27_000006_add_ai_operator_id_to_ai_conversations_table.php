<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->foreignId('ai_operator_id')->nullable()->after('ai_persona_id')->constrained('ai_operators')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->dropForeign(['ai_operator_id']);
            $table->dropColumn('ai_operator_id');
        });
    }
};
