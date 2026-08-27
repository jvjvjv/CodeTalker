<?php

namespace Jvjvjv\CodeTalker\Support;

use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Services\Web\WebToolPolicy;

/**
 * Identity/context a tool runs under, populated differently per transport.
 *
 * - Local chat loop: built from the live {@see AiConversation} (carries the
 *   conversation plus its derived user identity and feature key).
 * - External MCP server: built from the authenticated caller (user id only;
 *   conversation is null and feature is unscoped).
 *
 * Tools read only what they need and degrade gracefully when the conversation
 * is absent.
 */
final class ToolContext
{
    public function __construct(
        public readonly ?AiConversation $conversation = null,
        public readonly int|string|null $userId = null,
        public readonly ?string $visitorEmail = null,
        public readonly ?string $feature = null,
    ) {}

    /**
     * Build a context from a live conversation (local chat loop).
     */
    public static function forConversation(AiConversation $conversation): self
    {
        return new self(
            conversation: $conversation,
            userId: $conversation->user_id,
            visitorEmail: $conversation->visitor_email,
            feature: $conversation->feature,
        );
    }

    /**
     * Build a context for an authenticated external MCP caller (no conversation).
     */
    public static function forUser(int|string|null $userId, ?string $visitorEmail = null): self
    {
        return new self(userId: $userId, visitorEmail: $visitorEmail);
    }

    /**
     * The chat bot name to advertise as the scraper user agent, if known.
     */
    public function botName(): ?string
    {
        return $this->conversation?->aiPersona?->name;
    }

    /**
     * The web-tool domain/credential scoping in force for this call.
     *
     * A conversation's AiSystem is the sole authority when one exists —
     * including its choice to leave `web_tool_policy` unset, which means
     * unrestricted and stays unrestricted; there is no fallback here, because
     * falling back would silently retighten a bot the operator already chose
     * not to scope.
     *
     * Without a conversation (the external MCP server — see {@see forUser()})
     * there is no AiSystem to ask at all, so the global
     * `code-talker.tools.web_fetcher.allowed_domains` config is consulted
     * instead. Without this, an MCP caller could never satisfy
     * `WebFetcher::allowsCredentialHeaders()`, regardless of what an operator
     * configures — nothing would ever be able to scope that transport.
     */
    public function webToolPolicy(): WebToolPolicy
    {
        if ($this->conversation !== null) {
            return WebToolPolicy::fromArray($this->conversation->aiPersona?->aiSystem?->web_tool_policy);
        }

        /** @var array<int, mixed> $configured */
        $configured = (array) config('code-talker.tools.web_fetcher.allowed_domains', []);

        return new WebToolPolicy(
            allowedDomains: $configured !== [] ? array_values(array_map('strval', $configured)) : null,
        );
    }

    /**
     * Whether this context has any user identity to scope user-specific data by.
     */
    public function hasIdentity(): bool
    {
        return $this->userId !== null || $this->visitorEmail !== null;
    }
}
