/**
 * A dependency-free client for the jvjvjv/code-talker message stream.
 *
 * Published with:
 *   php artisan vendor:publish --tag=code-talker-client
 *
 * This is a starting point, not a dependency — once published it is yours to
 * edit, and upgrades will not publish over your changes. It imports nothing but
 * standard browser APIs, so it works with any UI framework or none.
 *
 * The wire format it consumes is documented under "Frontend Integration" in the
 * package README.
 */

import type {
    ChatStreamErrorReason,
    ChatStreamEvent,
    StopReason,
} from './types/code-talker';

export interface ChatTurnPayload {
    message: string;
    /** Required on the first message when the bot asks anonymous visitors to identify themselves. */
    name?: string;
    email?: string;
    [key: string]: unknown;
}

export interface ChatTurnSummary {
    stopReason: StopReason;
    usage: { inputTokens: number | null; outputTokens: number | null };
}

export interface ChatTurnError {
    message: string;
    reason?: ChatStreamErrorReason;
}

export interface ChatTurnCallbacks {
    /** The conversation's shareable hash, as soon as response headers arrive. */
    onChatHash?: (hash: string) => void;
    /** Progress before tokens arrive. */
    onStatus?: (phase: 'request_received' | 'model_loading', message: string) => void;
    /** The turn has begun. */
    onStart?: () => void;
    /** Append to the answer. */
    onText?: (delta: string) => void;
    /** Append to the reasoning trace. */
    onReasoning?: (delta: string) => void;
    /** The turn finished normally. */
    onDone?: (summary: ChatTurnSummary) => void;
    /**
     * The turn failed. Terminal — no completion follows. Content already
     * delivered through onText/onReasoning is still valid.
     */
    onError?: (error: ChatTurnError) => void;
}

export interface ChatTurn {
    /**
     * Stop the turn. The server keeps generating just long enough to persist
     * whatever it already produced, so a cancelled turn is not lost.
     */
    abort: () => void;
    /** Resolves once the stream closes, however it ended. */
    done: Promise<void>;
}

export interface ChatTurnOptions {
    /** Extra headers, e.g. a CSRF token if your app does not set one globally. */
    headers?: Record<string, string>;
    /** Supply your own controller to tie cancellation to an existing lifetime. */
    signal?: AbortSignal;
}

const DONE_SENTINEL = '[DONE]';

/**
 * POST a message and stream the reply.
 *
 * Callbacks fire as frames arrive. `done` resolves when the stream closes,
 * whether it ended with the completion sentinel, an error frame, or an abort —
 * it never rejects for a streamed error, because an error arrives after content
 * the UI should keep.
 */
export function streamChatTurn(
    messageUrl: string,
    payload: ChatTurnPayload,
    callbacks: ChatTurnCallbacks = {},
    options: ChatTurnOptions = {},
): ChatTurn {
    const controller = new AbortController();

    if (options.signal) {
        options.signal.addEventListener('abort', () => controller.abort(), { once: true });
    }

    const done = run(messageUrl, payload, callbacks, options, controller);

    return {
        abort: () => controller.abort(),
        done,
    };
}

async function run(
    messageUrl: string,
    payload: ChatTurnPayload,
    callbacks: ChatTurnCallbacks,
    options: ChatTurnOptions,
    controller: AbortController,
): Promise<void> {
    try {
        const response = await fetch(messageUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'text/event-stream',
                'X-Requested-With': 'XMLHttpRequest',
                ...csrfHeader(),
                ...options.headers,
            },
            body: JSON.stringify(payload),
            signal: controller.signal,
        });

        const chatHash = response.headers.get('X-Chat-Hash');

        if (chatHash) {
            callbacks.onChatHash?.(chatHash);
        }

        if (!response.ok) {
            callbacks.onError?.({ message: `Request failed with status ${response.status}.` });

            return;
        }

        if (response.body === null) {
            callbacks.onError?.({ message: 'The response carried no body to stream.' });

            return;
        }

        await consume(response.body, callbacks);
    } catch (error) {
        // An abort is a deliberate stop, not a failure to report.
        if (isAbortError(error)) {
            return;
        }

        callbacks.onError?.({
            message: error instanceof Error ? error.message : 'The stream failed unexpectedly.',
        });
    }
}

/**
 * Read the SSE body, dispatching each complete frame.
 *
 * Frames are separated by a blank line and can be split across reads, so the
 * tail of the buffer is carried forward rather than parsed early.
 */
async function consume(body: ReadableStream<Uint8Array>, callbacks: ChatTurnCallbacks): Promise<void> {
    const reader = body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';

    for (;;) {
        const { done, value } = await reader.read();

        if (done) {
            break;
        }

        buffer += decoder.decode(value, { stream: true });

        const frames = buffer.split('\n\n');
        // The final element is whatever came after the last separator, which
        // may be a partial frame — hold it until more bytes arrive.
        buffer = frames.pop() ?? '';

        for (const frame of frames) {
            if (dispatch(frame, callbacks)) {
                return;
            }
        }
    }

    // A stream can close without the sentinel — an error frame is terminal on
    // its own — so flush whatever is left rather than discarding it.
    buffer += decoder.decode();

    if (buffer.trim() !== '') {
        dispatch(buffer, callbacks);
    }
}

/**
 * Handle one raw frame.
 *
 * @return true when the stream is finished and reading should stop.
 */
function dispatch(frame: string, callbacks: ChatTurnCallbacks): boolean {
    const data = frame
        .split('\n')
        .filter((line) => line.startsWith('data:'))
        .map((line) => line.slice(5).trim())
        .join('');

    if (data === '') {
        return false;
    }

    if (data === DONE_SENTINEL) {
        return true;
    }

    let event: ChatStreamEvent;

    try {
        event = JSON.parse(data) as ChatStreamEvent;
    } catch {
        // A frame we cannot parse is not worth failing the turn over.
        return false;
    }

    switch (event.type) {
        case 'status':
            callbacks.onStatus?.(event.phase, event.message);

            return false;

        case 'message_start':
            callbacks.onStart?.();

            return false;

        case 'content_block_delta':
            callbacks.onText?.(event.delta.text);

            return false;

        case 'reasoning_block_delta':
            callbacks.onReasoning?.(event.delta.reasoning);

            return false;

        case 'message_delta':
            callbacks.onDone?.({
                stopReason: event.delta.stop_reason,
                usage: {
                    inputTokens: event.usage.input_tokens,
                    outputTokens: event.usage.output_tokens,
                },
            });

            return false;

        case 'message_stop':
            return false;

        case 'error':
            callbacks.onError?.({ message: event.message, reason: event.reason });

            // Terminal: no sentinel follows an error frame.
            return true;

        default:
            // An unrecognized type is a newer server talking to an older
            // client. Ignoring it keeps this forward-compatible.
            return false;
    }
}

function csrfHeader(): Record<string, string> {
    const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;

    return token ? { 'X-CSRF-TOKEN': token } : {};
}

function isAbortError(error: unknown): boolean {
    return error instanceof DOMException && error.name === 'AbortError';
}
