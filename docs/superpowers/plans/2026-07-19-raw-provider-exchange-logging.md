# Raw Provider Exchange Logging Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Capture every byte of every laravel/ai HTTP request/response into a new `ai_provider_exchanges` table via a Laravel `Http` global middleware that tees the response stream, defaulting to LM Studio traffic with a 14-day prune.

**Architecture:** A global Guzzle middleware (registered once through `Http::globalMiddleware`) inspects a request-scoped `RawExchangeContext` frame that the package's own services open around each agent call. When a frame is active, the provider is allow-listed, and the request host matches the frame's provider base URL, the middleware records the request body and tees the response body — reading the whole buffered body for non-streaming calls, or wrapping the stream in a `TeeingStream` decorator that flushes once on EOF/close for streaming calls — writing one `AiProviderExchange` row per exchange. Nothing in the existing chat/memory logic changes; capture is purely additive and never throws into the request path.

**Tech Stack:** PHP 8.3, Laravel ^12.62 || ^13.15, `laravel/ai`, GuzzleHttp PSR-7, PHPUnit + Orchestra Testbench.

## Global Constraints

- Namespace root: `Jvjvjv\CodeTalker`.
- PHP `^8.3`; Laravel `^12.62 || ^13.15`.
- This is a Laravel **package**, not an app — models resolve factories from the host app; package tests create models directly via `::create()`.
- The `StreamTranslator` browser SSE wire format is a compatibility surface — **do not change it**.
- API keys live in the `Authorization` HTTP header — **never read or store request headers**; only JSON bodies.
- Capture must **never** break a chat turn: all record writes are wrapped in `try/catch` that logs a warning and swallows.
- Config keys and env var names are exact: `code-talker.raw_exchanges.enabled` (`CODE_TALKER_RAW_EXCHANGES_ENABLED`, default `true`), `code-talker.raw_exchanges.providers` (`CODE_TALKER_RAW_EXCHANGES_PROVIDERS`, default `lm-studio`), `code-talker.raw_exchanges.retention_days` (`CODE_TALKER_RAW_EXCHANGES_RETENTION_DAYS`, default `14`).
- Provider values are `AiProvider` enum string values (e.g. `lm-studio`), matched against `AiSystem::$provider` — **not** the laravel/ai driver name.
- Run the test suite with `vendor/bin/phpunit`.

---

## File Structure

- Create `database/migrations/2026_07_19_000100_create_ai_provider_exchanges_table.php` — the table.
- Create `src/Models/AiProviderExchange.php` — the Eloquent model.
- Create `src/Services/RawExchange/RawExchangeFrame.php` — immutable correlation frame + `forSystem()` factory + host/port parsing.
- Create `src/Services/RawExchange/RawExchangeContext.php` — request-scoped stack of active frames.
- Create `src/Services/RawExchange/TeeingStream.php` — PSR-7 stream decorator that buffers reads and flushes once.
- Create `src/Services/RawExchange/RawExchangeRecorder.php` — global middleware registration + capture predicate + row writer + `capture()` wrapper.
- Create `src/Console/Commands/PruneProviderExchangesCommand.php` — retention prune.
- Modify `src/Services/LaravelAi/AiSystemProviderConfigurator.php` — add public `baseUrlFor()`.
- Modify `config/code-talker.php` — add `raw_exchanges` block.
- Modify `src/CodeTalkerServiceProvider.php` — bind singletons, register middleware, register + schedule prune command.
- Modify `src/Services/AiChatBotConversationService.php` — open a frame around the streaming loop.
- Modify `src/Services/AiMemoryService.php` — wrap the analysis call in `capture()`.
- Modify `README.md` — document the config block.
- Tests under `tests/Feature/` and `tests/Unit/` (create `tests/Unit/` if absent) as specified per task.

---

## Task 1: `ai_provider_exchanges` table + model

**Files:**
- Create: `database/migrations/2026_07_19_000100_create_ai_provider_exchanges_table.php`
- Create: `src/Models/AiProviderExchange.php`
- Test: `tests/Feature/AiProviderExchangeModelTest.php`

**Interfaces:**
- Produces: `Jvjvjv\CodeTalker\Models\AiProviderExchange` with fillable `provider, endpoint, method, streaming, http_status, request_body, raw_response, model, duration_ms, ai_system_id, ai_conversation_id, ai_llm_message_id, created_at`; casts `streaming=>bool`, `http_status=>int`, `duration_ms=>int`, `created_at=>datetime`; `$timestamps = false`; auto-sets `created_at` on create.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Jvjvjv\CodeTalker\Models\AiProviderExchange;
use Jvjvjv\CodeTalker\Tests\TestCase;

class AiProviderExchangeModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_and_casts_an_exchange_row(): void
    {
        $exchange = AiProviderExchange::create([
            'provider' => 'lm-studio',
            'endpoint' => '/v1/chat/completions',
            'method' => 'POST',
            'streaming' => true,
            'http_status' => 200,
            'request_body' => '{"model":"qwen"}',
            'raw_response' => "data: {\"x\":1}\n\ndata: [DONE]",
            'model' => 'qwen/qwen3.5-9b',
            'duration_ms' => 1234,
            'ai_system_id' => null,
            'ai_conversation_id' => null,
            'ai_llm_message_id' => null,
        ]);

        $fresh = $exchange->fresh();

        $this->assertTrue($fresh->streaming);
        $this->assertSame(200, $fresh->http_status);
        $this->assertSame(1234, $fresh->duration_ms);
        $this->assertSame("data: {\"x\":1}\n\ndata: [DONE]", $fresh->raw_response);
        $this->assertNotNull($fresh->created_at);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/AiProviderExchangeModelTest.php`
Expected: FAIL — `Class "Jvjvjv\CodeTalker\Models\AiProviderExchange" not found`.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_07_19_000100_create_ai_provider_exchanges_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_provider_exchanges', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('endpoint');
            $table->string('method', 16);
            $table->boolean('streaming')->default(false);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->longText('request_body')->nullable();
            $table->longText('raw_response')->nullable();
            $table->string('model')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedBigInteger('ai_system_id')->nullable();
            $table->unsignedBigInteger('ai_conversation_id')->nullable();
            $table->unsignedBigInteger('ai_llm_message_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index('provider');
            $table->index('ai_conversation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_provider_exchanges');
    }
};
```

> Note: FK columns are plain nullable integers (not `foreignId()->constrained()`), matching the "nullable, context-optional" design and avoiding cascade coupling to tables the exchange may not reference.

- [ ] **Step 4: Write the model**

Create `src/Models/AiProviderExchange.php`:

```php
<?php

namespace Jvjvjv\CodeTalker\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class AiProviderExchange extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'provider',
        'endpoint',
        'method',
        'streaming',
        'http_status',
        'request_body',
        'raw_response',
        'model',
        'duration_ms',
        'ai_system_id',
        'ai_conversation_id',
        'ai_llm_message_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'streaming' => 'boolean',
            'http_status' => 'integer',
            'duration_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $exchange): void {
            if ($exchange->created_at === null) {
                $exchange->created_at = Carbon::now();
            }
        });
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/AiProviderExchangeModelTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_19_000100_create_ai_provider_exchanges_table.php src/Models/AiProviderExchange.php tests/Feature/AiProviderExchangeModelTest.php
git commit -m "feat: add ai_provider_exchanges table and model"
```

---

## Task 2: Config block

**Files:**
- Modify: `config/code-talker.php` (add block after the `providers` array, before the closing `];`)
- Test: `tests/Feature/RawExchangesConfigTest.php`

**Interfaces:**
- Produces: config keys `code-talker.raw_exchanges.enabled` (bool, default true), `.providers` (string, default `'lm-studio'`), `.retention_days` (int, default 14).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Jvjvjv\CodeTalker\Tests\TestCase;

class RawExchangesConfigTest extends TestCase
{
    public function test_raw_exchanges_defaults_are_present(): void
    {
        $this->assertTrue(config('code-talker.raw_exchanges.enabled'));
        $this->assertSame('lm-studio', config('code-talker.raw_exchanges.providers'));
        $this->assertSame(14, config('code-talker.raw_exchanges.retention_days'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/RawExchangesConfigTest.php`
Expected: FAIL — `config('code-talker.raw_exchanges.enabled')` is null, not true.

- [ ] **Step 3: Add the config block**

In `config/code-talker.php`, insert this block immediately before the final `];` (after the `'providers' => [ ... ],` array closes):

```php
    /*
    |--------------------------------------------------------------------------
    | Raw Provider Exchange Logging
    |--------------------------------------------------------------------------
    |
    | Captures the verbatim request and response bytes of every laravel/ai
    | HTTP call into the ai_provider_exchanges table. `providers` is a comma-
    | separated allow-list of AiSystem provider values (or "all"); only those
    | providers are captured. Rows older than `retention_days` are removed by
    | the ai:prune-provider-exchanges command (scheduled daily).
    |
    */

    'raw_exchanges' => [
        'enabled' => env('CODE_TALKER_RAW_EXCHANGES_ENABLED', true),
        'providers' => env('CODE_TALKER_RAW_EXCHANGES_PROVIDERS', 'lm-studio'),
        'retention_days' => (int) env('CODE_TALKER_RAW_EXCHANGES_RETENTION_DAYS', 14),
    ],
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/RawExchangesConfigTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add config/code-talker.php tests/Feature/RawExchangesConfigTest.php
git commit -m "feat: add raw_exchanges config block"
```

---

## Task 3: `RawExchangeFrame` DTO

**Files:**
- Create: `src/Services/RawExchange/RawExchangeFrame.php`
- Test: `tests/Feature/RawExchangeFrameTest.php`

**Interfaces:**
- Consumes: `AiSystem`, `AiSystemProviderConfigurator::baseUrlFor()` (Task 6 — the test below stubs it via a real lm-studio `AiSystem`, which resolves a base URL without that method needing Task 6; but `forSystem()` calls `$configurator->baseUrlFor($system)`, so Task 6 must be merged-in or exist. This task depends on Task 6.).
- Produces: `RawExchangeFrame` with readonly public props `provider (string)`, `baseUrl (?string)`, `aiSystemId (?int)`, `aiConversationId (?int)`, `aiLlmMessageId (?int)`, `model (?string)`; static `forSystem(AiSystem $system, AiSystemProviderConfigurator $configurator, ?int $aiConversationId = null, ?int $aiLlmMessageId = null): self`; instance `host(): ?string`, `port(): ?int`.

> **Ordering note:** Do Task 6 before this task (or as its first step) because `forSystem()` calls `baseUrlFor()`. The steps below assume `baseUrlFor()` already exists.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Services\LaravelAi\AiSystemProviderConfigurator;
use Jvjvjv\CodeTalker\Services\RawExchange\RawExchangeFrame;
use Jvjvjv\CodeTalker\Tests\TestCase;

class RawExchangeFrameTest extends TestCase
{
    use RefreshDatabase;

    public function test_for_system_builds_a_frame_for_an_lm_studio_system(): void
    {
        $system = AiSystem::create([
            'name' => 'Local',
            'provider' => 'lm-studio',
            'model' => 'qwen/qwen3.5-9b',
            'base_url' => 'http://localhost:1234',
            'max_tokens' => 1024,
            'is_active' => true,
        ]);

        $frame = RawExchangeFrame::forSystem(
            $system,
            $this->app->make(AiSystemProviderConfigurator::class),
            aiConversationId: 42,
            aiLlmMessageId: 7,
        );

        $this->assertSame('lm-studio', $frame->provider);
        $this->assertSame('http://localhost:1234/v1', $frame->baseUrl);
        $this->assertSame('qwen/qwen3.5-9b', $frame->model);
        $this->assertSame($system->id, $frame->aiSystemId);
        $this->assertSame(42, $frame->aiConversationId);
        $this->assertSame(7, $frame->aiLlmMessageId);
        $this->assertSame('localhost', $frame->host());
        $this->assertSame(1234, $frame->port());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/RawExchangeFrameTest.php`
Expected: FAIL — class `RawExchangeFrame` not found.

- [ ] **Step 3: Write the DTO**

Create `src/Services/RawExchange/RawExchangeFrame.php`:

```php
<?php

namespace Jvjvjv\CodeTalker\Services\RawExchange;

use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Services\LaravelAi\AiSystemProviderConfigurator;

final class RawExchangeFrame
{
    public function __construct(
        public readonly string $provider,
        public readonly ?string $baseUrl = null,
        public readonly ?int $aiSystemId = null,
        public readonly ?int $aiConversationId = null,
        public readonly ?int $aiLlmMessageId = null,
        public readonly ?string $model = null,
    ) {
    }

    public static function forSystem(
        AiSystem $system,
        AiSystemProviderConfigurator $configurator,
        ?int $aiConversationId = null,
        ?int $aiLlmMessageId = null,
    ): self {
        return new self(
            provider: $system->provider,
            baseUrl: $configurator->baseUrlFor($system),
            aiSystemId: $system->id,
            aiConversationId: $aiConversationId,
            aiLlmMessageId: $aiLlmMessageId,
            model: $system->model,
        );
    }

    public function host(): ?string
    {
        if ($this->baseUrl === null) {
            return null;
        }

        $host = parse_url($this->baseUrl, PHP_URL_HOST);

        return $host !== false && $host !== null ? $host : null;
    }

    public function port(): ?int
    {
        if ($this->baseUrl === null) {
            return null;
        }

        $port = parse_url($this->baseUrl, PHP_URL_PORT);

        return is_int($port) ? $port : null;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/RawExchangeFrameTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Services/RawExchange/RawExchangeFrame.php tests/Feature/RawExchangeFrameTest.php
git commit -m "feat: add RawExchangeFrame correlation DTO"
```

---

## Task 4: `RawExchangeContext` stack

**Files:**
- Create: `src/Services/RawExchange/RawExchangeContext.php`
- Test: `tests/Feature/RawExchangeContextTest.php`

**Interfaces:**
- Consumes: `RawExchangeFrame`.
- Produces: `RawExchangeContext` with `push(RawExchangeFrame): void`, `pop(): ?RawExchangeFrame`, `current(): ?RawExchangeFrame`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Jvjvjv\CodeTalker\Services\RawExchange\RawExchangeContext;
use Jvjvjv\CodeTalker\Services\RawExchange\RawExchangeFrame;
use Jvjvjv\CodeTalker\Tests\TestCase;

class RawExchangeContextTest extends TestCase
{
    public function test_it_tracks_a_stack_of_frames(): void
    {
        $context = new RawExchangeContext();
        $this->assertNull($context->current());

        $a = new RawExchangeFrame('lm-studio', 'http://localhost:1234/v1');
        $b = new RawExchangeFrame('anthropic', 'https://api.anthropic.com/v1');

        $context->push($a);
        $this->assertSame($a, $context->current());

        $context->push($b);
        $this->assertSame($b, $context->current());

        $this->assertSame($b, $context->pop());
        $this->assertSame($a, $context->current());

        $this->assertSame($a, $context->pop());
        $this->assertNull($context->current());
        $this->assertNull($context->pop());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/RawExchangeContextTest.php`
Expected: FAIL — class `RawExchangeContext` not found.

- [ ] **Step 3: Write the context**

Create `src/Services/RawExchange/RawExchangeContext.php`:

```php
<?php

namespace Jvjvjv\CodeTalker\Services\RawExchange;

class RawExchangeContext
{
    /** @var array<int, RawExchangeFrame> */
    private array $stack = [];

    public function push(RawExchangeFrame $frame): void
    {
        $this->stack[] = $frame;
    }

    public function pop(): ?RawExchangeFrame
    {
        return array_pop($this->stack);
    }

    public function current(): ?RawExchangeFrame
    {
        if ($this->stack === []) {
            return null;
        }

        return $this->stack[array_key_last($this->stack)];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/RawExchangeContextTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Services/RawExchange/RawExchangeContext.php tests/Feature/RawExchangeContextTest.php
git commit -m "feat: add RawExchangeContext frame stack"
```

---

## Task 5: `TeeingStream` decorator

**Files:**
- Create: `src/Services/RawExchange/TeeingStream.php`
- Test: `tests/Feature/TeeingStreamTest.php`

**Interfaces:**
- Consumes: `GuzzleHttp\Psr7\Utils::streamFor`, `GuzzleHttp\Psr7\StreamDecoratorTrait`.
- Produces: `TeeingStream implements Psr\Http\Message\StreamInterface`, constructor `(StreamInterface $stream, Closure $onFlush)` where `$onFlush` receives the full buffered string exactly once (on EOF or on `close()`).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use GuzzleHttp\Psr7\Utils;
use Jvjvjv\CodeTalker\Services\RawExchange\TeeingStream;
use Jvjvjv\CodeTalker\Tests\TestCase;

class TeeingStreamTest extends TestCase
{
    public function test_it_passes_bytes_through_and_flushes_once_on_eof(): void
    {
        $flushed = [];
        $tee = new TeeingStream(
            Utils::streamFor('hello world'),
            function (string $bytes) use (&$flushed): void {
                $flushed[] = $bytes;
            },
        );

        $read = '';
        while (! $tee->eof()) {
            $read .= $tee->read(4);
        }

        $this->assertSame('hello world', $read);
        $this->assertSame(['hello world'], $flushed);
    }

    public function test_it_flushes_on_close_without_reaching_eof(): void
    {
        $flushed = [];
        $tee = new TeeingStream(
            Utils::streamFor('data: {}\n\ndata: [DONE]'),
            function (string $bytes) use (&$flushed): void {
                $flushed[] = $bytes;
            },
        );

        // Read only part of the stream, then close (mirrors the SSE parser
        // returning at [DONE] before the inner stream reports EOF).
        $tee->read(6);
        $tee->close();

        $this->assertCount(1, $flushed);
        $this->assertSame('data: ', $flushed[0]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/TeeingStreamTest.php`
Expected: FAIL — class `TeeingStream` not found.

- [ ] **Step 3: Write the decorator**

Create `src/Services/RawExchange/TeeingStream.php`:

```php
<?php

namespace Jvjvjv\CodeTalker\Services\RawExchange;

use Closure;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;

/**
 * Wraps a response body stream, buffering every byte read by the consumer and
 * flushing the buffer to a callback exactly once — on EOF or on close/destruct.
 *
 * Both triggers are required: laravel/ai's SSE parser returns at `[DONE]` and
 * may not read the inner stream to true EOF, so close() is the backstop.
 */
class TeeingStream implements StreamInterface
{
    use StreamDecoratorTrait;

    private string $buffer = '';

    private bool $flushed = false;

    /** @param Closure(string): void $onFlush */
    public function __construct(
        private StreamInterface $stream,
        private Closure $onFlush,
    ) {
    }

    public function read($length): string
    {
        $data = $this->stream->read($length);

        if ($data !== '') {
            $this->buffer .= $data;
        }

        if ($this->stream->eof()) {
            $this->flush();
        }

        return $data;
    }

    public function close(): void
    {
        $this->flush();
        $this->stream->close();
    }

    public function __destruct()
    {
        $this->flush();
    }

    private function flush(): void
    {
        if ($this->flushed) {
            return;
        }

        $this->flushed = true;

        ($this->onFlush)($this->buffer);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/TeeingStreamTest.php`
Expected: PASS (both methods).

- [ ] **Step 5: Commit**

```bash
git add src/Services/RawExchange/TeeingStream.php tests/Feature/TeeingStreamTest.php
git commit -m "feat: add TeeingStream response-body decorator"
```

---

## Task 6: `AiSystemProviderConfigurator::baseUrlFor()`

**Files:**
- Modify: `src/Services/LaravelAi/AiSystemProviderConfigurator.php`
- Test: `tests/Feature/AiSystemProviderConfiguratorTest.php` (append a method; file already exists)

**Interfaces:**
- Produces: `AiSystemProviderConfigurator::baseUrlFor(AiSystem $system): ?string` — returns the resolved provider base URL (same value written into the laravel/ai provider config), or null for unknown providers.

> Do this task **before** Task 3 (RawExchangeFrame depends on it).

- [ ] **Step 1: Write the failing test**

Append this method to the existing `AiSystemProviderConfiguratorTest` class:

```php
    public function test_base_url_for_returns_the_resolved_lm_studio_url(): void
    {
        $system = \Jvjvjv\CodeTalker\Models\AiSystem::create([
            'name' => 'Local',
            'provider' => 'lm-studio',
            'model' => 'qwen/qwen3.5-9b',
            'base_url' => 'http://localhost:1234',
            'max_tokens' => 1024,
            'is_active' => true,
        ]);

        $configurator = $this->app->make(\Jvjvjv\CodeTalker\Services\LaravelAi\AiSystemProviderConfigurator::class);

        $this->assertSame('http://localhost:1234/v1', $configurator->baseUrlFor($system));
    }
```

If `AiSystemProviderConfiguratorTest` does not already use `RefreshDatabase`, add `use Illuminate\Foundation\Testing\RefreshDatabase;` and the `use RefreshDatabase;` trait to the class (needed for `AiSystem::create`).

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/AiSystemProviderConfiguratorTest.php --filter test_base_url_for_returns_the_resolved_lm_studio_url`
Expected: FAIL — `Call to undefined method ...::baseUrlFor()`.

- [ ] **Step 3: Add the public method**

In `src/Services/LaravelAi/AiSystemProviderConfigurator.php`, add this method (after `providerFor()`, before `buildProviderConfig()`):

```php
    /**
     * The resolved provider base URL for a system (host used for exchange-capture host matching).
     */
    public function baseUrlFor(AiSystem $system): ?string
    {
        $provider = AiProvider::tryFrom($system->provider);

        if ($provider === null) {
            return null;
        }

        return $this->resolveUrl($provider, $system);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/AiSystemProviderConfiguratorTest.php --filter test_base_url_for_returns_the_resolved_lm_studio_url`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Services/LaravelAi/AiSystemProviderConfigurator.php tests/Feature/AiSystemProviderConfiguratorTest.php
git commit -m "feat: expose AiSystemProviderConfigurator::baseUrlFor"
```

---

## Task 7: `RawExchangeRecorder` + provider wiring (core capture)

**Files:**
- Create: `src/Services/RawExchange/RawExchangeRecorder.php`
- Modify: `src/CodeTalkerServiceProvider.php` (bind singletons in `register()`; register middleware in `boot()`)
- Test: `tests/Feature/RawExchangeRecorderTest.php`

**Interfaces:**
- Consumes: `RawExchangeContext`, `RawExchangeFrame`, `TeeingStream`, `AiProviderExchange`, `Illuminate\Support\Facades\Http::globalMiddleware`, `GuzzleHttp\Psr7\Utils`.
- Produces: `RawExchangeRecorder` with `register(): void`, `capture(RawExchangeFrame $frame, Closure $callback): mixed`, and (public for testing) `middleware(): Closure`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Jvjvjv\CodeTalker\Models\AiProviderExchange;
use Jvjvjv\CodeTalker\Services\RawExchange\RawExchangeContext;
use Jvjvjv\CodeTalker\Services\RawExchange\RawExchangeFrame;
use Jvjvjv\CodeTalker\Tests\TestCase;

class RawExchangeRecorderTest extends TestCase
{
    use RefreshDatabase;

    private function pushFrame(string $provider = 'lm-studio', ?string $baseUrl = 'http://localhost:1234/v1'): void
    {
        $this->app->make(RawExchangeContext::class)->push(
            new RawExchangeFrame(
                provider: $provider,
                baseUrl: $baseUrl,
                aiSystemId: 3,
                aiConversationId: 9,
                aiLlmMessageId: 5,
                model: 'qwen/qwen3.5-9b',
            ),
        );
    }

    public function test_it_captures_a_non_streaming_exchange(): void
    {
        $this->pushFrame();
        $body = '{"choices":[{"message":{"content":"hi"}}],"usage":{"prompt_tokens":1}}';
        Http::fake(['http://localhost:1234/v1/chat/completions' => Http::response($body, 200)]);

        $response = Http::post('http://localhost:1234/v1/chat/completions', ['model' => 'qwen']);
        $this->assertSame('hi', $response->json('choices.0.message.content'));

        $exchange = AiProviderExchange::first();
        $this->assertNotNull($exchange);
        $this->assertSame('lm-studio', $exchange->provider);
        $this->assertSame('/v1/chat/completions', $exchange->endpoint);
        $this->assertFalse($exchange->streaming);
        $this->assertSame(200, $exchange->http_status);
        $this->assertSame($body, $exchange->raw_response);
        $this->assertStringContainsString('"model":"qwen"', $exchange->request_body);
        $this->assertSame(9, $exchange->ai_conversation_id);
        $this->assertSame(5, $exchange->ai_llm_message_id);
    }

    public function test_it_captures_a_streaming_exchange_verbatim(): void
    {
        $this->pushFrame();
        $sse = "data: {\"choices\":[{\"delta\":{\"content\":\"Hel\"}}]}\n\n"
            . "data: {\"choices\":[{\"delta\":{\"content\":\"lo\"}}]}\n\n"
            . "data: [DONE]\n\n";
        Http::fake(['http://localhost:1234/v1/chat/completions' => Http::response($sse, 200)]);

        $response = Http::withOptions(['stream' => true])
            ->post('http://localhost:1234/v1/chat/completions', ['model' => 'qwen', 'stream' => true]);

        // Consume the body fully to drive the tee to EOF.
        $consumed = (string) $response->toPsrResponse()->getBody();
        $this->assertSame($sse, $consumed);

        $exchange = AiProviderExchange::first();
        $this->assertNotNull($exchange);
        $this->assertTrue($exchange->streaming);
        $this->assertSame($sse, $exchange->raw_response);
    }

    public function test_it_skips_providers_not_in_the_allow_list(): void
    {
        config()->set('code-talker.raw_exchanges.providers', 'anthropic');
        $this->pushFrame(); // lm-studio frame
        Http::fake(['http://localhost:1234/v1/chat/completions' => Http::response('{}', 200)]);

        Http::post('http://localhost:1234/v1/chat/completions', ['model' => 'qwen']);

        $this->assertSame(0, AiProviderExchange::count());
    }

    public function test_it_skips_when_disabled(): void
    {
        config()->set('code-talker.raw_exchanges.enabled', false);
        $this->pushFrame();
        Http::fake(['http://localhost:1234/v1/chat/completions' => Http::response('{}', 200)]);

        Http::post('http://localhost:1234/v1/chat/completions', ['model' => 'qwen']);

        $this->assertSame(0, AiProviderExchange::count());
    }

    public function test_it_skips_when_no_frame_is_active(): void
    {
        Http::fake(['http://localhost:1234/v1/chat/completions' => Http::response('{}', 200)]);

        Http::post('http://localhost:1234/v1/chat/completions', ['model' => 'qwen']);

        $this->assertSame(0, AiProviderExchange::count());
    }

    public function test_it_skips_when_request_host_does_not_match_the_frame(): void
    {
        $this->pushFrame(baseUrl: 'http://localhost:1234/v1');
        Http::fake(['https://api.anthropic.com/*' => Http::response('{}', 200)]);

        Http::post('https://api.anthropic.com/v1/messages', ['model' => 'x']);

        $this->assertSame(0, AiProviderExchange::count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/RawExchangeRecorderTest.php`
Expected: FAIL — class `RawExchangeRecorder` not found (and no rows written).

- [ ] **Step 3: Write the recorder**

Create `src/Services/RawExchange/RawExchangeRecorder.php`:

```php
<?php

namespace Jvjvjv\CodeTalker\Services\RawExchange;

use Closure;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Jvjvjv\CodeTalker\Models\AiProviderExchange;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class RawExchangeRecorder
{
    public function __construct(
        private RawExchangeContext $context,
    ) {
    }

    /**
     * Register the global Http middleware once. Safe to call in provider boot.
     */
    public function register(): void
    {
        Http::globalMiddleware($this->middleware());
    }

    /**
     * Run a callback with a capture frame active, popping it afterward.
     */
    public function capture(RawExchangeFrame $frame, Closure $callback): mixed
    {
        $this->context->push($frame);

        try {
            return $callback();
        } finally {
            $this->context->pop();
        }
    }

    public function middleware(): Closure
    {
        return function (callable $handler): callable {
            return function (RequestInterface $request, array $options) use ($handler) {
                $frame = $this->shouldCapture($request);

                if ($frame === null) {
                    return $handler($request, $options);
                }

                $method = $request->getMethod();
                $endpoint = $request->getUri()->getPath();
                $requestBody = (string) $request->getBody();

                if ($request->getBody()->isSeekable()) {
                    $request->getBody()->rewind();
                }

                $streaming = (bool) ($options['stream'] ?? false);
                $startedAt = microtime(true);

                return $handler($request, $options)->then(
                    function (ResponseInterface $response) use ($frame, $method, $endpoint, $requestBody, $streaming, $startedAt) {
                        $status = $response->getStatusCode();

                        if ($streaming) {
                            $teed = new TeeingStream(
                                $response->getBody(),
                                fn (string $bytes) => $this->write($frame, $method, $endpoint, $requestBody, true, $status, $bytes, $startedAt),
                            );

                            return $response->withBody($teed);
                        }

                        $bytes = (string) $response->getBody();

                        $this->write($frame, $method, $endpoint, $requestBody, false, $status, $bytes, $startedAt);

                        return $response->withBody(Utils::streamFor($bytes));
                    }
                );
            };
        };
    }

    private function shouldCapture(RequestInterface $request): ?RawExchangeFrame
    {
        if (! config('code-talker.raw_exchanges.enabled', true)) {
            return null;
        }

        $frame = $this->context->current();

        if ($frame === null) {
            return null;
        }

        if (! $this->providerAllowed($frame->provider)) {
            return null;
        }

        if (! $this->hostMatches($frame, $request)) {
            return null;
        }

        return $frame;
    }

    private function providerAllowed(string $provider): bool
    {
        $list = $this->normalizeProviders(config('code-talker.raw_exchanges.providers', 'lm-studio'));

        return $list === null || in_array($provider, $list, true);
    }

    /**
     * @return array<int, string>|null  null means "all providers"
     */
    private function normalizeProviders(mixed $configured): ?array
    {
        $items = is_array($configured) ? $configured : explode(',', (string) $configured);
        $items = array_values(array_filter(array_map('trim', $items), static fn ($v) => $v !== ''));

        if ($items === [] || in_array('all', array_map('strtolower', $items), true)) {
            return null;
        }

        return $items;
    }

    private function hostMatches(RawExchangeFrame $frame, RequestInterface $request): bool
    {
        $frameHost = $frame->host();

        if ($frameHost === null) {
            return false;
        }

        if (strtolower($frameHost) !== strtolower($request->getUri()->getHost())) {
            return false;
        }

        $framePort = $frame->port();

        return $framePort === null || $framePort === $request->getUri()->getPort();
    }

    private function write(
        RawExchangeFrame $frame,
        string $method,
        string $endpoint,
        string $requestBody,
        bool $streaming,
        int $status,
        string $rawResponse,
        float $startedAt,
    ): void {
        try {
            AiProviderExchange::create([
                'provider' => $frame->provider,
                'endpoint' => $endpoint,
                'method' => $method,
                'streaming' => $streaming,
                'http_status' => $status,
                'request_body' => $requestBody !== '' ? $requestBody : null,
                'raw_response' => $rawResponse !== '' ? $rawResponse : null,
                'model' => $frame->model,
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                'ai_system_id' => $frame->aiSystemId,
                'ai_conversation_id' => $frame->aiConversationId,
                'ai_llm_message_id' => $frame->aiLlmMessageId,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to record provider exchange', ['exception' => $e->getMessage()]);
        }
    }
}
```

- [ ] **Step 4: Bind singletons and register middleware in the service provider**

In `src/CodeTalkerServiceProvider.php`:

Add imports near the other `use` statements:

```php
use Illuminate\Support\Facades\Http;
use Jvjvjv\CodeTalker\Services\RawExchange\RawExchangeContext;
use Jvjvjv\CodeTalker\Services\RawExchange\RawExchangeRecorder;
```

In `register()`, after the existing `$this->app->singleton(ProviderModelsClient::class);` line, add:

```php
        $this->app->singleton(RawExchangeContext::class);
        $this->app->singleton(RawExchangeRecorder::class);
```

In `boot()`, after `$this->loadMigrationsFrom(...)` (near the top of `boot()`), add:

```php
        $this->app->make(RawExchangeRecorder::class)->register();
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/RawExchangeRecorderTest.php`
Expected: PASS (all six methods).

> If the streaming test's `$response->toPsrResponse()->getBody()` does not drive the tee (Http::fake streaming-fidelity risk noted in the spec), replace that line with a read loop: `$psr = $response->toPsrResponse()->getBody(); $consumed = ''; while (! $psr->eof()) { $consumed .= $psr->read(16); }` — this forces byte-by-byte reads to EOF.

- [ ] **Step 6: Run the full suite to check for regressions**

Run: `vendor/bin/phpunit`
Expected: PASS (existing faked-agent tests are unaffected — no real HTTP, so no exchange rows).

- [ ] **Step 7: Commit**

```bash
git add src/Services/RawExchange/RawExchangeRecorder.php src/CodeTalkerServiceProvider.php tests/Feature/RawExchangeRecorderTest.php
git commit -m "feat: capture raw provider exchanges via Http global middleware"
```

---

## Task 8: Open a frame around the chat streaming loop

**Files:**
- Modify: `src/Services/AiChatBotConversationService.php`
- Test: `tests/Feature/RawExchangeChatIntegrationTest.php`

**Interfaces:**
- Consumes: `RawExchangeContext`, `RawExchangeFrame::forSystem`, `AiSystemProviderConfigurator`.
- Produces: no new public API; the streaming loop runs inside an active frame carrying `ai_system_id`, `ai_conversation_id`, and the request `AiLlmMessage` id.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Jvjvjv\CodeTalker\Models\AiChatBot;
use Jvjvjv\CodeTalker\Models\AiLlmMessage;
use Jvjvjv\CodeTalker\Models\AiProviderExchange;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Services\AiChatBotConversationService;
use Jvjvjv\CodeTalker\Tests\TestCase;

class RawExchangeChatIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasColumn('ai_conversations', 'uuid')) {
            Schema::table('ai_conversations', function ($table): void {
                $table->string('uuid')->nullable();
            });
        }
    }

    public function test_streaming_chat_turn_records_a_provider_exchange(): void
    {
        Queue::fake();

        $sse = "data: {\"id\":\"c\",\"choices\":[{\"delta\":{\"role\":\"assistant\"}}]}\n\n"
            . "data: {\"choices\":[{\"delta\":{\"content\":\"Hi\"}}]}\n\n"
            . "data: {\"choices\":[{\"delta\":{},\"finish_reason\":\"stop\"}]}\n\n"
            . "data: [DONE]\n\n";

        Http::fake([
            'http://localhost:1234/v1/chat/completions' => Http::response($sse, 200, [
                'Content-Type' => 'text/event-stream',
            ]),
        ]);

        $system = AiSystem::create([
            'name' => 'Local',
            'provider' => 'lm-studio',
            'model' => 'qwen/qwen3.5-9b',
            'base_url' => 'http://localhost:1234',
            'max_tokens' => 256,
            'is_active' => true,
        ]);

        $bot = AiChatBot::create([
            'ai_system_id' => $system->id,
            'name' => 'Local Bot',
            'slug' => 'local-bot',
            'prompt_template' => 'You are {{bot_name}}.',
            'is_active' => true,
        ]);

        $service = $this->app->make(AiChatBotConversationService::class);
        $conversation = $service->startConversation($bot);

        foreach ($service->continueConversation($conversation, 'Hello') as $line) {
            // drain the stream
        }

        $exchange = AiProviderExchange::first();
        $this->assertNotNull($exchange);
        $this->assertSame('lm-studio', $exchange->provider);
        $this->assertTrue($exchange->streaming);
        $this->assertSame($sse, $exchange->raw_response);
        $this->assertSame($conversation->id, $exchange->ai_conversation_id);

        $requestMessage = AiLlmMessage::where('direction', 'request')->first();
        $this->assertSame($requestMessage->id, $exchange->ai_llm_message_id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/RawExchangeChatIntegrationTest.php`
Expected: FAIL — `AiProviderExchange::first()` is null (no frame opened around the stream yet).

> If this test fails instead because laravel/ai rejects the faked SSE shape (fidelity risk), adjust the faked `$sse` chunks to match the `openai-compatible` driver's expectations; the assertion of interest is that an exchange row exists with `raw_response === $sse`.

- [ ] **Step 3: Inject the new dependencies**

In `src/Services/AiChatBotConversationService.php`, add imports:

```php
use Jvjvjv\CodeTalker\Services\LaravelAi\AiSystemProviderConfigurator;
use Jvjvjv\CodeTalker\Services\RawExchange\RawExchangeContext;
use Jvjvjv\CodeTalker\Services\RawExchange\RawExchangeFrame;
```

Extend the constructor:

```php
    public function __construct(
        private AgentFactory $agentFactory,
        private AiMemoryService $memoryService,
        private ConversationUsageService $conversationUsageService,
        private RawExchangeContext $rawExchangeContext,
        private AiSystemProviderConfigurator $providerConfigurator,
    ) {
    }
```

- [ ] **Step 4: Open a frame around the streaming loop**

In `continueConversation()`, the request `AiLlmMessage` is currently created without capturing the return value. Change that block so it assigns the record and opens a frame around the `foreach ($agent->stream(...))` loop. Locate:

```php
                AiLlmMessage::create([
                    'ai_conversation_id' => $conversation->id,
                    'direction' => 'request',
                    'turn_number' => $attemptTurnNumber,
                    'request_data' => $requestPayload,
                    'created_at' => now(),
                ]);

                /** @var array<int, StreamEvent> $events */
                $events = [];
                $toolCalls = [];

                foreach ($agent->stream($prompt) as $event) {
```

Replace it with:

```php
                $requestMessage = AiLlmMessage::create([
                    'ai_conversation_id' => $conversation->id,
                    'direction' => 'request',
                    'turn_number' => $attemptTurnNumber,
                    'request_data' => $requestPayload,
                    'created_at' => now(),
                ]);

                /** @var array<int, StreamEvent> $events */
                $events = [];
                $toolCalls = [];

                $this->rawExchangeContext->push(RawExchangeFrame::forSystem(
                    $system,
                    $this->providerConfigurator,
                    aiConversationId: $conversation->id,
                    aiLlmMessageId: $requestMessage->id,
                ));

                try {
                    foreach ($agent->stream($prompt) as $event) {
```

Then find the end of that `foreach` loop (the closing `}` immediately before `$attemptUsage = StreamEnd::combineUsage($events);`) and wrap it with the `finally` that pops the frame. Locate:

```php
                    foreach ($translator->translate($event) as $browserEvent) {
                        if ($browserEvent['type'] === 'content_block_delta') {
                            $appendToBlocks('text', $browserEvent['delta']['text']);
                        } elseif ($browserEvent['type'] === 'reasoning_block_delta') {
                            $appendToBlocks('reasoning', $browserEvent['delta']['reasoning']);
                        }

                        yield 'data: ' . json_encode($browserEvent) . "\n\n";
                    }
                }

                $attemptUsage = StreamEnd::combineUsage($events);
```

Replace with (note the added `finally` block and the extra indentation on the inner statements is optional — keep the existing indentation of the loop body, only the wrapping `try {`/`} finally {` are new):

```php
                    foreach ($translator->translate($event) as $browserEvent) {
                        if ($browserEvent['type'] === 'content_block_delta') {
                            $appendToBlocks('text', $browserEvent['delta']['text']);
                        } elseif ($browserEvent['type'] === 'reasoning_block_delta') {
                            $appendToBlocks('reasoning', $browserEvent['delta']['reasoning']);
                        }

                        yield 'data: ' . json_encode($browserEvent) . "\n\n";
                    }
                    }
                } finally {
                    $this->rawExchangeContext->pop();
                }

                $attemptUsage = StreamEnd::combineUsage($events);
```

> **Care:** the `foreach ($agent->stream($prompt) as $event) {` opening brace now belongs to the `try {` you added in the previous edit. Ensure exactly one `try {` opens before the `foreach` and one `} finally { ... }` closes after the `foreach`'s closing brace. Verify by running the test in Step 5; a brace mismatch produces a PHP parse error naming the line.

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/RawExchangeChatIntegrationTest.php`
Expected: PASS.

- [ ] **Step 6: Run the full suite**

Run: `vendor/bin/phpunit`
Expected: PASS — existing `AiChatBotConversationServiceTest` (faked agent, no HTTP) still passes; no exchange rows created there.

- [ ] **Step 7: Commit**

```bash
git add src/Services/AiChatBotConversationService.php tests/Feature/RawExchangeChatIntegrationTest.php
git commit -m "feat: record provider exchanges for chat streaming turns"
```

---

## Task 9: Wrap the memory-analysis call in a capture frame

**Files:**
- Modify: `src/Services/AiMemoryService.php`
- Test: `tests/Feature/RawExchangeMemoryIntegrationTest.php`

**Interfaces:**
- Consumes: `RawExchangeRecorder::capture`, `RawExchangeFrame::forSystem`, `AiSystemProviderConfigurator`.
- Produces: no new public API; `analyzeConversation()`'s agent call runs inside a frame carrying only `ai_system_id`, `provider`, `base_url`, `model` (conversation/message links null, per spec).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Models\AiConversationMessage;
use Jvjvjv\CodeTalker\Models\AiProviderExchange;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Services\AiMemoryService;
use Jvjvjv\CodeTalker\Tests\TestCase;

class RawExchangeMemoryIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasColumn('ai_conversations', 'uuid')) {
            Schema::table('ai_conversations', function ($table): void {
                $table->string('uuid')->nullable();
            });
        }
    }

    public function test_memory_analysis_records_a_provider_exchange_with_null_links(): void
    {
        $completion = json_encode([
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => '{"add":[],"update":[],"remove":[]}'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ]);

        Http::fake(['http://localhost:1234/v1/chat/completions' => Http::response($completion, 200)]);

        $system = AiSystem::create([
            'name' => 'Local',
            'provider' => 'lm-studio',
            'model' => 'qwen/qwen3.5-9b',
            'base_url' => 'http://localhost:1234',
            'max_tokens' => 4096,
            'is_active' => true,
        ]);

        $conversation = AiConversation::create([
            'ai_system_id' => $system->id,
            'feature' => 'chat-bot:local',
            'status' => 'active',
        ]);

        AiConversationMessage::create([
            'ai_conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Hello there',
        ]);

        $result = $this->app->make(AiMemoryService::class)
            ->analyzeConversation($conversation, userId: 1);

        $this->assertSame(['add' => [], 'update' => [], 'remove' => []], $result);

        $exchange = AiProviderExchange::first();
        $this->assertNotNull($exchange);
        $this->assertSame('lm-studio', $exchange->provider);
        $this->assertFalse($exchange->streaming);
        $this->assertSame($completion, $exchange->raw_response);
        $this->assertSame($system->id, $exchange->ai_system_id);
        $this->assertNull($exchange->ai_conversation_id);
        $this->assertNull($exchange->ai_llm_message_id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/RawExchangeMemoryIntegrationTest.php`
Expected: FAIL — `AiProviderExchange::first()` is null (memory call not wrapped in a frame).

> If it fails because the faked completion shape is rejected by the driver, adjust `$completion` fields to satisfy the `openai-compatible` non-streaming parser; the assertion of interest is that a row exists with `raw_response === $completion`.

- [ ] **Step 3: Inject dependencies and wrap the call**

In `src/Services/AiMemoryService.php`, add imports:

```php
use Jvjvjv\CodeTalker\Services\LaravelAi\AiSystemProviderConfigurator;
use Jvjvjv\CodeTalker\Services\RawExchange\RawExchangeFrame;
use Jvjvjv\CodeTalker\Services\RawExchange\RawExchangeRecorder;
```

Extend the constructor:

```php
    public function __construct(
        private LaravelAi\AgentFactory $agentFactory,
        private RawExchangeRecorder $rawExchanges,
        private AiSystemProviderConfigurator $providerConfigurator,
    ) {
    }
```

In `analyzeConversation()`, replace:

```php
        $response = $agent->prompt($analysisPrompt);

        return $this->parseAnalysisResponse((string) $response->text);
```

with:

```php
        $response = $this->rawExchanges->capture(
            RawExchangeFrame::forSystem($conversation->aiSystem, $this->providerConfigurator),
            fn () => $agent->prompt($analysisPrompt),
        );

        return $this->parseAnalysisResponse((string) $response->text);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/RawExchangeMemoryIntegrationTest.php`
Expected: PASS.

- [ ] **Step 5: Run the full suite**

Run: `vendor/bin/phpunit`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Services/AiMemoryService.php tests/Feature/RawExchangeMemoryIntegrationTest.php
git commit -m "feat: record provider exchanges for memory analysis calls"
```

---

## Task 10: Prune command + schedule

**Files:**
- Create: `src/Console/Commands/PruneProviderExchangesCommand.php`
- Modify: `src/CodeTalkerServiceProvider.php` (register command; schedule daily)
- Test: `tests/Feature/PruneProviderExchangesCommandTest.php`

**Interfaces:**
- Produces: artisan command `ai:prune-provider-exchanges {--days=} {--dry-run}` that deletes `ai_provider_exchanges` rows older than `days` (default `config('code-talker.raw_exchanges.retention_days')`).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Jvjvjv\CodeTalker\Models\AiProviderExchange;
use Jvjvjv\CodeTalker\Tests\TestCase;

class PruneProviderExchangesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_deletes_rows_older_than_retention_days(): void
    {
        config()->set('code-talker.raw_exchanges.retention_days', 14);

        $old = AiProviderExchange::create([
            'provider' => 'lm-studio', 'endpoint' => '/v1/chat/completions', 'method' => 'POST',
            'streaming' => false, 'created_at' => now()->subDays(30),
        ]);
        $recent = AiProviderExchange::create([
            'provider' => 'lm-studio', 'endpoint' => '/v1/chat/completions', 'method' => 'POST',
            'streaming' => false, 'created_at' => now()->subDays(2),
        ]);

        $this->artisan('ai:prune-provider-exchanges')
            ->assertExitCode(0);

        $this->assertNull(AiProviderExchange::find($old->id));
        $this->assertNotNull(AiProviderExchange::find($recent->id));
    }

    public function test_dry_run_deletes_nothing(): void
    {
        AiProviderExchange::create([
            'provider' => 'lm-studio', 'endpoint' => '/v1/chat/completions', 'method' => 'POST',
            'streaming' => false, 'created_at' => now()->subDays(90),
        ]);

        $this->artisan('ai:prune-provider-exchanges --dry-run')->assertExitCode(0);

        $this->assertSame(1, AiProviderExchange::count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/PruneProviderExchangesCommandTest.php`
Expected: FAIL — command `ai:prune-provider-exchanges` not defined.

- [ ] **Step 3: Write the command**

Create `src/Console/Commands/PruneProviderExchangesCommand.php`:

```php
<?php

namespace Jvjvjv\CodeTalker\Console\Commands;

use Illuminate\Console\Command;
use Jvjvjv\CodeTalker\Models\AiProviderExchange;

class PruneProviderExchangesCommand extends Command
{
    protected $signature = 'ai:prune-provider-exchanges
        {--days= : Override the retention window in days}
        {--dry-run : Report how many rows would be deleted without deleting}';

    protected $description = 'Delete captured provider exchange rows older than the retention window.';

    public function handle(): int
    {
        $days = $this->option('days') !== null
            ? max((int) $this->option('days'), 0)
            : (int) config('code-talker.raw_exchanges.retention_days', 14);

        $cutoff = now()->subDays($days);

        $query = AiProviderExchange::query()->where('created_at', '<', $cutoff);

        if ($this->option('dry-run')) {
            $this->info("Would delete {$query->count()} provider exchange(s) older than {$days} day(s).");

            return self::SUCCESS;
        }

        $deleted = $query->delete();

        $this->info("Deleted {$deleted} provider exchange(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Register and schedule the command**

In `src/CodeTalkerServiceProvider.php`, add the import:

```php
use Jvjvjv\CodeTalker\Console\Commands\PruneProviderExchangesCommand;
```

Add `PruneProviderExchangesCommand::class` to the `$this->commands([...])` array inside the `runningInConsole()` block:

```php
            $this->commands([
                BackfillAiSystemCapabilitiesCommand::class,
                BackfillConversationUsageCommand::class,
                SyncConversationUsageCommand::class,
                PruneProviderExchangesCommand::class,
            ]);
```

Inside the `if (config('code-talker.schedule', true)) {` block, add:

```php
            Schedule::command('ai:prune-provider-exchanges')
                ->dailyAt('03:00')
                ->withoutOverlapping();
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/PruneProviderExchangesCommandTest.php`
Expected: PASS (both methods).

- [ ] **Step 6: Commit**

```bash
git add src/Console/Commands/PruneProviderExchangesCommand.php src/CodeTalkerServiceProvider.php tests/Feature/PruneProviderExchangesCommandTest.php
git commit -m "feat: add ai:prune-provider-exchanges retention command"
```

---

## Task 11: Documentation

**Files:**
- Modify: `README.md` (Configuration section)

**Interfaces:** none (docs only).

- [ ] **Step 1: Document the config block**

In `README.md`, under the Configuration section (where `providers.*` and `schedule` are documented), add a subsection:

```markdown
### Raw Provider Exchange Logging

Every laravel/ai HTTP request/response can be captured verbatim into the
`ai_provider_exchanges` table for debugging and auditing.

```php
'raw_exchanges' => [
    'enabled' => env('CODE_TALKER_RAW_EXCHANGES_ENABLED', true),
    'providers' => env('CODE_TALKER_RAW_EXCHANGES_PROVIDERS', 'lm-studio'),
    'retention_days' => (int) env('CODE_TALKER_RAW_EXCHANGES_RETENTION_DAYS', 14),
],
```

- `enabled` — master switch for capture.
- `providers` — comma-separated allow-list of `AiSystem` provider values
  (`lm-studio`, `anthropic`, `openai`, `openai-compatible`, `gemini`, `grok`),
  or `all` to capture every provider. Defaults to `lm-studio`.
- `retention_days` — rows older than this are removed by
  `php artisan ai:prune-provider-exchanges`, scheduled daily at 03:00 (respects
  the `schedule` flag).

Request bodies and response bytes are stored, but request **headers are never
recorded**, so provider API keys are not persisted.
```

- [ ] **Step 2: Verify the full suite still passes**

Run: `vendor/bin/phpunit`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add README.md
git commit -m "docs: document raw provider exchange logging config"
```

> **CHANGELOG:** Per the repo's documentation rules, in-progress work is not logged in `CHANGELOG.md` until the version ships. When cutting the next release, add a **New Features** entry: "Raw provider exchange logging captures verbatim laravel/ai request/response traffic into `ai_provider_exchanges`, with a configurable provider allow-list and a daily retention prune (`ai:prune-provider-exchanges`)."

---

## Self-Review

**Spec coverage:**
- Dedicated `ai_provider_exchanges` table (nullable correlation) → Task 1. ✓
- Config: `enabled`/`providers`/`retention_days` with exact env vars → Task 2. ✓
- Literal bytes via stream tee → Task 5 (`TeeingStream`) + Task 7 (streaming path). ✓
- Non-streaming full-body capture → Task 7 (non-streaming path + test). ✓
- Global `Http` middleware seam → Task 7. ✓
- Scoping by active frame + provider allow-list + host match → Task 7 (`shouldCapture`, `providerAllowed`, `hostMatches`) with dedicated skip tests. ✓
- Correlation frames opened at service call sites → Task 8 (chat, with conversation + request-message id) and Task 9 (memory, null links). ✓
- Never break a chat turn / swallow logging errors → Task 7 (`write()` try/catch). ✓
- No header/secret capture → Task 7 records only body; documented in Task 11. ✓
- Retention prune command + daily schedule → Task 10. ✓
- `baseUrlFor` for host matching → Task 6. ✓
- Docs (README) → Task 11; CHANGELOG deferred to release per rules. ✓

**Placeholder scan:** No TBD/TODO; every code step shows complete code; every test shows real assertions. ✓

**Type consistency:** `RawExchangeFrame` props (`provider`, `baseUrl`, `aiSystemId`, `aiConversationId`, `aiLlmMessageId`, `model`) and methods (`forSystem`, `host`, `port`) are used identically in Tasks 3, 7, 8, 9. `RawExchangeContext` (`push`/`pop`/`current`) consistent across Tasks 4, 7, 8. `RawExchangeRecorder` (`register`, `capture`, `middleware`) consistent across Tasks 7, 9. `baseUrlFor` signature consistent Tasks 3, 6. Config keys identical across Tasks 2, 7, 10, 11. ✓

**Ordering note:** Task 6 (`baseUrlFor`) must land before Task 3 (`RawExchangeFrame::forSystem` calls it). Both are flagged inline. All other tasks are in dependency order.
