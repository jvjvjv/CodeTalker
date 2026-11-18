<?php

namespace Jvjvjv\CodeTalker\Support;

use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Jvjvjv\CodeTalker\Services\Web\WebFetcher;
use Throwable;

/**
 * Log context for a failed outbound call that keeps what an operator needs to
 * diagnose it (the exception class, the host and port, the HTTP status) and
 * drops what should not reach a log.
 *
 * The throwable itself is never included: a logger writes its stack trace, and
 * the trace's arguments can hold the URL or the key. A transport failure's
 * message is kept with userinfo and credential query parameters masked. A
 * response error is reduced to its status, because its message quotes the
 * response body, which can echo the request's key back. Anything else is
 * reduced to its class.
 */
final class RedactedException
{
    /**
     * @return array{exception: class-string<Throwable>, status?: int, error?: string}
     */
    public static function context(Throwable $e): array
    {
        $context = ['exception' => $e::class];

        if ($e instanceof RequestException) {
            $context['status'] = $e->response->status();

            return $context;
        }

        if ($e instanceof BadResponseException) {
            $context['status'] = $e->getResponse()->getStatusCode();

            return $context;
        }

        // Only a transport failure's message is kept: it is cURL's text plus
        // the URL, which redactText() knows how to mask. Any other exception's
        // message could carry anything, so it is reduced to its class.
        if ($e instanceof ConnectionException || $e instanceof TransferException) {
            $context['error'] = WebFetcher::redactText($e->getMessage());
        }

        return $context;
    }
}
