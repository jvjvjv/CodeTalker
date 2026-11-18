<?php

namespace Jvjvjv\CodeTalker\Support;

use InvalidArgumentException;

/**
 * Cleans the visitor name and email a host passes when opening a conversation.
 *
 * Both are typed by the visitor and both are interpolated into the system
 * prompt, so a line break in a name is a line the visitor wrote into the
 * prompt. They are also written to 255-character columns, where an over-long
 * value would surface as a database exception rather than a readable error.
 */
final class VisitorIdentity
{
    public const MAX_LENGTH = 255;

    private function __construct(
        public readonly ?string $name,
        public readonly ?string $email,
    ) {
    }

    /**
     * @throws InvalidArgumentException naming the field that was refused
     */
    public static function sanitise(?string $name, ?string $email): self
    {
        $name = self::clean('visitor_name', $name);
        $email = self::clean('visitor_email', $email);

        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('The visitor_email must be a valid email address.');
        }

        return new self($name, $email);
    }

    /**
     * Control characters and line/paragraph separators become a space, runs of
     * whitespace collapse, and the result is trimmed — so a value always reads
     * as one line. Blank after cleaning is treated as absent.
     */
    private static function clean(string $field, ?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $cleaned = preg_replace(['/[\p{C}\p{Zl}\p{Zp}]+/u', '/\s+/u'], ' ', $value);

        // preg_replace() returns null on input that is not valid UTF-8.
        if ($cleaned === null) {
            throw new InvalidArgumentException("The {$field} must be valid UTF-8 text.");
        }

        $cleaned = trim($cleaned);

        if ($cleaned === '') {
            return null;
        }

        if (mb_strlen($cleaned) > self::MAX_LENGTH) {
            throw new InvalidArgumentException(
                "The {$field} must not be longer than " . self::MAX_LENGTH . ' characters.'
            );
        }

        return $cleaned;
    }
}
