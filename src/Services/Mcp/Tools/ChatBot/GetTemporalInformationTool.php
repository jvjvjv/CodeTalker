<?php

namespace Jvjvjv\CodeTalker\Services\Mcp\Tools\ChatBot;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('get-temporal-information')]
#[Description(
    'Return the current date and time. Call this before answering anything date-relative — '
    . '"today", "this week", "how long until", "is that expired" — because your training data '
    . 'has a cutoff and the system prompt is static. Optionally accepts an IANA timezone '
    . '(e.g. America/New_York) or a fixed UTC offset (e.g. -05:00) to answer in that zone.'
)]
class GetTemporalInformationTool extends Tool
{
    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'timezone' => $schema->string()
                ->description(
                    'Optional. An IANA timezone identifier such as "America/New_York" or "Europe/Berlin", '
                    . 'or a fixed UTC offset such as "-05:00", "+0530", or "+5". '
                    . 'Defaults to the application timezone when omitted.'
                ),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $requested = trim((string) $request->get('timezone', ''));

        $zone = $requested === ''
            ? $this->applicationTimezone()
            : $this->resolveTimezone($requested);

        if ($zone === null) {
            return Response::error(sprintf(
                'Could not resolve "%s" as a timezone. Supply an IANA timezone identifier such as '
                . '"America/New_York", or a fixed UTC offset such as "-05:00", "+0530", or "+5".',
                $requested,
            ));
        }

        $now = CarbonImmutable::now($zone);

        return Response::structured([
            'iso8601' => $now->toIso8601String(),
            'utc_iso8601' => $now->setTimezone('UTC')->toIso8601String(),
            'timezone' => $zone->getName(),
            'utc_offset' => $now->format('P'),
            'unix_timestamp' => $now->getTimestamp(),
            'date' => $now->format('Y-m-d'),
            'time' => $now->format('H:i:s'),
            'day_of_week' => $now->format('l'),
            'human' => $now->format('l, F j, Y \a\t g:i A T'),
        ]);
    }

    /**
     * The host application's configured timezone, falling back to UTC when it
     * is missing or unusable.
     */
    private function applicationTimezone(): DateTimeZone
    {
        return $this->resolveTimezone((string) config('app.timezone', 'UTC')) ?? new DateTimeZone('UTC');
    }

    /**
     * Resolve an IANA identifier or a fixed UTC offset, or null when it is neither.
     *
     * Returning null rather than defaulting is deliberate: a confidently-wrong
     * time the model then reasons from is worse than a refusal it can correct.
     */
    private function resolveTimezone(string $value): ?DateTimeZone
    {
        $normalizedOffset = $this->normalizeUtcOffset($value);

        try {
            return new DateTimeZone($normalizedOffset ?? $value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Normalize `±H`, `±HH`, `±HHMM`, and `±HH:MM` to the `±HH:MM` form
     * DateTimeZone accepts. Returns null when the value is not an offset at all.
     */
    private function normalizeUtcOffset(string $value): ?string
    {
        $candidate = str_replace(' ', '', $value);

        if (preg_match('/^(?<sign>[+-])(?<hours>\d{1,2})(?::?(?<minutes>\d{2}))?$/', $candidate, $matches) !== 1) {
            return null;
        }

        $hours = (int) $matches['hours'];
        $minutes = (int) ($matches['minutes'] ?? 0);

        if ($hours > 14 || $minutes > 59) {
            return null;
        }

        return sprintf('%s%02d:%02d', $matches['sign'], $hours, $minutes);
    }
}
