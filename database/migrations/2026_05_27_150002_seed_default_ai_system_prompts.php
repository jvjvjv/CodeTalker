<?php

use App\Services\TargetedResumeService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $service = app(TargetedResumeService::class);

        DB::table('ai_system_prompts')->insert([
            [
                'id' => TargetedResumeService::PROMPT_ID_DEFAULT,
                'title' => 'Default Prompt',
                'description' => 'A default system prompt for general use.',
                'content' => 'You are a helpful assistant.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => TargetedResumeService::PROMPT_ID_CREATIVE,
                'title' => 'Creative Prompt',
                'description' => 'A system prompt designed for creative tasks.',
                'content' => 'You are a creative assistant specializing in imaginative and original content.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => TargetedResumeService::PROMPT_ID_TECHNICAL,
                'title' => 'Technical Prompt',
                'description' => 'A system prompt tailored for technical tasks.',
                'content' => 'You are a technical assistant specializing in software engineering, architecture, and problem-solving.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => TargetedResumeService::PROMPT_ID_TARGETED_RESUME,
                'title' => 'Targeted Resume and Cover Letter Prompt',
                'description' => 'A system prompt designed to help users create targeted resumes and cover letters.',
                'content' => $service->buildResumePortionPrompt(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => TargetedResumeService::PROMPT_ID_COVER_LETTER,
                'title' => 'Cover Letter Prompt',
                'description' => 'A system prompt designed to help users create cover letters.',
                'content' => $service->buildCoverLetterPortionPrompt(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('ai_system_prompts')->whereIn('id', [
            TargetedResumeService::PROMPT_ID_DEFAULT,
            TargetedResumeService::PROMPT_ID_CREATIVE,
            TargetedResumeService::PROMPT_ID_TECHNICAL,
            TargetedResumeService::PROMPT_ID_TARGETED_RESUME,
            TargetedResumeService::PROMPT_ID_COVER_LETTER,
        ])->delete();
    }
};
