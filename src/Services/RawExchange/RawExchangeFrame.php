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
