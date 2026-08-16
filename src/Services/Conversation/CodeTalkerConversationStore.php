<?php

namespace Jvjvjv\CodeTalker\Services\Conversation;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use LogicException;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Models\AiConversationMessage;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Files\File;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;

/**
 * laravel/ai's conversation store, backed by this package's tables.
 *
 * Bound over the framework default so an agent resumed onto a conversation
 * replays Code Talker's own history — including tool calls, tool results, and
 * attachments, none of which a transcript rebuilt from message text can carry.
 *
 * Two deliberate departures from the framework's store:
 *
 * - Conversation ids are this package's integer keys cast to string. The
 *   contract only ever round-trips the value, so the difference is invisible.
 * - `system` rows are excluded from replayed history. They hold the generated
 *   system prompt, which reaches the agent as instructions rather than as a
 *   turn; replaying one would duplicate it into the transcript. Upstream has no
 *   system role to contend with, so this rule is ours alone.
 */
class CodeTalkerConversationStore implements ConversationStore
{
    public function latestConversationId(string|int $userId): ?string
    {
        $id = AiConversation::query()
            ->where('user_id', $userId)
            ->orderByDesc('updated_at')
            ->value('id');

        return $id !== null ? (string) $id : null;
    }

    /**
     * Not supported: this package's conversations require an AiSystem (and
     * usually an AiChatBot) that the contract gives no way to supply.
     *
     * The framework calls this only when an agent has a participant but no
     * current conversation. The package never takes that path — it always
     * resumes an existing conversation — so reaching here means a host asked an
     * agent to remember a conversation it never opened.
     *
     * @throws LogicException always
     */
    public function storeConversation(string|int|null $userId, string $title): string
    {
        throw new LogicException(
            'Code Talker conversations cannot be created from a conversation store, because they '
            . 'require an AiSystem. Open one with AiChatBotConversationService::startConversation() '
            . '(or create an AiConversation directly), then resume the agent onto it with '
            . 'continue() or withStoredConversation().'
        );
    }

    public function storeUserMessage(string $conversationId, string|int|null $userId, AgentPrompt $prompt): string
    {
        return (string) AiConversationMessage::create([
            'ai_conversation_id' => $conversationId,
            'user_id' => $userId,
            'role' => 'user',
            'agent' => $prompt->agent::class,
            'content' => $prompt->prompt,
            'attachments' => $prompt->attachments->toArray(),
        ])->id;
    }

    public function storeAssistantMessage(
        string $conversationId,
        string|int|null $userId,
        AgentPrompt $prompt,
        AgentResponse $response,
    ): string {
        return (string) AiConversationMessage::create([
            'ai_conversation_id' => $conversationId,
            'user_id' => $userId,
            'role' => 'assistant',
            'agent' => $prompt->agent::class,
            'content' => $response->text,
            'tool_calls' => $response->toolCalls->values()->toArray(),
            'tool_results' => $response->toolResults->values()->toArray(),
            'usage' => $response->usage?->toArray(),
            'metadata' => $response->meta?->toArray(),
        ])->id;
    }

    /**
     * @return Collection<int, Message>
     */
    public function getLatestConversationMessages(string $conversationId, int $limit): Collection
    {
        return AiConversationMessage::query()
            ->where('ai_conversation_id', $conversationId)
            // The system prompt is passed as instructions, not replayed as a turn.
            ->where('role', '!=', 'system')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->flatMap(fn (AiConversationMessage $record): array => $this->toMessages($record));
    }

    /**
     * One stored row can expand into two messages: an assistant turn that called
     * tools becomes the call plus its results.
     *
     * @return array<int, Message>
     */
    private function toMessages(AiConversationMessage $record): array
    {
        $content = (string) $record->content;

        if ($record->role === 'user') {
            $attachments = $this->rehydrateAttachments($record->attachments);

            return $attachments->isNotEmpty()
                ? [new UserMessage($content, $attachments)]
                : [new Message('user', $content)];
        }

        $toolCalls = collect($record->tool_calls ?? [])->values();
        $toolResults = collect($record->tool_results ?? [])->values();

        if ($toolCalls->isEmpty()) {
            // Providers reject an empty assistant turn, which a cut-off
            // reasoning-only turn can leave behind.
            return filled($content) ? [new AssistantMessage($content)] : [];
        }

        if ($toolResults->isEmpty()) {
            return filled($content) ? [new AssistantMessage($content)] : [];
        }

        $messages = [
            new AssistantMessage('', $toolCalls->map(ToolCall::fromArray(...))),
            new ToolResultMessage($toolResults->map(ToolResult::fromArray(...))),
        ];

        if (filled($content)) {
            $messages[] = new AssistantMessage($content);
        }

        return $messages;
    }

    /**
     * @param array<int, mixed>|null $attachments
     * @return Collection<int, File>
     */
    private function rehydrateAttachments(?array $attachments): Collection
    {
        if ($attachments === null || $attachments === []) {
            return collect();
        }

        if (!array_is_list($attachments)) {
            throw new InvalidArgumentException('Stored conversation attachments must be a JSON array.');
        }

        return collect($attachments)
            ->map(static function (mixed $attachment) {
                if (!is_array($attachment)) {
                    throw new InvalidArgumentException('Stored conversation attachment entries must be objects.');
                }

                return File::fromArray($attachment);
            })
            ->filter()
            ->values();
    }
}
