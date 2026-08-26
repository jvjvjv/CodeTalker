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
        return $this->conversation?->aiChatBot?->name;
    }

    /**
     * The web-tool domain/credential scoping for this conversation's AiSystem.
     * Unrestricted when there is no conversation, no chat bot, no system, or
     * the system has no policy configured — matching pre-scoping behavior.
     */
    public function webToolPolicy(): WebToolPolicy
    {
        return WebToolPolicy::fromArray($this->conversation?->aiChatBot?->aiSystem?->web_tool_policy);
    }

    /**
     * Whether this context has any user identity to scope user-specific data by.
     */
    public function hasIdentity(): bool
    {
        return $this->userId !== null || $this->visitorEmail !== null;
    }
}
