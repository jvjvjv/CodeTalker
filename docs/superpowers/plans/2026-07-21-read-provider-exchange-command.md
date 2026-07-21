# ai:read-exchange Command Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `php artisan ai:read-exchange {ai_llm_message_id?}` command that prints the captured request/response for a provider exchange, with an interactive chatbot → conversation → message drilldown when no id is given.

**Architecture:** A pure `ExchangeTranscriptParser` service turns raw bytes (OpenAI-compatible SSE request/response) and stored `AiLlmMessage.response_data` into readable text. A thin `ReadProviderExchangeCommand` gathers the exchange row(s) for a message id (plus trailing orphan rows), resolves System/Model/ChatBot/Conversation via the exchange's own foreign keys, and renders each block. The interactive drilldown is added in a second command task.

**Tech Stack:** PHP 8.3, Laravel package (Testbench for tests), PHPUnit.

## Global Constraints

- PHP `^8.3`; Laravel `^12.62 || ^13.15`.
- Namespace: `Jvjvjv\CodeTalker`.
- Tests use direct `Model::create(...)` (no in-package factories) and extend `Jvjvjv\CodeTalker\Tests\TestCase`.
- `ai_conversations.uuid` is not created by any package migration; tests that create conversations must add it in `setUp()` (see Task 2).
- `ai_llm_messages.turn_number` is a **string** column; use string values like `'1'`.
- Run tests with `vendor/bin/phpunit`.
- Commit messages end with:
  `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`
- Work happens on branch `feature/read-provider-exchange-command` (already checked out).

---

## File Structure

- Create: `src/Services/RawExchange/ExchangeTranscriptParser.php` — pure parsing, no framework/DB deps.
- Create: `src/Console/Commands/ReadProviderExchangeCommand.php` — command orchestration + output.
- Modify: `src/CodeTalkerServiceProvider.php` — register the command.
- Create: `tests/Feature/ExchangeTranscriptParserTest.php` — parser unit tests.
- Create: `tests/Feature/ReadProviderExchangeCommandTest.php` — command feature tests.

---

## Task 1: ExchangeTranscriptParser service

**Files:**
- Create: `src/Services/RawExchange/ExchangeTranscriptParser.php`
- Test: `tests/Feature/ExchangeTranscriptParserTest.php`

**Interfaces:**
- Produces:
  - `requestText(?string $requestBody): string`
  - `sseResponse(?string $rawResponse): array{text: string, reasoning: string}`
  - `llmResponse(?array $responseData): array{text: string, reasoning: string}`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ExchangeTranscriptParserTest.php`:

```php
<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Jvjvjv\CodeTalker\Services\RawExchange\ExchangeTranscriptParser;
use Jvjvjv\CodeTalker\Tests\TestCase;

class ExchangeTranscriptParserTest extends TestCase
{
    private function parser(): ExchangeTranscriptParser
    {
        return new ExchangeTranscriptParser();
    }

    public function test_request_text_renders_messages_as_role_content_lines(): void
    {
        $body = json_encode([
            'model' => 'qwen',
            'messages' => [
                ['role' => 'system', 'content' => 'You are helpful'],
                ['role' => 'user', 'content' => 'Hi'],
            ],
        ]);

        $this->assertSame(
            "system: You are helpful\n\nuser: Hi",
            $this->parser()->requestText($body),
        );
    }

    public function test_request_text_falls_back_to_raw_when_not_json(): void
    {
        $this->assertSame('not json', $this->parser()->requestText('not json'));
    }

    public function test_request_text_pretty_prints_json_without_messages(): void
    {
        $out = $this->parser()->requestText('{"model":"qwen"}');

        $this->assertStringContainsString('"model": "qwen"', $out);
    }

    public function test_request_text_handles_null(): void
    {
        $this->assertSame('', $this->parser()->requestText(null));
    }

    public function test_sse_response_concatenates_streaming_content_and_reasoning(): void
    {
        $raw = "data: {\"choices\":[{\"delta\":{\"content\":\"Streamed \"}}]}\n\n"
            . "data: {\"choices\":[{\"delta\":{\"content\":\"hi\"}}]}\n\n"
            . "data: {\"choices\":[{\"delta\":{\"reasoning_content\":\"thinking\"}}]}\n\n"
            . "data: [DONE]\n\n";

        $result = $this->parser()->sseResponse($raw);

        $this->assertSame('Streamed hi', $result['text']);
        $this->assertSame('thinking', $result['reasoning']);
    }

    public function test_sse_response_parses_non_streaming_json_body(): void
    {
        $raw = json_encode([
            'choices' => [['message' => ['content' => 'Final answer']]],
        ]);

        $result = $this->parser()->sseResponse($raw);

        $this->assertSame('Final answer', $result['text']);
        $this->assertSame('', $result['reasoning']);
    }

    public function test_sse_response_handles_null(): void
    {
        $this->assertSame(['text' => '', 'reasoning' => ''], $this->parser()->sseResponse(null));
    }

    public function test_llm_response_extracts_text_and_reasoning_deltas(): void
    {
        $data = [
            'events' => [
                ['type' => 'text_delta', 'delta' => 'Hello '],
                ['type' => 'text_delta', 'delta' => 'there'],
                ['type' => 'reasoning_delta', 'delta' => 'hmm'],
                ['type' => 'stream_end'],
            ],
        ];

        $result = $this->parser()->llmResponse($data);

        $this->assertSame('Hello there', $result['text']);
        $this->assertSame('hmm', $result['reasoning']);
    }

    public function test_llm_response_handles_null(): void
    {
        $this->assertSame(['text' => '', 'reasoning' => ''], $this->parser()->llmResponse(null));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/ExchangeTranscriptParserTest.php`
Expected: FAIL — `Class "Jvjvjv\CodeTalker\Services\RawExchange\ExchangeTranscriptParser" not found`.

- [ ] **Step 3: Write minimal implementation**

Create `src/Services/RawExchange/ExchangeTranscriptParser.php`:

```php
<?php

namespace Jvjvjv\CodeTalker\Services\RawExchange;

final class ExchangeTranscriptParser
{
    /**
     * Render a chat-completions request body into readable "role: content" lines.
     */
    public function requestText(?string $requestBody): string
    {
        if ($requestBody === null || $requestBody === '') {
            return '';
        }

        $decoded = json_decode($requestBody, true);

        if (! is_array($decoded)) {
            return $requestBody;
        }

        $messages = $decoded['messages'] ?? null;

        if (! is_array($messages)) {
            return (string) json_encode(
                $decoded,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        }

        $lines = [];

        foreach ($messages as $message) {
            $role = is_array($message) ? ($message['role'] ?? 'unknown') : 'unknown';
            $content = is_array($message) ? ($message['content'] ?? '') : (string) $message;

            if (is_array($content)) {
                $content = (string) json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }

            $lines[] = $role . ': ' . $content;
        }

        return implode("\n\n", $lines);
    }

    /**
     * Parse OpenAI-compatible response bytes (streaming SSE or a single JSON body).
     *
     * @return array{text: string, reasoning: string}
     */
    public function sseResponse(?string $rawResponse): array
    {
        if ($rawResponse === null || trim($rawResponse) === '') {
            return ['text' => '', 'reasoning' => ''];
        }

        $text = '';
        $reasoning = '';
        $sawData = false;

        foreach (preg_split('/\r\n|\r|\n/', $rawResponse) as $line) {
            $line = trim((string) $line);

            if (! str_starts_with($line, 'data:')) {
                continue;
            }

            $sawData = true;
            $payload = trim(substr($line, strlen('data:')));

            if ($payload === '' || $payload === '[DONE]') {
                continue;
            }

            $chunk = json_decode($payload, true);

            if (! is_array($chunk)) {
                continue;
            }

            [$t, $r] = $this->extractFromChoice($chunk);
            $text .= $t;
            $reasoning .= $r;
        }

        if (! $sawData) {
            $chunk = json_decode(trim($rawResponse), true);

            if (is_array($chunk)) {
                [$text, $reasoning] = $this->extractFromChoice($chunk);
            }
        }

        return ['text' => $text, 'reasoning' => $reasoning];
    }

    /**
     * Extract text/reasoning from a stored AiLlmMessage response_data payload.
     *
     * @param  array<string, mixed>|null  $responseData
     * @return array{text: string, reasoning: string}
     */
    public function llmResponse(?array $responseData): array
    {
        $text = '';
        $reasoning = '';

        $events = $responseData['events'] ?? null;

        if (is_array($events)) {
            foreach ($events as $event) {
                if (! is_array($event)) {
                    continue;
                }

                $delta = is_string($event['delta'] ?? null) ? $event['delta'] : '';

                if (($event['type'] ?? null) === 'text_delta') {
                    $text .= $delta;
                } elseif (($event['type'] ?? null) === 'reasoning_delta') {
                    $reasoning .= $delta;
                }
            }
        }

        return ['text' => $text, 'reasoning' => $reasoning];
    }

    /**
     * @param  array<string, mixed>  $chunk
     * @return array{0: string, 1: string}
     */
    private function extractFromChoice(array $chunk): array
    {
        $choice = $chunk['choices'][0] ?? null;

        if (! is_array($choice)) {
            return ['', ''];
        }

        // Streaming chunks carry "delta"; non-streaming responses carry "message".
        $node = $choice['delta'] ?? $choice['message'] ?? [];

        if (! is_array($node)) {
            return ['', ''];
        }

        $text = is_string($node['content'] ?? null) ? $node['content'] : '';
        $reasoning = is_string($node['reasoning_content'] ?? null) ? $node['reasoning_content'] : '';

        return [$text, $reasoning];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/ExchangeTranscriptParserTest.php`
Expected: PASS (9 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Services/RawExchange/ExchangeTranscriptParser.php tests/Feature/ExchangeTranscriptParserTest.php
git commit -m "$(cat <<'EOF'
feat: add ExchangeTranscriptParser for provider exchange text extraction

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: ReadProviderExchangeCommand (id argument path) + registration

**Files:**
- Create: `src/Console/Commands/ReadProviderExchangeCommand.php`
- Modify: `src/CodeTalkerServiceProvider.php` (add `use` import + entry in the `$this->commands([...])` array around line 185-190)
- Test: `tests/Feature/ReadProviderExchangeCommandTest.php`

**Interfaces:**
- Consumes: `ExchangeTranscriptParser::requestText/sseResponse/llmResponse` (Task 1).
- Produces (private methods used again in Task 3):
  - `renderForMessage(int $messageId, ExchangeTranscriptParser $parser): int`
  - `gatherExchanges(int $messageId): \Illuminate\Support\Collection`
  - `trailingOrphans(int $afterId): \Illuminate\Support\Collection`
  - `siblingResponseData(AiProviderExchange $exchange): ?array`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ReadProviderExchangeCommandTest.php`:

```php
<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Jvjvjv\CodeTalker\Models\AiChatBot;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Models\AiLlmMessage;
use Jvjvjv\CodeTalker\Models\AiProviderExchange;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Tests\TestCase;

class ReadProviderExchangeCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // AiConversation::booted() assigns a uuid, but no package migration
        // creates the column (host apps add it themselves).
        if (! Schema::hasColumn('ai_conversations', 'uuid')) {
            Schema::table('ai_conversations', function ($table): void {
                $table->string('uuid')->nullable();
            });
        }
    }

    /**
     * @return array{system: AiSystem, bot: AiChatBot, conversation: AiConversation, request: AiLlmMessage, exchange: AiProviderExchange, orphan: AiProviderExchange}
     */
    private function seedExchange(): array
    {
        $system = AiSystem::create([
            'name' => 'Test System',
            'provider' => 'lm-studio',
            'api_key' => 'none',
            'model' => 'qwen/qwen3-8b',
            'max_tokens' => 1024,
            'is_active' => true,
        ]);

        $bot = AiChatBot::create([
            'ai_system_id' => $system->id,
            'name' => 'Test Bot',
            'slug' => 'test-bot',
            'prompt_template' => 'You are {{bot_name}}.',
            'is_active' => true,
        ]);

        $conversation = AiConversation::create([
            'ai_system_id' => $system->id,
            'ai_chat_bot_id' => $bot->id,
            'feature' => 'chat-bot:test-bot',
            'title' => 'My Conversation',
            'status' => 'active',
        ]);

        $request = AiLlmMessage::create([
            'ai_conversation_id' => $conversation->id,
            'direction' => 'request',
            'turn_number' => '1',
            'request_data' => ['model' => 'qwen'],
        ]);

        AiLlmMessage::create([
            'ai_conversation_id' => $conversation->id,
            'direction' => 'response',
            'turn_number' => '1',
            'request_data' => ['model' => 'qwen'],
            'response_data' => [
                'events' => [
                    ['type' => 'text_delta', 'delta' => 'Hello '],
                    ['type' => 'text_delta', 'delta' => 'there'],
                    ['type' => 'reasoning_delta', 'delta' => 'hmm'],
                ],
                'stop_reason' => 'stop',
            ],
        ]);

        $exchange = AiProviderExchange::create([
            'provider' => 'lm-studio',
            'endpoint' => '/v1/chat/completions',
            'method' => 'POST',
            'streaming' => true,
            'http_status' => 200,
            'request_body' => json_encode([
                'model' => 'qwen',
                'messages' => [
                    ['role' => 'system', 'content' => 'You are helpful'],
                    ['role' => 'user', 'content' => 'Hi'],
                ],
            ]),
            'raw_response' => "data: {\"choices\":[{\"delta\":{\"content\":\"Streamed \"}}]}\n\n"
                . "data: {\"choices\":[{\"delta\":{\"content\":\"hi\"}}]}\n\n"
                . "data: [DONE]\n\n",
            'model' => 'qwen/qwen3-8b',
            'ai_system_id' => $system->id,
            'ai_conversation_id' => $conversation->id,
            'ai_llm_message_id' => $request->id,
        ]);

        $orphan = AiProviderExchange::create([
            'provider' => 'lm-studio',
            'endpoint' => '/v1/chat/completions',
            'method' => 'POST',
            'streaming' => true,
            'http_status' => 200,
            'raw_response' => "data: {\"choices\":[{\"delta\":{\"content\":\"Orphan chunk\"}}]}\n\n"
                . "data: [DONE]\n\n",
            'model' => 'qwen/qwen3-8b',
            'ai_system_id' => null,
            'ai_conversation_id' => null,
            'ai_llm_message_id' => null,
        ]);

        return compact('system', 'bot', 'conversation', 'request', 'exchange', 'orphan');
    }

    public function test_it_renders_the_exchange_for_a_given_message_id(): void
    {
        $data = $this->seedExchange();

        $this->artisan('ai:read-exchange', ['ai_llm_message_id' => $data['request']->id])
            ->expectsOutputToContain('Test System')
            ->expectsOutputToContain('qwen/qwen3-8b')
            ->expectsOutputToContain('Test Bot')
            ->expectsOutputToContain('My Conversation')
            ->expectsOutputToContain('system: You are helpful')
            ->expectsOutputToContain('user: Hi')
            ->expectsOutputToContain('Hello there')   // sibling response text
            ->expectsOutputToContain('Streamed hi')    // raw_response parsed text
            ->expectsOutputToContain('Orphan chunk')   // trailing orphan row
            ->assertExitCode(0);
    }

    public function test_it_reports_when_no_exchange_exists(): void
    {
        $this->artisan('ai:read-exchange', ['ai_llm_message_id' => 999])
            ->assertExitCode(1);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/ReadProviderExchangeCommandTest.php`
Expected: FAIL — command `ai:read-exchange` is not defined (`Command "ai:read-exchange" is not defined`).

- [ ] **Step 3: Write minimal implementation**

Create `src/Console/Commands/ReadProviderExchangeCommand.php`:

```php
<?php

namespace Jvjvjv\CodeTalker\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Models\AiLlmMessage;
use Jvjvjv\CodeTalker\Models\AiProviderExchange;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Services\RawExchange\ExchangeTranscriptParser;

class ReadProviderExchangeCommand extends Command
{
    protected $signature = 'ai:read-exchange
        {ai_llm_message_id? : The AiLlmMessage id whose provider exchange(s) to read}';

    protected $description = 'Read the raw request/response captured for a provider exchange.';

    public function handle(ExchangeTranscriptParser $parser): int
    {
        $id = $this->argument('ai_llm_message_id');

        if ($id === null) {
            $this->error('No ai_llm_message_id given. Provide one as an argument.');

            return self::FAILURE;
        }

        return $this->renderForMessage((int) $id, $parser);
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
        $this->line('<comment>--- Request (text) ---</comment>');
        $this->line($parser->requestText($exchange->request_body) ?: '—');

        $this->newLine();
        $this->line('<comment>--- Response (text, from sibling AiLlmMessage) ---</comment>');
        $this->line($llm['text'] !== '' ? $llm['text'] : '—');

        if ($llm['reasoning'] !== '') {
            $this->newLine();
            $this->line('<comment>--- Response reasoning ---</comment>');
            $this->line($llm['reasoning']);
        }

        $this->newLine();
        $this->line('<comment>--- Raw response (parsed) ---</comment>');
        $this->line($raw['text'] !== '' ? $raw['text'] : '—');

        if ($raw['reasoning'] !== '') {
            $this->newLine();
            $this->line('<comment>--- Raw response reasoning ---</comment>');
            $this->line($raw['reasoning']);
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
```

- [ ] **Step 4: Register the command**

In `src/CodeTalkerServiceProvider.php`, add the import near the other command imports (around line 8):

```php
use Jvjvjv\CodeTalker\Console\Commands\ReadProviderExchangeCommand;
```

And add it to the `$this->commands([...])` array (around lines 185-190), after `CompleteIdleConversationsCommand::class`:

```php
                CompleteIdleConversationsCommand::class,
                ReadProviderExchangeCommand::class,
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/ReadProviderExchangeCommandTest.php`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add src/Console/Commands/ReadProviderExchangeCommand.php src/CodeTalkerServiceProvider.php tests/Feature/ReadProviderExchangeCommandTest.php
git commit -m "$(cat <<'EOF'
feat: add ai:read-exchange command (id argument path)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Interactive drilldown (no argument)

**Files:**
- Modify: `src/Console/Commands/ReadProviderExchangeCommand.php` (change the no-arg branch in `handle()`; add `resolveMessageIdInteractively()`)
- Test: `tests/Feature/ReadProviderExchangeCommandTest.php` (add one interactive test)

**Interfaces:**
- Consumes: `renderForMessage()` and the models from Task 2.
- Produces: `resolveMessageIdInteractively(): ?int`.

- [ ] **Step 1: Write the failing test**

Append this method to `tests/Feature/ReadProviderExchangeCommandTest.php` (reuse `seedExchange()` from Task 2):

```php
    public function test_it_drills_down_to_a_message_when_no_id_is_given(): void
    {
        $data = $this->seedExchange();

        $bot = $data['bot'];
        $conversation = AiConversation::find($data['conversation']->id);
        $request = AiLlmMessage::find($data['request']->id);

        $botLabel = $bot->name . ' (id ' . $bot->id . ')';
        $convLabel = $conversation->title . ' (id ' . $conversation->id . ' · ' . $conversation->created_at . ')';
        $msgLabel = '#' . $request->turn_number . ' ' . $request->direction
            . ' (id ' . $request->id . ' · ' . $request->created_at . ')';

        $this->artisan('ai:read-exchange')
            ->expectsChoice('Select a chat bot', $botLabel, [
                $botLabel,
                '[unassigned conversations]',
            ])
            ->expectsChoice('Select a conversation', $convLabel, [$convLabel])
            ->expectsChoice('Select a message', $msgLabel, [$msgLabel])
            ->expectsOutputToContain('Streamed hi')
            ->assertExitCode(0);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/ReadProviderExchangeCommandTest.php --filter test_it_drills_down_to_a_message_when_no_id_is_given`
Expected: FAIL — the command errors "No ai_llm_message_id given." and exits non-zero (no choice prompts appear).

- [ ] **Step 3: Update `handle()` to drill down when no id is given**

In `src/Console/Commands/ReadProviderExchangeCommand.php`, replace the no-arg branch of `handle()`:

```php
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
```

- [ ] **Step 4: Add the drilldown method**

Add these imports at the top of the file (with the other model imports):

```php
use Jvjvjv\CodeTalker\Models\AiChatBot;
```

Add this method to the class (e.g. after `handle()`):

```php
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
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/ReadProviderExchangeCommandTest.php`
Expected: PASS (3 tests).

- [ ] **Step 6: Run the full suite**

Run: `vendor/bin/phpunit`
Expected: PASS (all tests green, including the pre-existing suite).

- [ ] **Step 7: Commit**

```bash
git add src/Console/Commands/ReadProviderExchangeCommand.php tests/Feature/ReadProviderExchangeCommandTest.php
git commit -m "$(cat <<'EOF'
feat: add interactive drilldown to ai:read-exchange

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

## Self-Review Notes

- **Spec coverage:** command form + optional arg (Task 2/3), drilldown chatbot→conversation→message showing everything (Task 3), `[unassigned]` bucket (Task 3), exchange gathering + trailing-orphan rule (Task 2 `gatherExchanges`/`trailingOrphans`), all display fields incl. sibling-response sourcing (Task 2 `renderExchange`/`siblingResponseData`), SSE + reasoning parsing (Task 1), unit + feature tests (all tasks). ✓
- **Sibling-response linkage** (exchange links the request row) is implemented in `siblingResponseData()` and covered by the Task 2 feature test asserting `Hello there`. ✓
- **Orphan rows** with null system/conversation render `—` for those fields; covered by the `Orphan chunk` assertion. ✓
