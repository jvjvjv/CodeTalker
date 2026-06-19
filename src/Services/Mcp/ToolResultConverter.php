<?php

namespace Jvjvjv\CodeTalker\Services\Mcp;

use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

/**
 * Normalises whatever a laravel/mcp Tool's handle() returns into the plain
 * associative array the local agentic loop consumes.
 *
 * Tools return a {@see Response} (e.g. Response::error) or a
 * {@see ResponseFactory} (e.g. Response::structured), and may also return a
 * raw array/string. The loop expects an array — structured payloads are
 * returned verbatim (preserving conventions like an `error` key or the
 * `_page_reload` side-channel), and plain text is wrapped as `content`.
 */
class ToolResultConverter
{
    /**
     * @param  Response|ResponseFactory|array<string, mixed>|string  $result
     * @return array<string, mixed>
     */
    public static function toArray(Response|ResponseFactory|array|string $result): array
    {
        if (is_array($result)) {
            return $result;
        }

        if (is_string($result)) {
            return ['content' => $result];
        }

        if ($result instanceof ResponseFactory) {
            $structured = $result->getStructuredContent();

            if ($structured !== null) {
                return $structured;
            }

            $responses = $result->responses();
            $isError = $responses->contains(fn (Response $r): bool => $r->isError());
            $text = $responses->map(fn (Response $r): string => (string) $r->content())->implode("\n");

            return $isError ? ['error' => $text] : ['content' => $text];
        }

        // Single Response.
        $text = (string) $result->content();

        if ($result->isError()) {
            return ['error' => $text];
        }

        return ['content' => $text];
    }
}
