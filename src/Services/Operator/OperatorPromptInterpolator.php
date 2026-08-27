<?php

namespace Jvjvjv\CodeTalker\Services\Operator;

use Illuminate\Support\Arr;
use RuntimeException;

/**
 * Resolves an AiOperator::prompt_template's {{dotted.path}} placeholders
 * against the $context array supplied at dispatch time.
 *
 * Unlike AiPersona::prompt_template's fixed placeholder set (built by
 * SystemPromptBuilder against known fields on the persona/visitor), an
 * operator's placeholders are arbitrary and caller-defined, so resolution
 * happens generically against whatever context shape the dispatching code
 * passed to RunAiOperatorJob.
 */
class OperatorPromptInterpolator
{
    private const PLACEHOLDER_PATTERN = '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/';

    /**
     * @param array<string, mixed> $context
     *
     * @throws RuntimeException if a placeholder has no matching value in $context
     */
    public function interpolate(string $template, array $context): string
    {
        return (string) preg_replace_callback(
            self::PLACEHOLDER_PATTERN,
            function (array $matches) use ($context): string {
                $path = $matches[1];
                $value = Arr::get($context, $path);

                if ($value === null) {
                    throw new RuntimeException(
                        "Operator prompt template references \"{{$path}}\", which has no value in the dispatched context."
                    );
                }

                return is_scalar($value) ? (string) $value : json_encode($value);
            },
            $template,
        );
    }
}
