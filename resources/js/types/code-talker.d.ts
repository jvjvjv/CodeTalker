/**
 * Type declarations for the jvjvjv/code-talker frontend contract.
 *
 * These describe the Inertia props the package's chat pages receive and the
 * server-sent events its message endpoint streams. Both are public API covered
 * by the package's semantic version — see "Frontend Integration" in the README.
 *
 * Published with:
 *   php artisan vendor:publish --tag=code-talker-types
 */

// ---------------------------------------------------------------------------
// Chat page props
// ---------------------------------------------------------------------------

/** One contiguous run of assistant output of a single kind. */
export interface MessageBlock {
    type: 'text' | 'reasoning';
    content: string;
}

/** One visible message. The system prompt is never sent to the browser. */
export interface ChatMessage {
    role: 'user' | 'assistant';
    content: string;
    /** Populated for reasoning models. */
    reasoning_content: string | null;
    /** Ordered content runs; null on messages stored before blocks existed. */
    blocks: MessageBlock[] | null;
}

/** One entry in the conversation switcher. */
export interface ChatHistoryEntry {
    /** Opaque id to POST to `switchUrl`. */
    handle: string;
    /** The conversation title, or 'New chat'. */
    label: string;
    is_current: boolean;
    /** No activity for 7 days. */
    is_stale: boolean;
    /** Human-readable, e.g. '3 hours ago'. */
    updated_at: string;
    cost_usd: number | null;
}

export interface ChatBotSummary {
    name: string;
    description: string | null;
    /** Whether anonymous visitors are asked for a name and email. */
    require_visitor_identity: boolean;
    /** Lifetime spend across every conversation with this bot. */
    total_cost_usd: number;
}

/** Props for the component configured as `inertia.components.chat_bot`. */
export interface ChatBotPageProps {
    bot: ChatBotSummary;
    messages: ChatMessage[];
    history: ChatHistoryEntry[];
    /** POST here to send a message; the response is an SSE stream. */
    messageUrl: string;
    resetUrl: string;
    switchUrl: string;
    statusUrl: string;
    warmupUrl: string;
    /** Shareable link, null until the conversation has a hash. */
    chatUrl: string | null;
    /** e.g. '/chat/my-bot/', for building links client-side. */
    chatUrlBase: string;
    showIdentityForm: boolean;
}

/**
 * Props when the page is reached through a shareable hash link.
 *
 * `chatHash` is present only on this route, and `showIdentityForm` is derived
 * differently here: the conversation exists by definition, so the form shows
 * when it has no non-system messages yet.
 */
export interface ChatBotHashPageProps extends ChatBotPageProps {
    chatHash: string | null;
}

// ---------------------------------------------------------------------------
// Index page props
// ---------------------------------------------------------------------------

export interface ChatBotListConversation {
    title: string;
    /** ISO 8601. */
    updated_at: string | null;
    /** Human-readable, e.g. '3 hours ago'. */
    updated_at_human: string;
    is_stale: boolean;
}

export interface ChatBotListEntry {
    slug: string;
    name: string;
    description: string | null;
    new_chat_url: string;
    status_url: string;
    /** Empty for guests — only populated for an authenticated user. */
    conversations: ChatBotListConversation[];
}

/** Props for the component configured as `inertia.components.chat_bots_index`. */
export interface ChatBotsIndexProps {
    bots: ChatBotListEntry[];
}

// ---------------------------------------------------------------------------
// Stream events
// ---------------------------------------------------------------------------

/** Why the turn stopped. */
export type StopReason = 'end_turn' | 'max_tokens' | 'tool_use';

/**
 * Why the turn failed. Absent for a recoverable in-stream provider error and
 * for a transport-level failure.
 */
export type ChatStreamErrorReason = 'max_stream_duration' | 'provider_error';

/** Progress before any tokens arrive. */
export interface StatusEvent {
    type: 'status';
    phase: 'request_received' | 'model_loading';
    message: string;
}

/** The turn has begun. Sent exactly once per turn. */
export interface MessageStartEvent {
    type: 'message_start';
    message: { usage: { input_tokens: number | null } };
}

/** Append to the answer. */
export interface ContentBlockDeltaEvent {
    type: 'content_block_delta';
    delta: { text: string };
}

/** Append to the reasoning trace. */
export interface ReasoningBlockDeltaEvent {
    type: 'reasoning_block_delta';
    delta: { reasoning: string };
}

/** Terminal summary of the turn. */
export interface MessageDeltaEvent {
    type: 'message_delta';
    delta: { stop_reason: StopReason };
    usage: { input_tokens: number | null; output_tokens: number | null };
}

/** The turn's content is complete. */
export interface MessageStopEvent {
    type: 'message_stop';
}

/**
 * The turn failed. This is terminal on its own — the `[DONE]` sentinel does
 * NOT follow an error frame. Content already delivered is still valid.
 */
export interface ChatStreamErrorEvent {
    type: 'error';
    message: string;
    reason?: ChatStreamErrorReason;
}

/** Every event the message endpoint emits, discriminated on `type`. */
export type ChatStreamEvent =
    | StatusEvent
    | MessageStartEvent
    | ContentBlockDeltaEvent
    | ReasoningBlockDeltaEvent
    | MessageDeltaEvent
    | MessageStopEvent
    | ChatStreamErrorEvent;

/** The literal frame that terminates a turn that finished normally. */
export type ChatStreamDoneSentinel = '[DONE]';
