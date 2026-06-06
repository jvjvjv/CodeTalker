<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Jvjvjv\CodeTalker\Support\AiSystemPromptIds;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('ai_system_prompts')->insert([
            [
                'id' => AiSystemPromptIds::DEFAULT,
                'title' => 'Default Prompt',
                'description' => 'A default system prompt for general use.',
                'content' => 'You are a helpful assistant.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => AiSystemPromptIds::CREATIVE,
                'title' => 'Creative Prompt',
                'description' => 'A system prompt designed for creative tasks.',
                'content' => 'You are a creative assistant specializing in imaginative and original content.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => AiSystemPromptIds::TECHNICAL,
                'title' => 'Technical Prompt',
                'description' => 'A system prompt tailored for technical tasks.',
                'content' => 'You are a technical assistant specializing in software engineering, architecture, and problem-solving.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('ai_system_prompts')->whereIn('id', [
            AiSystemPromptIds::DEFAULT,
            AiSystemPromptIds::CREATIVE,
            AiSystemPromptIds::TECHNICAL,
        ])->delete();
    }
};
