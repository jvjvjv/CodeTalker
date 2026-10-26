<?php

namespace Jvjvjv\CodeTalker\Services\Mcp\Remote;

use Illuminate\Support\Collection;
use Laravel\Mcp\Client;
use Laravel\Mcp\Client\Methods\Tools\ListTools;
use Laravel\Mcp\Client\Primitives\Tool;
use Laravel\Mcp\Client\Protocol;
use RuntimeException;
use stdClass;

/**
 * `tools/list`, bounded in tools and in pages.
 *
 * laravel/mcp's listing follows `nextCursor` until the server stops sending
 * one. It refuses a repeated cursor, but a server that answers every page
 * with a fresh cursor — empty pages included — keeps a sync looping forever,
 * and one that lists a million tools fills memory before anything is stored.
 * Both limits throw, so the sync records a failure and the stored catalog is
 * left as it was, rather than a silently truncated listing replacing it.
 */
class BoundedToolListing extends ListTools
{
    private readonly stdClass $pages;

    public function __construct(
        ?Client $client,
        private readonly int $maxTools,
        private readonly int $maxPages,
        ?string $cursor = null,
        ?stdClass $pages = null,
    ) {
        // One past the bound, so a listing that exceeds it can be told apart
        // from one that exactly meets it.
        parent::__construct($client, $cursor, $maxTools + 1);

        $this->pages = $pages ?? (object) ['count' => 0];
    }

    /**
     * @return Collection<string, Tool>
     *
     * @throws RuntimeException naming the bound the server exceeded
     */
    public static function fetchFrom(Client $client, int $maxTools, int $maxPages): Collection
    {
        // Client::tools() offers no hook to bound pages, so the method is
        // dispatched on the client's own protocol, as tools() itself does.
        $protocol = (fn (): Protocol => $this->protocol)->call($client);

        $tools = (new self($client, $maxTools, $maxPages))->handle($protocol);

        if ($tools->count() > $maxTools) {
            throw new RuntimeException("Server lists more than {$maxTools} tools.");
        }

        return $tools;
    }

    protected function nextPage(?string $cursor): static
    {
        if (++$this->pages->count > $this->maxPages) {
            throw new RuntimeException("Server lists more than {$this->maxPages} pages of tools.");
        }

        return new static($this->client, $this->maxTools, $this->maxPages, $cursor, $this->pages);
    }
}
