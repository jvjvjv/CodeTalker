<?php

namespace Jvjvjv\CodeTalker\Services\Management;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Models\AiSystemPrompt;

/**
 * Write operations on reusable system prompts.
 */
class AiSystemPromptManager
{
    /**
     * The 64-character title ceiling is why AiSystemManager truncates generated
     * prompt titles to the same length.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:64'],
            'description' => ['required', 'string', 'max:200'],
            'content' => ['required', 'string'],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): AiSystemPrompt
    {
        return AiSystemPrompt::create(
            Validator::make($data, static::rules())->validate()
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(AiSystemPrompt $prompt, array $data): AiSystemPrompt
    {
        $prompt->update(Validator::make($data, static::rules())->validate());

        return $prompt;
    }

    /**
     * Delete a prompt, clearing it from any system that references it.
     *
     * There is no database-level cascade for this, so it runs by hand — inside
     * a transaction, because a system left pointing at a deleted prompt would
     * break every turn that system serves.
     *
     * @return int the number of systems whose prompt reference was cleared
     */
    public function delete(AiSystemPrompt $prompt): int
    {
        return DB::transaction(function () use ($prompt): int {
            $systemCount = AiSystem::where('system_prompt_id', $prompt->id)->count();

            AiSystem::where('system_prompt_id', $prompt->id)
                ->update(['system_prompt_id' => null]);

            $prompt->delete();

            return $systemCount;
        });
    }

    /**
     * Prompts in display order, with the number of systems using each.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, AiSystemPrompt>
     */
    public function list(): \Illuminate\Database\Eloquent\Collection
    {
        return AiSystemPrompt::withCount('aiSystems')->ordered()->get();
    }
}
