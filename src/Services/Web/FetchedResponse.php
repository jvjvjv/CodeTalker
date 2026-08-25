<?php

namespace Jvjvjv\CodeTalker\Services\Web;

/**
 * The outcome of one {@see WebFetcher} call.
 *
 * Carries either a decoded response or a caller-facing error string, so both
 * `fetch-web-page` and `http-request` can branch on one value instead of each
 * re-deriving the failure cases. Follows the package's value-object style:
 * a final class with promoted readonly properties and named static factories,
 * matching {@see \Jvjvjv\CodeTalker\Support\ToolContext} and
 * {@see \Jvjvjv\CodeTalker\Services\RawExchange\RawExchangeFrame}.
 */
final class FetchedResponse
{
    /**
     * @param mixed $content Decoded body — a string for text and HTML, an array for JSON and XML
     * @param array<int, string> $notes Non-fatal remarks for the model (a decode failure, a forced truncation)
     * @param array<int, string> $strippedHeaders Caller-supplied request headers refused by the header policy
     */
    private function __construct(
        public readonly string $url,
        public readonly ?int $status = null,
        public readonly ?string $contentType = null,
        public readonly ?string $title = null,
        public readonly mixed $content = null,
        public readonly bool $truncated = false,
        public readonly ?string $error = null,
        public readonly array $notes = [],
        public readonly array $strippedHeaders = [],
    ) {}

    /**
     * A response whose body was read and decoded.
     *
     * @param array<int, string> $notes
     * @param array<int, string> $strippedHeaders
     */
    public static function decoded(
        string $url,
        int $status,
        string $contentType,
        mixed $content,
        bool $truncated = false,
        ?string $title = null,
        array $notes = [],
        array $strippedHeaders = [],
    ): self {
        return new self(
            url: $url,
            status: $status,
            contentType: $contentType,
            title: $title,
            content: $content,
            truncated: $truncated,
            notes: $notes,
            strippedHeaders: $strippedHeaders,
        );
    }

    /**
     * A request that could not be made, or a response that could not be used.
     *
     * The message is returned to the model verbatim, so it must read as an
     * instruction rather than as a stack trace.
     */
    public static function failure(string $url, string $error, ?int $status = null): self
    {
        return new self(url: $url, status: $status, error: $error);
    }

    public function failed(): bool
    {
        return $this->error !== null;
    }

    /**
     * The same response with additional caller-facing notes appended.
     *
     * @param array<int, string> $notes
     */
    public function withNotes(array $notes): self
    {
        if ($notes === []) {
            return $this;
        }

        return new self(
            url: $this->url,
            status: $this->status,
            contentType: $this->contentType,
            title: $this->title,
            content: $this->content,
            truncated: $this->truncated,
            error: $this->error,
            notes: array_values(array_unique([...$this->notes, ...$notes])),
            strippedHeaders: $this->strippedHeaders,
        );
    }

    /**
     * The same response tagged with the request headers the policy refused.
     *
     * @param array<int, string> $strippedHeaders
     */
    public function withStrippedHeaders(array $strippedHeaders): self
    {
        return new self(
            url: $this->url,
            status: $this->status,
            contentType: $this->contentType,
            title: $this->title,
            content: $this->content,
            truncated: $this->truncated,
            error: $this->error,
            notes: $this->notes,
            strippedHeaders: $strippedHeaders,
        );
    }
}
