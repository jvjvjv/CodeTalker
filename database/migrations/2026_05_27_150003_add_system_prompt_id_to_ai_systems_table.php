<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_systems', function (Blueprint $table) {
            $table->unsignedBigInteger('system_prompt_id')->nullable()->after('system_prompt');
            $table->foreign('system_prompt_id')
                ->references('id')
                ->on('ai_system_prompts')
                ->nullOnDelete();
        });

        // Migrate existing system_prompt text values to new records
        DB::table('ai_systems')
            ->whereNotNull('system_prompt')
            ->where('system_prompt', '!=', '')
            ->orderBy('id')
            ->each(function ($system) {
                $promptId = DB::table('ai_system_prompts')->insertGetId([
                    'title' => mb_substr($system->name . ' Custom Prompt', 0, 64),
                    'description' => 'Custom prompt migrated from AI system.',
                    'content' => $system->system_prompt,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('ai_systems')
                    ->where('id', $system->id)
                    ->update(['system_prompt_id' => $promptId]);
            });
    }

    public function down(): void
    {
        // Restore system_prompt text for all systems, combining feature-based prompts where needed
        DB::table('ai_systems')
            ->orderBy('id')
            ->each(function ($system) {
                $featureDefaults = DB::table('ai_system_feature_defaults')
                    ->where('ai_system_id', $system->id)
                    ->pluck('feature');

                $isTargetedResume = $featureDefaults->contains('targeted-resume');
                $isCoverLetter = $featureDefaults->contains('cover-letter');

                if ($isTargetedResume || $isCoverLetter) {
                    $parts = [];
                    if ($isTargetedResume) {
                        $prompt = DB::table('ai_system_prompts')->where('id', 4)->value('content');
                        if ($prompt) {
                            $parts[] = $prompt;
                        }
                    }
                    if ($isCoverLetter) {
                        $prompt = DB::table('ai_system_prompts')->where('id', 5)->value('content');
                        if ($prompt) {
                            $parts[] = $prompt;
                        }
                    }
                    if (!empty($parts)) {
                        DB::table('ai_systems')
                            ->where('id', $system->id)
                            ->update(['system_prompt' => implode("\n\n---\n\n", $parts)]);
                    }
                } elseif ($system->system_prompt_id) {
                    $content = DB::table('ai_system_prompts')->where('id', $system->system_prompt_id)->value('content');
                    if ($content) {
                        DB::table('ai_systems')
                            ->where('id', $system->id)
                            ->update(['system_prompt' => $content]);
                    }
                }
            });

        Schema::table('ai_systems', function (Blueprint $table) {
            $table->dropForeign(['system_prompt_id']);
            $table->dropColumn('system_prompt_id');
        });
    }
};
