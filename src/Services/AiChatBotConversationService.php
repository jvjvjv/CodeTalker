<?php

namespace Jvjvjv\CodeTalker\Services;

use Jvjvjv\CodeTalker\Enums\AiConversationStatus;
use Jvjvjv\CodeTalker\Enums\AiInteractionStatus;
use Jvjvjv\CodeTalker\Jobs\ProcessAiMemoryJob;
use Jvjvjv\CodeTalker\Models\AiChatBot;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Models\AiConversationMessage;
use Jvjvjv\CodeTalker\Models\AiInteractionLog;
use Jvjvjv\CodeTalker\Models\AiLlmMessage;
use Jvjvjv\CodeTalker\Services\Mcp\ChatBotToolRegistry;
use Generator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AiChatBotConversationService
{
    public function __construct(
        private AiClientFactory $clientFactory,
        private AiMemoryService $memoryService,
        private ConversationUsageService $conversationUsageService,
    ) {
    }

    public function startConversation(AiChatBot $bot, mixed $user = null, ?string $visitorName = null, ?string $visitorEmail = null): AiConversation
    {
        $conversation = AiConversation::create([
            'user_id' => $user?->id,
            'ai_system_id' => $bot->ai_system_id,
            'ai_chat_bot_id' => $bot->id,
            'feature' => $bot->featureKey(),
            'title' => null,
            'visitor_name' => $visitorName,
            'visitor_email' => $visitorEmail,
            'status' => AiConversationStatus::Active,
            'context' => [
                'bot_slug' => $bot->slug,
                'bot_name' => $bot->name,
            ],
        ]);

        AiConversationMessage::create([
            'ai_conversation_id' => $conversation->id,
            'role' => 'system',
            'content' => $this->buildSystemPrompt($bot, $visitorName, $visitorEmail),
        ]);

        return $conversation;
    }

    /**
     * Continue a bot conversation by streaming the assistant response.
     *
     * @return Generator<int, string>
     */
    public function continueConversation(AiConversation $conversation, string $userMessage): Generator
    {
        $conversation->loadMissing(['aiSystem', 'aiChatBot', 'messages']);

        AiConversationMessage::create([
            'ai_conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $userMessage,
        ]);

        if (blank($conversation->title)) {
            $conversation->forceFill([
                'title' => $this->titleFromUserMessage($userMessage),
            ])->save();
        }

        $allMessages = $conversation->messages()->orderBy('created_at')->get();
        $systemPrompt = null;
        $apiMessages = [];

        foreach ($allMessages as $message) {
            if ($message->role === 'system') {
                $systemPrompt = $message->content;

                continue;
            }

            $content = $message->content;

            if ($message->role === 'assistant' && trim((string) $content) === '') {
                $content = null;
            }

            $apiMessages[] = [
                'role' => $message->role,
                'content' => $content,
            ];
        }

        $client = $this->clientFactory->forSystem($conversation->aiSystem);

        $turnNumber = $this->getTurnNumberForConversation($conversation);

        $startTime = microtime(true);
        $resolvedModel = $conversation->aiSystem->model;
        $maxTokens = $conversation->aiSystem->max_tokens;
        $resolvedTemperature = $conversation->aiChatBot?->resolvedTemperature();

        $requestPayload = [
            'model' => $resolvedModel,
            'max_tokens' => $maxTokens,
            'messages' => $apiMessages,
        ];

        if ($resolvedTemperature !== null) {
            $requestPayload['temperature'] = $resolvedTemperature;
        }

        if ($systemPrompt !== null) {
            $requestPayload['system'] = $systemPrompt;
        }

        $toolRegistry = $conversation->aiChatBot?->tools_enabled
            ? new ChatBotToolRegistry(
                $conversation,
                $conversation->aiSystem->allowed_tools ?? [],
            )
            : null;
        $availableTools = $toolRegistry?->toApiTools() ?? [];
        $toolRegistry = $availableTools !== [] ? $toolRegistry : null;

        $iterationMessages = $apiMessages;
        $totalInputTokens = 0;
        $totalOutputTokens = 0;
        $blocks = [];
        $durationMs = 0;

        $appendToBlocks = static function (string $type, string $delta) use (&$blocks): void {
            if ($blocks !== [] && $blocks[\count($blocks) - 1]['type'] === $type) {
                $blocks[\count($blocks) - 1]['content'] .= $delta;
            } else {
                $blocks[] = ['type' => $type, 'content' => $delta];
            }
        };

        try {
            yield 'data: ' . json_encode([
                'type' => 'status',
                'phase' => 'model_loading',
                'message' => 'Waiting for model response...',
            ]) . "\n\n";

            for ($iteration = 0; $iteration < 6; $iteration++) {
                if ($systemPrompt !== null) {
                    $client->withSystem($systemPrompt);
                }

                $client->withMaxTokens($maxTokens);

                if ($resolvedTemperature !== null) {
                    $client->withTemperature($resolvedTemperature);
                }

                if ($toolRegistry !== null) {
                    $client->withTools($availableTools);
                }

                $iterationRequestPayload = [
                    'model' => $resolvedModel,
                    'max_tokens' => $maxTokens,
                    'messages' => $iterationMessages,
                ];

                if ($resolvedTemperature !== null) {
                    $iterationRequestPayload['temperature'] = $resolvedTemperature;
                }

                if ($systemPrompt !== null) {
                    $iterationRequestPayload['system'] = $systemPrompt;
                }

                $requestPayload = $iterationRequestPayload;

                $iterationTurnNumber = $iteration === 0 ? (string) $turnNumber : "{$turnNumber}.{$iteration}";

                AiLlmMessage::create([
                    'ai_conversation_id' => $conversation->id,
                    'direction' => 'request',
                    'turn_number' => $iterationTurnNumber,
                    'request_data' => $iterationRequestPayload,
                    'created_at' => now(),
                ]);

                $iterationResponseEvents = [];
                $pendingToolCalls = [];
                $currentToolBlockIndex = null;
                $iterationStopReason = null;
                $iterationInputTokens = null;
                $iterationOutputTokens = null;

                foreach ($client->stream($iterationMessages) as $event) {
                    Log::debug('Chat bot API stream event', [
                        'conversation_id' => $conversation->id,
                        'ai_chat_bot_id' => $conversation->ai_chat_bot_id,
                        'ai_system_id' => $conversation->ai_system_id,
                        'turn_number' => $turnNumber,
                        'iteration' => $iteration,
                        'event_type' => $event['type'] ?? null,
                    ]);

                    if (!isset($event['type'])) {
                        continue;
                    }

                    $iterationResponseEvents[] = $event;

                    switch ($event['type']) {
                        case 'content_block_start':
                            $block = $event['content_block'] ?? $event['block'] ?? [];
                            if (isset($block['type']) && $block['type'] === 'tool_use') {
                                $currentToolBlockIndex = $event['index'] ?? count($pendingToolCalls);
                                $pendingToolCalls[$currentToolBlockIndex] = [
                                    'id' => $block['id'] ?? Str::uuid()->toString(),
                                    'name' => $block['name'] ?? '',
                                    'inputJson' => '',
                                ];
                            } else {
                                yield 'data: ' . json_encode($event) . "\n\n";
                            }
                            break;

                        case 'reasoning_block_delta':
                            if (isset($event['delta']['reasoning'])) {
                                $appendToBlocks('reasoning', $event['delta']['reasoning']);
                            }
                            yield 'data: ' . json_encode($event) . "\n\n";
                            break;

                        case 'content_block_delta':
                            if (isset($event['delta']['text'])) {
                                $appendToBlocks('text', $event['delta']['text']);
                            }

                            if (isset($event['delta']['thinking']) || isset($event['delta']['signature'])) {
                                $appendToBlocks('reasoning', $event['delta']['thinking'] ?? '');
                            }

                            if (
                                isset($event['delta']['type'], $event['delta']['partial_json'])
                                && $event['delta']['type'] === 'input_json_delta'
                                && $currentToolBlockIndex !== null
                                && isset($pendingToolCalls[$currentToolBlockIndex])
                            ) {
                                $pendingToolCalls[$currentToolBlockIndex]['inputJson'] .= $event['delta']['partial_json'];
                            } else {
                                yield 'data: ' . json_encode($event) . "\n\n";
                            }
                            break;

                        case 'content_block_stop':
                            if ($currentToolBlockIndex !== null && ($event['index'] ?? null) === $currentToolBlockIndex) {
                                $currentToolBlockIndex = null;
                            } else {
                                yield 'data: ' . json_encode($event) . "\n\n";
                            }
                            break;

                        case 'message_start':
                            if (isset($event['message']['usage'])) {
                                $iterationInputTokens = $event['message']['usage']['input_tokens'] ?? null;
                            }
                            yield 'data: ' . json_encode($event) . "\n\n";
                            break;

                        case 'message_delta':
                            if (isset($event['usage'])) {
                                $iterationOutputTokens = $event['usage']['output_tokens'] ?? null;
                            }
                            $iterationStopReason = $event['delta']['stop_reason'] ?? $event['stop_reason'] ?? null;
                            yield 'data: ' . json_encode($event) . "\n\n";
                            break;

                        case 'message_stop':
                            yield 'data: ' . json_encode($event) . "\n\n";
                            break;

                        case 'ping':
                            Log::debug('Received ping event in stream');
                            break;

                        default:
                            yield 'data: ' . json_encode($event) . "\n\n";
                    }
                }

                $totalInputTokens += (int) ($iterationInputTokens ?? 0);
                $totalOutputTokens += (int) ($iterationOutputTokens ?? 0);
                $durationMs = (int) ((microtime(true) - $startTime) * 1000);

                AiLlmMessage::create([
                    'ai_conversation_id' => $conversation->id,
                    'direction' => 'response',
                    'turn_number' => $iterationTurnNumber,
                    'request_data' => $iterationRequestPayload,
                    'response_data' => [
                        'events' => $iterationResponseEvents,
                        'stop_reason' => $iterationStopReason,
                        'input_tokens' => $iterationInputTokens,
                        'output_tokens' => $iterationOutputTokens,
                        'model' => $resolvedModel,
                        'tool_calls' => array_values(array_map(
                            static fn (array $tc): array => ['id' => $tc['id'], 'name' => $tc['name']],
                            $pendingToolCalls,
                        )),
                    ],
                    'duration_ms' => $durationMs,
                    'created_at' => now(),
                ]);

                if ($iterationStopReason === 'tool_use' && $toolRegistry !== null && $pendingToolCalls !== []) {
                    $iterationText = trim(collect($blocks)->where('type', 'text')->pluck('content')->implode(''));

                    $formattedToolCalls = [];
                    $toolResults = [];

                    foreach ($pendingToolCalls as $tc) {
                        $toolInput = json_decode($tc['inputJson'], true) ?? [];
                        $formattedToolCalls[] = ['id' => $tc['id'], 'name' => $tc['name'], 'input' => $toolInput];
                        $toolResults[] = ['tool_use_id' => $tc['id'], 'result' => $toolRegistry->dispatch($tc['name'], $toolInput)];
                    }

                    $assistantTurn = $client->formatAssistantToolCallTurn($iterationText, $formattedToolCalls);
                    $resultTurns = $client->formatToolResultTurn($toolResults);

                    $iterationMessages = array_merge($iterationMessages, [$assistantTurn], $resultTurns);

                    continue;
                }

                if ($iterationStopReason === 'max_tokens') {
                    $accumulatedText = collect($blocks)->where('type', 'text')->pluck('content')->implode('');
                    $iterationMessages[] = ['role' => 'assistant', 'content' => $accumulatedText];
                    $iterationMessages[] = ['role' => 'user', 'content' => 'Continue.'];
                    continue;
                }

                break;
            }

            yield "data: [DONE]\n\n";

            $fullResponse = collect($blocks)->where('type', 'text')->pluck('content')->implode('');
            $thinkingContent = collect($blocks)->where('type', 'reasoning')->pluck('content')->implode("\n\n");

            $pricingSnapshot = $this->conversationUsageService->pricingSnapshotForSystem(
                $conversation->aiSystem,
                $conversation->aiSystem->model,
            );

            if ($fullResponse !== '') {
                AiConversationMessage::create([
                    'ai_conversation_id' => $conversation->id,
                    'role' => 'assistant',
                    'content' => $fullResponse,
                    'reasoning_content' => $thinkingContent !== '' ? $thinkingContent : null,
                    'blocks' => $blocks !== [] ? $blocks : null,
                    'metadata' => [
                        'input_tokens' => $totalInputTokens ?: null,
                        'output_tokens' => $totalOutputTokens ?: null,
                        'model' => $conversation->aiSystem->model,
                    ],
                ]);

                ProcessAiMemoryJob::dispatch(
                    $conversation->fresh(),
                    $conversation->user_id,
                    $conversation->visitor_email
                );
            }

            AiInteractionLog::create([
                'ai_system_id' => $conversation->aiSystem->id,
                'ai_conversation_id' => $conversation->id,
                'ai_chat_bot_id' => $conversation->ai_chat_bot_id,
                'user_id' => $conversation->user_id,
                'feature' => $conversation->feature,
                'input_tokens' => $totalInputTokens ?: null,
                'output_tokens' => $totalOutputTokens ?: null,
                'model' => $resolvedModel,
                'input_token_price_snapshot' => $pricingSnapshot['input_token_price_snapshot'],
                'output_token_price_snapshot' => $pricingSnapshot['output_token_price_snapshot'],
                'duration_ms' => $durationMs,
                'status' => AiInteractionStatus::Success,
            ]);

            $this->conversationUsageService->syncConversation($conversation->fresh());
        } catch (\Throwable $exception) {
            AiLlmMessage::create([
                'ai_conversation_id' => $conversation->id,
                'direction' => 'request',
                'turn_number' => (string) $turnNumber,
                'request_data' => $requestPayload + ['error' => $exception->getMessage()],
                'created_at' => now(),
            ]);

            AiInteractionLog::create([
                'ai_system_id' => $conversation->aiSystem->id,
                'ai_conversation_id' => $conversation->id,
                'ai_chat_bot_id' => $conversation->ai_chat_bot_id,
                'user_id' => $conversation->user_id,
                'feature' => $conversation->feature,
                'model' => $resolvedModel ?? $conversation->aiSystem->model,
                'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
                'status' => AiInteractionStatus::Error,
                'error_message' => $exception->getMessage(),
            ]);

            yield 'data: ' . json_encode(['type' => 'error', 'message' => $exception->getMessage()]) . "\n\n";
        }
    }

    private function getTurnNumberForConversation(AiConversation $conversation): int
    {
        $maxTurn = AiLlmMessage::query()
            ->where('ai_conversation_id', $conversation->id)
            ->max('turn_number');

        if ($maxTurn === null || !is_numeric($maxTurn)) {
            return 1;
        }

        return (int) $maxTurn + 1;
    }

    private function buildSystemPrompt(AiChatBot $bot, ?string $visitorName = null, ?string $visitorEmail = null): string
    {
        return $this->buildSystemPromptForBot($bot, null, $visitorName, $visitorEmail);
    }

    private function buildSystemPromptForBot(AiChatBot $bot, ?AiConversation $conversation = null, ?string $visitorName = null, ?string $visitorEmail = null): string
    {
        $replacements = [
            '{{bot_name}}' => $bot->name,
            '{{bot_slug}}' => $bot->slug,
            '{{bot_description}}' => $bot->description ?? '',
            '{{visitor_name}}' => $visitorName ?? '',
            '{{visitor_email}}' => $visitorEmail ?? '',
        ];

        $prompt = strtr($bot->prompt_template, $replacements);
        $systemPrompt = trim((string) $bot->aiSystem?->system_prompt);

        $memoryUserId = null;
        $memoryVisitorEmail = null;

        if ($conversation !== null) {
            $memoryUserId = $conversation->user_id;
            $memoryVisitorEmail = $conversation->visitor_email;
        } elseif (auth()->check()) {
            $memoryUserId = auth()->id();
        }

        $memoryPrompt = trim($this->memoryService->getMemoriesForPrompt(
            $bot->featureKey(),
            $memoryUserId,
            $memoryVisitorEmail
        ));

        return collect([
            $systemPrompt !== '' ? $systemPrompt : null,
            $prompt,
            $memoryPrompt !== '' ? "## Learned Insights\n{$memoryPrompt}" : null,
        ])->filter()->implode("\n\n");
    }

    private function titleFromUserMessage(string $userMessage): string
    {
        $normalized = Str::of(strip_tags($userMessage))
            ->squish()
            ->trim();

        if ($normalized->isEmpty()) {
            return 'New chat';
        }

        return Str::limit($normalized->toString(), 80, '...');
    }
}
