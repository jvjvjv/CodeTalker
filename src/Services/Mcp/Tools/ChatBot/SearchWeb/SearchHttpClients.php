<?php

namespace Jvjvjv\CodeTalker\Services\Mcp\Tools\ChatBot\SearchWeb;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Jvjvjv\CodeTalker\Support\ToolContext;
use Jvjvjv\CodeTalker\Support\WebScraperUserAgent;

/**
 * The two HTTP clients the engines use.
 *
 * Scraping a results page needs browser-like headers and an identifiable
 * user agent; calling an official API does not.
 */
final class SearchHttpClients
{
    public function __construct(
        private ToolContext $context,
    ) {
    }

    public function web(): PendingRequest
    {
        return Http::connectTimeout(10)
            ->timeout(20)
            ->withHeaders([
                'User-Agent' => WebScraperUserAgent::forBotName($this->context->botName()),
                'Accept' => 'text/html,application/xhtml+xml,application/json,text/plain;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.5',
                'Accept-Encoding' => 'gzip, deflate, br',
                'Cache-Control' => 'no-cache',
                'Pragma' => 'no-cache',
                'Upgrade-Insecure-Requests' => '1',
            ]);
    }

    public function api(): PendingRequest
    {
        return Http::connectTimeout(10)
            ->timeout(20)
            ->withHeaders([
                'Accept' => 'application/json,text/plain;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.5',
            ]);
    }
}
