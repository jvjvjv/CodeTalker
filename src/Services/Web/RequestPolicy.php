<?php

namespace Jvjvjv\CodeTalker\Services\Web;

/**
 * What a caller has declared it intends an outbound request to do.
 *
 * Both web tools gate on one of these. `http-request` builds it from a required
 * `request_policy` input; `fetch-web-page` builds it from an optional one and
 * falls back to {@see publicHostsOnly()}.
 *
 * An empty $allowedMethods means "no method restriction", not "refuse
 * everything". Fail-closed-on-absence is `http-request`'s rule and lives in
 * that tool, which refuses an empty declaration before a policy is ever built —
 * encoding one tool's strictness into a type both share would force the same
 * friction onto a GET-only tool whose safe default is unambiguous.
 */
final class RequestPolicy
{
    /**
     * @param array<int, string> $allowedMethods Uppercased; empty means unrestricted
     * @param array<int, string> $allowedHosts Lowercased; empty means no host allow-list
     */
    private function __construct(
        public readonly array $allowedMethods,
        public readonly bool $allowPrivateHosts,
        public readonly array $allowedHosts,
    ) {}

    /**
     * Parse a caller-supplied `request_policy` input.
     *
     * @param array<string, mixed> $input
     */
    public static function declared(array $input): self
    {
        return new self(
            allowedMethods: self::normalizeList($input['allowed_methods'] ?? [], strtoupper(...)),
            allowPrivateHosts: ($input['allow_private_hosts'] ?? false) === true,
            allowedHosts: self::normalizeList($input['allowed_hosts'] ?? [], strtolower(...)),
        );
    }

    /**
     * The default when nothing was declared: public hosts only, no other limits.
     *
     * The declaration is optional; the permission is not. Absence behaves
     * exactly as an explicit `allow_private_hosts: false`.
     */
    public static function publicHostsOnly(): self
    {
        return new self(allowedMethods: [], allowPrivateHosts: false, allowedHosts: []);
    }

    public function restrictsMethods(): bool
    {
        return $this->allowedMethods !== [];
    }

    public function restrictsHosts(): bool
    {
        return $this->allowedHosts !== [];
    }

    public function permits(string $method): bool
    {
        return !$this->restrictsMethods() || in_array(strtoupper($method), $this->allowedMethods, true);
    }

    public function permitsHost(string $host): bool
    {
        return !$this->restrictsHosts() || in_array(strtolower($host), $this->allowedHosts, true);
    }

    /**
     * @param callable(string): string $normalize
     * @return array<int, string>
     */
    private static function normalizeList(mixed $values, callable $normalize): array
    {
        return array_values(array_filter(array_map(
            static fn ($value): string => $normalize(trim((string) $value)),
            (array) $values,
        )));
    }
}
