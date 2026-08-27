<?php

namespace Jvjvjv\CodeTalker\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Jvjvjv\CodeTalker\Models\AiOperator;
use Jvjvjv\CodeTalker\Services\Operator\AiOperatorRunner;

/**
 * Runs one AiOperator dispatch. The package places no constraint on what
 * triggers this — a host observer, an event listener, a console command, or
 * the package's own code all dispatch it the same way ProcessAiMemoryJob is
 * dispatched from AiConversationObserver.
 */
class RunAiOperatorJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public AiOperator $operator,
        public array $context = [],
    ) {
    }

    public function handle(AiOperatorRunner $runner): void
    {
        $runner->run($this->operator, $this->context);
    }
}
