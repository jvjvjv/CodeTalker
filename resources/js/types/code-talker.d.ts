/**
 * Type declarations for the jvjvjv/code-talker frontend contract.
 *
 * The package ships no routes and no pages. What it does ship is the turn event
 * vocabulary, which these declare, and an encoder that frames those events as
 * server-sent events. They describe what your own endpoint emits when it passes
 * a turn through `SseFrameEncoder` — public API covered by the package's
 * semantic version, see "Driving a turn" in the README.
 *
 * Published with:
 *   php artisan vendor:publish --tag=code-talker-types
 */

// ---------------------------------------------------------------------------
// Transcript
// ---------------------------------------------------------------------------

/** One contiguous run of assistant output of a single kind. */
export interface MessageBlock {
    type: 'text' | 'reasoning';
    content: string;
}

/**
 * One visible message, matching what `ChatBotPresenter::transcript()` returns.
 * The system prompt is never part of a transcript.
 */
export interface ChatMessage {
    role: 'user' | 'assistant';
    content: string;
    /** Populated for reasoning models. */
    reasoning_content: string | null;
    /** Ordered content runs; null on messages stored before blocks existed. */
    blocks: MessageBlock[] | null;
    /**
     * The reply was never finished — the browser hung up, or the server's
     * duration guard cut it off. `content` may be empty or stop mid-sentence;
     * render it as interrupted rather than as an answer.
     */
    incomplete: boolean;
}

// ---------------------------------------------------------------------------
// Stream events
// ---------------------------------------------------------------------------

/**
 * Why the turn stopped. `incomplete` means the turn never finished — the
 * connection dropped, or the server's duration guard cut the generation off —
 * so whatever content arrived stops mid-answer.
 */
export type StopReason = 'end_turn' | 'max_tokens' | 'tool_use' | 'incomplete';

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

/**
 * The agent is calling a tool. `text` is always `""` — this is a progress
 * signal, not display text. `input`/`output`/`successful` are present only
 * when the host enabled tool payloads.
 */
export interface ToolUseProgressEvent {
    type: 'tool_use_progress';
    text: string;
    tools: string[];
    input?: unknown;
    output?: unknown;
    successful?: boolean;
}

/** A tool changed server state; the page should reload. */
export interface PageReloadEvent {
    type: 'page_reload';
}

/**
 * `heartbeat` is deliberately absent from this union. The server yields it as
 * a turn event, but `SseFrameEncoder` writes it as an SSE comment (`: ping`),
 * which never arrives as a message — so a wire consumer cannot receive one and
 * should not be made to handle it. A host consuming the events directly,
 * without the SSE encoding, will see `{ type: 'heartbeat' }`.
 *
 * A turn dispatched with `dispatchTurn()` frames each stored event with an SSE
 * `id:` line carrying its sequence — present only on that path, never for
 * `continueConversation()`. The published client's `ChatTurnCallbacks` reports
 * it through `onSequence?: (sequence: number) => void`: the sequence of the
 * last event received, to pass back as `after` when reconnecting so the turn
 * resumes rather than replays.
 */

/** Every event the message endpoint emits, discriminated on `type`. */
export type ChatStreamEvent =
    | StatusEvent
    | MessageStartEvent
    | ContentBlockDeltaEvent
    | ReasoningBlockDeltaEvent
    | MessageDeltaEvent
    | MessageStopEvent
    | ToolUseProgressEvent
    | PageReloadEvent
    | ChatStreamErrorEvent;

/** The literal frame that terminates a turn that finished normally. */
export type ChatStreamDoneSentinel = '[DONE]';
