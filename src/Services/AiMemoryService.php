<?php

namespace Jvjvjv\CodeTalker\Services;

use Jvjvjv\CodeTalker\Enums\AiConversationStatus;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Models\AiFeatureMemory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AiMemoryService
{
    public function __construct(
        private AiClientFactory $clientFactory,
    ) {
    }

    /**
     * Get formatted memory text for injection into a system prompt.
     *
     * Memories are scoped by user identity:
     * - For logged-in users (user_id): memories tied to that user across all their conversations with the chatbot
     * - For visitors (email): memories tied to their email address
     * This allows each individual user to have persistent chatbot memories while keeping different users' data separate.
     *
     * @param string $feature The feature key (e.g., 'chat-bot:resume-assistant')
     * @param string|int|null $userId User ID if logged in, null for visitors (supports both UUID and integer IDs)
     * @param string|null $email Email address for visitors or when user_id is unavailable
     */
    public function getMemoriesForPrompt(string $feature, string|int|null $userId = null, ?string $email = null): string
    {
        $query = AiFeatureMemory::query()
            ->forFeature($feature)
            ->active();

        // Scope memories to the current user (by ID or email)
        if ($userId !== null) {
            $query->where('user_id', $userId);
        } elseif ($email !== null) {
            $query->where('visitor_email', $email);
        } else {
            // No user identity - return no memories (or we could fallback to all memories for backward compatibility)
            return '';
        }

        $memories = $query->orderByDesc('confidence')
                          ->orderByDesc('times_reinforced')
                          ->get();

        if ($memories->isEmpty()) {
            return '';
        }

        $grouped = $memories->groupBy('category');
        $sections = [];

        $categoryLabels = [
            'preference' => 'User Preferences',
            'domain_knowledge' => 'Domain Knowledge',
            'system_tuning' => 'System Tuning Insights',
        ];

        foreach ($grouped as $category => $entries) {
            $label = $categoryLabels[$category] ?? ucfirst(str_replace('_', ' ', $category));
            $lines = $entries->map(fn (AiFeatureMemory $m) => "- {$m->content}")->implode("\n");
            $sections[] = "### {$label}\n{$lines}";
        }

        return implode("\n\n", $sections);
    }

    /**
     * Analyze a completed conversation and extract memory operations.
     * Memories are scoped to the current user (by ID or email).
     *
     * @return array{add: array<int, mixed>, update: array<int, mixed>, remove: array<int, mixed>}
     */
    public function analyzeConversation(AiConversation $conversation, string|int|null $userId = null, ?string $visitorEmail = null): array
    {
        $conversation->loadMissing(['messages', 'aiSystem']);

        // Scope existing memories to the current user
        $existingMemoriesQuery = AiFeatureMemory::query()
            ->forFeature($conversation->feature)
            ->active();

        if ($userId !== null) {
            $existingMemoriesQuery->where('user_id', $userId);
        } elseif ($visitorEmail !== null) {
            $existingMemoriesQuery->where('visitor_email', $visitorEmail);
        }

        $existingMemories = $existingMemoriesQuery->get(['key', 'category', 'content', 'confidence'])
            ->toArray();

        $conversationMessages = $conversation->messages
            ->filter(fn ($m) => $m->role !== 'system')
            ->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])
            ->values()
            ->toArray();

        $analysisPrompt = $this->buildAnalysisPrompt($existingMemories, $conversationMessages);

        $client = $this->clientFactory->forSystem($conversation->aiSystem);

        $response = $client
            ->withSystem('You are a memory management system. You analyze AI conversations and extract reusable insights. Always respond with valid JSON only, no markdown fences or extra text.')
            ->withMaxTokens(4096)
            ->withTemperature(0.2)
            ->message([
                ['role' => 'user', 'content' => $analysisPrompt],
            ]);

        return $this->parseAnalysisResponse($response);
    }

    /**
     * Apply memory operations (add/update/remove) from an analysis result.
     * Memories are scoped to the user who owns them, identified by either user_id or visitor_email.
     */
    public function applyMemoryOperations(string $feature, array $operations, int $conversationId, string|int|null $userId = null, ?string $visitorEmail = null): void
    {
        DB::transaction(function () use ($feature, $operations, $conversationId, $userId, $visitorEmail) {
            foreach ($operations['add'] ?? [] as $entry) {
                AiFeatureMemory::create([
                    'feature' => $feature,
                    'category' => $entry['category'] ?? 'general',
                    'key' => $entry['key'],
                    'content' => $entry['content'],
                    'confidence' => $entry['confidence'] ?? 50,
                    'source_conversation_id' => $conversationId,
                    'user_id' => $userId,
                    'visitor_email' => $visitorEmail,
                    'is_active' => true,
                ]);
            }

            foreach ($operations['update'] ?? [] as $entry) {
                // When updating memories, scope to the current user (by ID or email)
                $updateQuery = AiFeatureMemory::query()
                    ->forFeature($feature)
                    ->where('key', $entry['key'] ?? '')
                    ->active();

                if ($userId !== null) {
                    $updateQuery->where('user_id', $userId);
                } elseif ($visitorEmail !== null) {
                    $updateQuery->where('visitor_email', $visitorEmail);
                }

                $memory = $updateQuery->first();

                if ($memory === null) {
                    continue;
                }

                $updates = ['source_conversation_id' => $conversationId];

                if (isset($entry['content'])) {
                    $updates['content'] = $entry['content'];
                }

                if (isset($entry['confidence'])) {
                    $updates['confidence'] = $entry['confidence'];
                }

                if (!empty($entry['reinforced'])) {
                    $updates['times_reinforced'] = $memory->times_reinforced + 1;
                    $updates['last_reinforced_at'] = now();
                }

                $memory->update($updates);
            }

            // When removing memories, also scope to the current user
            $removeQuery = AiFeatureMemory::query()
                ->forFeature($feature)
                ->active();

            if ($userId !== null) {
                $removeQuery->where('user_id', $userId);
            } elseif ($visitorEmail !== null) {
                $removeQuery->where('visitor_email', $visitorEmail);
            }

            foreach ($operations['remove'] ?? [] as $entry) {
                $removeQuery
                    ->where('key', $entry['key'])
                    ->update(['is_active' => false]);
            }
        });
    }

    /**
     * Orchestrate: analyze a completed conversation and apply the results.
     * Memories are scoped to the user who owns them (identified by user_id or visitor_email).
     */
    public function processCompletedConversation(AiConversation $conversation, string|int|null $userId = null, ?string $visitorEmail = null): void
    {
        try {
            $operations = $this->analyzeConversation($conversation, $userId, $visitorEmail);
            $this->applyMemoryOperations($conversation->feature, $operations, $conversation->id, $userId, $visitorEmail);
        } catch (\Throwable $e) {
            Log::error('AI Memory processing failed', [
                'conversation_id' => $conversation->id,
                'feature' => $conversation->feature,
                'user_id' => $userId,
                'visitor_email' => $visitorEmail,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Rebuild all memories for a feature from scratch.
     * Memories are rebuilt with user scoping (user_id or visitor_email).
     */
    public function rebuildMemories(string $feature): void
    {
        // Deactivate existing memories but don't delete them
        AiFeatureMemory::query()
            ->forFeature($feature)
            ->update(['is_active' => false]);

        $conversations = AiConversation::query()
            ->where('feature', $feature)
            ->where('status', AiConversationStatus::Completed)
            ->orderBy('created_at')
            ->get();

        foreach ($conversations as $conversation) {
            // Pass user identity from conversation for proper scoping
            $this->processCompletedConversation(
                $conversation,
                $conversation->user_id,
                $conversation->visitor_email
            );
        }
    }

    /**
     * Build the analysis prompt for the AI.
     */
    private function buildAnalysisPrompt(array $existingMemories, array $conversationMessages): string
    {
        $memoriesJson = json_encode($existingMemories, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $messagesJson = json_encode($conversationMessages, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return <<<PROMPT
Analyze this completed AI conversation and compare against existing memory entries.

## Existing Memories
{$memoriesJson}

## Conversation Messages
{$messagesJson}

Return a JSON object with three arrays:

{
  "add": [
    {"key": "descriptive-key", "category": "preference|domain_knowledge|system_tuning", "content": "The insight text", "confidence": 1-100}
  ],
  "update": [
    {"key": "existing-key", "content": "updated content if changed", "confidence": 80, "reinforced": true}
  ],
  "remove": [
    {"key": "existing-key", "reason": "why this is no longer valid"}
  ]
}

Rules:
- Only extract genuinely useful, reusable insights — not conversation-specific details
- "preference": how the user likes things done (e.g., "prefers action verbs in bullet points")
- "domain_knowledge": facts about the user's background not already in their resume data
- "system_tuning": what worked well or poorly in this conversation's approach
- If an existing entry is confirmed by this conversation, update it with "reinforced": true
- If an existing entry is contradicted, update its content or remove it
- Be conservative: only high-confidence insights. Use lower confidence when uncertain
- Return empty arrays if no changes are warranted
- Keys should be lowercase-kebab-case, descriptive, and unique
PROMPT;
    }

    /**
     * Parse the AI response into structured operations.
     *
     * @return array{add: array<int, mixed>, update: array<int, mixed>, remove: array<int, mixed>}
     */
    private function parseAnalysisResponse(array $response): array
    {
        $text = '';

        foreach ($response['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'];
            }
        }

        $text = trim($text);

        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $text, $matches)) {
            $text = $matches[1];
        }

        $parsed = json_decode($text, true);

        if (!is_array($parsed)) {
            Log::warning('AI Memory analysis returned unparseable response', ['raw' => $text]);

            return ['add' => [], 'update' => [], 'remove' => []];
        }

        return [
            'add' => $parsed['add'] ?? [],
            'update' => $parsed['update'] ?? [],
            'remove' => $parsed['remove'] ?? [],
        ];
    }
}
