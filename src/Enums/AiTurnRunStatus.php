<?php

namespace Jvjvjv\CodeTalker\Enums;

enum AiTurnRunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Abandoned = 'abandoned';

    /**
     * Whether the run is over. A reader stops on this — and only after one
     * final drain, because the job appends its last event before marking the
     * run finished.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Queued, self::Running => false,
            self::Completed, self::Failed, self::Cancelled, self::Abandoned => true,
        };
    }
}
