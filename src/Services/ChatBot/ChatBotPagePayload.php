<?php

namespace Jvjvjv\CodeTalker\Services\ChatBot;

use Jvjvjv\CodeTalker\Models\AiChatBot;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Models\AiConversationMessage;

/**
 * The props behind the `ai/ChatBot` page.
 *
 * Both entry points — the session-backed page and a hash link — render the same
 * component, but they differ in two ways the caller decides: whether the chat
 * hash is exposed, and how the visitor identity form is triggered. Those stay
 * parameters rather than being inferred here.
 */
class ChatBotPagePayload
{
    public function __construct(
        private ChatBotRouteUrls $urls,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $history
     * @return array<string, mixed>
     */
    public function build(
        AiChatBot $aiChatBot,
        ?AiConversation $conversation,
        array $history,
        bool $showIdentityForm,
        bool $includeChatHash = false,
    ): array {
        $payload = [
            'bot' => [
                'name' => $aiChatBot->name,
                'description' => $aiChatBot->description,
                'require_visitor_identity' => $aiChatBot->require_visitor_identity,
                'total_cost_usd' => (float) (AiConversation::query()
                    ->where('ai_chat_bot_id', $aiChatBot->id)
                    ->sum('usage_cost_usd') ?? 0),
            ],
            'messages' => $this->messages($conversation),
            'history' => $history,
            'messageUrl' => $this->urls->for($aiChatBot, 'message'),
            'resetUrl' => $this->urls->for($aiChatBot, 'reset'),
            'switchUrl' => $this->urls->for($aiChatBot, 'switch'),
            'statusUrl' => $this->urls->for($aiChatBot, 'status'),
            'warmupUrl' => $this->urls->for($aiChatBot, 'warmup'),
            'chatUrl' => $this->urls->chatUrlFor($aiChatBot, $conversation?->chat_hash),
            'chatUrlBase' => $this->urls->chatUrlBase($aiChatBot),
            'showIdentityForm' => $showIdentityForm,
        ];

        if ($includeChatHash) {
            $payload['chatHash'] = $conversation?->chat_hash;
        }

        return $payload;
    }

    /**
     * The visible transcript: everything but the system prompt.
     *
     * @return array<int, array{role: string, content: ?string, reasoning_content: ?string, blocks: ?array}>
     */
    private function messages(?AiConversation $conversation): array
    {
        if ($conversation === null) {
            return [];
        }

        return $conversation->messages()
            ->where('role', '!=', 'system')
            ->orderBy('created_at')
            ->get()
            ->map(fn (AiConversationMessage $message) => [
                'role' => $message->role,
                'content' => $message->content,
                'reasoning_content' => $message->reasoning_content,
                'blocks' => $message->blocks,
            ])
            ->all();
    }
}
