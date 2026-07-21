<?php

namespace Jvjvjv\CodeTalker\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Jvjvjv\CodeTalker\Models\AiChatBot;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Models\AiLlmMessage;
use Jvjvjv\CodeTalker\Models\AiProviderExchange;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Services\RawExchange\ExchangeTranscriptParser;
use Symfony\Component\Console\Formatter\OutputFormatter;

class ReadProviderExchangeCommand extends Command
{
    protected $signature = 'ai:read-exchange
        {ai_llm_message_id? : The AiLlmMessage id whose provider exchange(s) to read}';

    protected $description = 'Read the raw request/response captured for a provider exchange.';

    public function handle(ExchangeTranscriptParser $parser): int
    {
        $id = $this->argument('ai_llm_message_id');

        if ($id === null) {
            $id = $this->resolveMessageIdInteractively();

            if ($id === null) {
                return self::FAILURE;
            }
        }

        return $this->renderForMessage((int) $id, $parser);
    }

    private function resolveMessageIdInteractively(): ?int
    {
        // 1. Chat bot (plus an [unassigned] bucket for null ai_chat_bot_id).
        $bots = AiChatBot::query()->orderBy('name')->get();

        $botLabels = [];
        $botByLabel = [];

        foreach ($bots as $bot) {
            $label = $bot->name . ' (id ' . $bot->id . ')';
            $botLabels[] = $label;
            $botByLabel[$label] = (string) $bot->id;
        }

        $unassignedLabel = '[unassigned conversations]';
        $botLabels[] = $unassignedLabel;
        $botByLabel[$unassignedLabel] = 'unassigned';

        $botKey = $botByLabel[$this->choice('Select a chat bot', $botLabels)];

        // 2. Conversation.
        $conversationsQuery = AiConversation::query()->orderByDesc('created_at');

        if ($botKey === 'unassigned') {
            $conversationsQuery->whereNull('ai_chat_bot_id');
        } else {
            $conversationsQuery->where('ai_chat_bot_id', (int) $botKey);
        }

        $conversations = $conversationsQuery->get();

        if ($conversations->isEmpty()) {
            $this->error('No conversations for that selection.');

            return null;
        }

        $convLabels = [];
        $convByLabel = [];

        foreach ($conversations as $conversation) {
            $label = ($conversation->title ?? '(untitled)')
                . ' (id ' . $conversation->id . ' · ' . $conversation->created_at . ')';
            $convLabels[] = $label;
            $convByLabel[$label] = (int) $conversation->id;
        }

        $conversationId = $convByLabel[$this->choice('Select a conversation', $convLabels)];

        // 3. Message.
        $messages = AiLlmMessage::query()
            ->where('ai_conversation_id', $conversationId)
            ->orderBy('id')
            ->get();

        if ($messages->isEmpty()) {
            $this->error('No LLM messages in that conversation.');

            return null;
        }

        $msgLabels = [];
        $msgByLabel = [];

        foreach ($messages as $message) {
            $label = '#' . $message->turn_number . ' ' . $message->direction
                . ' (id ' . $message->id . ' · ' . $message->created_at . ')';
            $msgLabels[] = $label;
            $msgByLabel[$label] = (int) $message->id;
        }

        return $msgByLabel[$this->choice('Select a message', $msgLabels)];
    }

    private function renderForMessage(int $messageId, ExchangeTranscriptParser $parser): int
    {
        $exchanges = $this->gatherExchanges($messageId);

        if ($exchanges->isEmpty()) {
            $this->error("No provider exchanges found for ai_llm_message_id {$messageId}.");

            return self::FAILURE;
        }

        foreach ($exchanges as $exchange) {
            $this->renderExchange($exchange, $parser);
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, AiProviderExchange>
     */
    private function gatherExchanges(int $messageId): Collection
    {
        $matched = AiProviderExchange::query()
            ->where('ai_llm_message_id', $messageId)
            ->orderBy('id')
            ->get();

        if ($matched->isEmpty()) {
            return $matched;
        }

        return $matched->concat($this->trailingOrphans((int) $matched->last()->id));
    }

    /**
     * Rows recorded without message/conversation linkage that immediately follow
     * the last matched row belong to it. Walk consecutive ids and stop at the
     * first row that carries either linkage.
     *
     * @return Collection<int, AiProviderExchange>
     */
    private function trailingOrphans(int $afterId): Collection
    {
        $orphans = new Collection();
        $cursor = $afterId;

        while (true) {
            $next = AiProviderExchange::query()
                ->where('id', '>', $cursor)
                ->orderBy('id')
                ->first();

            if ($next === null) {
                break;
            }

            if ($next->ai_llm_message_id !== null || $next->ai_conversation_id !== null) {
                break;
            }

            $orphans->push($next);
            $cursor = (int) $next->id;
        }

        return $orphans;
    }

    private function renderExchange(AiProviderExchange $exchange, ExchangeTranscriptParser $parser): void
    {
        $system = $exchange->ai_system_id ? AiSystem::find($exchange->ai_system_id) : null;
        $conversation = $exchange->ai_conversation_id ? AiConversation::find($exchange->ai_conversation_id) : null;
        $bot = $conversation?->aiChatBot;

        $llm = $parser->llmResponse($this->siblingResponseData($exchange));
        $raw = $parser->sseResponse($exchange->raw_response);

        $this->line(str_repeat('=', 72));
        $this->line('<info>Exchange</info> #' . $exchange->id . '  (' . $exchange->created_at . ')');
        $this->line('<info>System:</info>       ' . ($system?->name ?? '—'));
        $this->line('<info>Model:</info>        ' . ($exchange->model ?? '—'));
        $this->line('<info>ChatBot:</info>      ' . ($bot?->name ?? '—'));
        $this->line('<info>Conversation:</info> ' . ($conversation?->title ?? '—'));

        $this->newLine();
        $this->line('<comment>--- User message ---</comment>');
        $userMessage = $parser->latestUserMessage($exchange->request_body);
        foreach (preg_split("/\n/", $userMessage !== '' ? $userMessage : '—') as $line) {
            $this->line(OutputFormatter::escape($line));
        }

        $this->newLine();
        $this->line('<comment>--- Response (text, from sibling AiLlmMessage) ---</comment>');
        foreach (preg_split("/\n/", $llm['text'] !== '' ? $llm['text'] : '—') as $line) {
            $this->line(OutputFormatter::escape($line));
        }

        if ($llm['reasoning'] !== '') {
            $this->newLine();
            $this->line('<comment>--- Response reasoning ---</comment>');
            foreach (preg_split("/\n/", $llm['reasoning']) as $line) {
                $this->line(OutputFormatter::escape($line));
            }
        }

        $this->newLine();
        $this->line('<comment>--- Raw response (parsed) ---</comment>');
        foreach (preg_split("/\n/", $raw['text'] !== '' ? $raw['text'] : '—') as $line) {
            $this->line(OutputFormatter::escape($line));
        }

        if ($raw['reasoning'] !== '') {
            $this->newLine();
            $this->line('<comment>--- Raw response reasoning ---</comment>');
            foreach (preg_split("/\n/", $raw['reasoning']) as $line) {
                $this->line(OutputFormatter::escape($line));
            }
        }

        $this->newLine();
    }

    /**
     * The exchange links to the request AiLlmMessage; response_data lives on the
     * sibling response row (same conversation + turn_number, direction=response).
     *
     * @return array<string, mixed>|null
     */
    private function siblingResponseData(AiProviderExchange $exchange): ?array
    {
        if ($exchange->ai_llm_message_id === null) {
            return null;
        }

        $request = AiLlmMessage::find($exchange->ai_llm_message_id);

        if ($request === null) {
            return null;
        }

        $sibling = AiLlmMessage::query()
            ->where('ai_conversation_id', $request->ai_conversation_id)
            ->where('turn_number', $request->turn_number)
            ->where('direction', 'response')
            ->where('id', '>', $request->id)
            ->orderBy('id')
            ->first();

        return $sibling?->response_data;
    }
}
