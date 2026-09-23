<?php

namespace Jvjvjv\CodeTalker\Services\Mcp\Remote;

use RuntimeException;

/**
 * Thrown for a call to a server whose breaker tripped earlier in the turn.
 */
class RemoteServerUnavailable extends RuntimeException
{
}
