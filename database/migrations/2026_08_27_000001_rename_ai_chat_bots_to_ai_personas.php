<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('ai_chat_bots', 'ai_personas');
    }

    public function down(): void
    {
        Schema::rename('ai_personas', 'ai_chat_bots');
    }
};
