<?php

namespace Jvjvjv\CodeTalker\Services\ChatBot\Conversation;

use Jvjvjv\CodeTalker\Models\AiPersona;
use Jvjvjv\CodeTalker\Models\AiConversation;
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

    public function build(
        AiPersona $persona,
        ?AiConversation $conversation = null,
        ?string $visitorName = null,
        ?string $visitorEmail = null,
    ): string {
        $prompt = strtr($persona->prompt_template, [
            '{{persona_name}}' => $persona->name,
            '{{persona_slug}}' => $persona->slug,
            '{{persona_description}}' => $persona->description ?? '',
            '{{visitor_name}}' => $visitorName ?? '',
            '{{visitor_email}}' => $visitorEmail ?? '',
        ]);

        $systemPrompt = trim((string) $persona->aiSystem?->system_prompt);
        $memoryPrompt = trim($this->memories($persona, $conversation));

        return collect([
            $systemPrompt !== '' ? $systemPrompt : null,
            $prompt,
            $memoryPrompt !== '' ? "## Learned Insights\n{$memoryPrompt}" : null,
        ])->filter()->implode("\n\n");
    }

    /**
     * Memories are scoped to whoever is talking. During a live conversation that
     * identity comes from the conversation itself; when opening one, the only
     * identity available is the authenticated user, if any.
     */
    private function memories(AiPersona $persona, ?AiConversation $conversation): string
    {
        $userId = null;
        $visitorEmail = null;

        if ($conversation !== null) {
            $userId = $conversation->user_id;
            $visitorEmail = $conversation->visitor_email;
        } elseif (auth()->check()) {
            $userId = auth()->id();
        }

        return $this->memoryService->getMemoriesForPrompt($persona->featureKey(), $userId, $visitorEmail);
    }
}
