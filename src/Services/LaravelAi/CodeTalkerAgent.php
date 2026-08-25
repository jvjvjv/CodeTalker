<?php

namespace Jvjvjv\CodeTalker\Services\LaravelAi;

use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Generic laravel/ai agent configured at runtime from an AiSystem record.
 *
 * One class serves every AiSystem: laravel/ai resolves provider, model, and
 * generation options from same-named instance methods before falling back to
 * class attributes, so all per-system values are plain constructor state.
 * Build instances via AgentFactory, not directly.
 */
class CodeTalkerAgent implements Agent, HasProviderOptions, HasTools, RemembersConversationsContract
{
    use Promptable;

    // The trait's messages() reads stored history; this class needs it combined
    // with the in-turn messages append() adds, so it is aliased rather than used
    // directly. A class-defined method silently wins over a trait one, so
    // without the alias the stored history would never be read at all.
    use RemembersConversations {
        messages as private storedMessages;
    }

    /**
     * @param array<int, Message> $messages
     * @param array<int, object> $tools
     * @param array<string, mixed> $providerOptions
     */
    public function __construct(
        protected string $providerName,
        protected string|Stringable $instructions = '',
        protected array $messages = [],
        protected array $tools = [],
        protected ?string $model = null,
        protected ?int $maxTokens = null,
        protected ?float $temperature = null,
        protected int $maxSteps = 6,
        protected int $timeout = 60,
        protected array $providerOptions = [],
    ) {
    }

    public function instructions(): Stringable|string
    {
        return $this->instructions;
    }

    /**
     * Stored history first, then anything appended within this turn.
     *
     * @return array<int, Message>
     */
    public function messages(): iterable
    {
        return [...$this->storedMessages(), ...$this->messages];
    }

    /**
     * Replay a stored conversation's history without arming the framework's
     * remembering middleware.
     *
     * That middleware attaches only when a conversation participant is present,
     * and it persists both messages of a turn from a `then()` callback that
     * fires after the stream is fully consumed — which never happens when the
     * turn runner breaks out early on a client abort or the duration guard. So
     * this package reads history through the store and keeps writing through
     * TurnRecorder, which persists partial turns too.
     *
     * Hosts wanting the framework's behaviour instead can call continue().
     */
    public function withStoredConversation(string $conversationId): static
    {
        $this->conversationId = $conversationId;

        return $this;
    }

    /**
     * @return array<int, object>
     */
    public function tools(): iterable
    {
        return $this->tools;
    }

    /**
     * Append prior-turn messages, e.g. when continuing after a length stop.
     */
    public function append(Message ...$messages): self
    {
        array_push($this->messages, ...$messages);

        return $this;
    }

    public function provider(): string
    {
        return $this->providerName;
    }

    public function model(): ?string
    {
        return $this->model;
    }

    public function maxTokens(): ?int
    {
        return $this->maxTokens;
    }

    public function temperature(): ?float
    {
        // claude-opus-4-7 rejects explicit temperature values.
        if ($this->model === 'claude-opus-4-7') {
            return null;
        }

        return $this->temperature;
    }

    public function maxSteps(): int
    {
        return $this->maxSteps;
    }

    public function timeout(): int
    {
        return $this->timeout;
    }

    /**
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        return $this->providerOptions;
    }
}
