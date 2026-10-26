<?php

namespace Jvjvjv\CodeTalker\Services\ChatBot\Conversation;

/**
 * The text a turn's terminal `error` event carries, chosen by its `reason`.
 *
 * Never the exception's own message. A provider or transport failure's text
 * names the provider's host and port, echoes the request, or quotes a 4xx body
 * — none of which belongs in a browser. The exception goes to the log and to
 * the turn's failure record instead; the event says only what kind of failure
 * it was.
 */
final class TurnErrorMessage
{
    public const PROVIDER_ERROR = 'provider_error';

    public const MAX_STREAM_DURATION = 'max_stream_duration';

    public const PERSONA_UNAVAILABLE = 'persona_unavailable';

    public const SYSTEM_UNAVAILABLE = 'system_unavailable';

    private const MESSAGES = [
        self::PROVIDER_ERROR => 'The assistant could not complete this response. Please try again.',
        self::MAX_STREAM_DURATION => 'The response exceeded the maximum stream duration and was stopped.',
        self::PERSONA_UNAVAILABLE => 'This assistant is no longer available.',
        self::SYSTEM_UNAVAILABLE => 'This assistant is not configured to respond right now.',
    ];

    public static function for(string $reason): string
    {
        return self::MESSAGES[$reason] ?? self::MESSAGES[self::PROVIDER_ERROR];
    }

    /**
     * The terminal event itself, so every producer yields the same shape.
     *
     * @return array{type: 'error', message: string, reason: string}
     */
    public static function event(string $reason): array
    {
        return [
            'type' => 'error',
            'message' => self::for($reason),
            'reason' => $reason,
        ];
    }
}
