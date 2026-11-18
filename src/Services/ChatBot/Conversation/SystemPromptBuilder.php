<?php

namespace Jvjvjv\CodeTalker\Services\ChatBot\Conversation;

use Jvjvjv\CodeTalker\Models\AiPersona;
use Jvjvjv\CodeTalker\Services\AiMemoryService;

/**
 * Assembles the system prompt a persona runs under: the AiSystem's base
 * prompt, the persona's own template with its placeholders filled in, and
 * anything the memory system has learned about this particular visitor.
 */
class SystemPromptBuilder
{
    public function __construct(
        private AiMemoryService $memoryService,
    ) {
    }

    /**
     * The visitor fields are interpolated as given, so the caller sanitises
     * them first (see VisitorIdentity).
     */
    public function build(
        AiPersona $persona,
        ?string $visitorName = null,
        ?string $visitorEmail = null,
        string|int|null $userId = null,
    ): string {
        $prompt = strtr($persona->prompt_template, [
            '{{persona_name}}' => $persona->name,
            '{{persona_slug}}' => $persona->slug,
            '{{persona_description}}' => $persona->description ?? '',
            '{{visitor_name}}' => $visitorName ?? '',
            '{{visitor_email}}' => $visitorEmail ?? '',
        ]);

        $systemPrompt = trim((string) $persona->aiSystem?->system_prompt);
        $memoryPrompt = trim($this->memories($persona, $userId, $visitorEmail));

        return collect([
            $systemPrompt !== '' ? $systemPrompt : null,
            $prompt,
            $memoryPrompt !== '' ? "## Learned Insights\n{$memoryPrompt}" : null,
        ])->filter()->implode("\n\n");
    }

    /**
     * Memories are scoped to the identity of the conversation being opened, as
     * the caller supplies it — never to the ambient authenticated session. A
     * turn driven from a queue or the console has no session, and one driven
     * on another user's behalf has the wrong one.
     */
    private function memories(AiPersona $persona, string|int|null $userId, ?string $visitorEmail): string
    {
        return $this->memoryService->getMemoriesForPrompt($persona->featureKey(), $userId, $visitorEmail);
    }
}
