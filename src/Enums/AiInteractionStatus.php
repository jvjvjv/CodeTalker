<?php

namespace Jvjvjv\CodeTalker\Enums;

enum AiInteractionStatus: string
{
    case Success = 'success';
    case Error = 'error';

    /**
     * The turn ran but never finished: the caller (usually the browser) hung
     * up mid-stream. Not an error — nothing failed, and the tokens it burned
     * still count towards the conversation's usage — but not a success either,
     * because the answer it was producing is incomplete.
     */
    case Aborted = 'aborted';
}
