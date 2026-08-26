<?php

namespace Jvjvjv\CodeTalker\Services\Web;

/**
 * An AiSystem's `web_tool_policy`: the domains its web tools may reach, and
 * any credentials attached for those domains. Absent entirely (the default
 * for every existing AiSystem) means unrestricted, matching the tool's
 * behavior before this policy existed.
 */
final class WebToolPolicy
{
    /**
     * @param array<int, string>|null $allowedDomains null/empty means unrestricted
     * @param array<string, array<string, string>> $credentials host => header map
     */
    public function __construct(
        public readonly ?array $allowedDomains = null,
        public readonly array $credentials = [],
    ) {}

    /**
     * Build from an AiSystem's decoded `web_tool_policy` column.
     *
     * @param array<string, mixed>|null $policy
     */
    public static function fromArray(?array $policy): self
    {
        if ($policy === null) {
            return new self();
        }

        $domains = $policy['allowed_domains'] ?? null;
        $credentials = $policy['credentials'] ?? [];

        return new self(
            allowedDomains: is_array($domains) ? array_map('strval', $domains) : null,
            credentials: is_array($credentials) ? $credentials : [],
        );
    }

    /**
     * The credential headers configured on this policy for a host, exact
     * match, case-insensitive — empty when this policy has none for it.
     *
     * @return array<string, string>
     */
    public function credentialsFor(string $host): array
    {
        $host = strtolower(trim($host));

        foreach ($this->credentials as $configuredHost => $headers) {
            if (strtolower(trim((string) $configuredHost)) === $host && is_array($headers)) {
                return array_map('strval', $headers);
            }
        }

        return [];
    }
}
