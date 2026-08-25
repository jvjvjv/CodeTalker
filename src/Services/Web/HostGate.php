<?php

namespace Jvjvjv\CodeTalker\Services\Web;

/**
 * Decides whether an outbound request may be made, and to which address.
 *
 * One implementation serves both web tools. It answers two questions about a
 * URL — may we go there, and what address did we validate — and the second
 * answer is what {@see WebFetcher} pins into the connection so the destination
 * cannot change between the check and the socket.
 *
 * This is a guardrail, not a security boundary. A model acting against the
 * host's interest declares a permissive policy and walks through it. What the
 * gate buys is that accidents are stopped and intent is recorded.
 */
class HostGate
{
    /**
     * Per-instance resolution cache, so deciding and pinning cost one lookup.
     *
     * @var array<string, array<int, string>>
     */
    private array $resolved = [];

    /**
     * @param string $privateHostMessage sprintf template taking the host; supplied
     *                                   per tool so the remediation names inputs
     *                                   that tool's schema actually has
     */
    public function __construct(
        private readonly string $privateHostMessage,
    ) {}

    /**
     * A caller-facing refusal, or null when the request may proceed.
     */
    public function refuse(string $url, string $method, RequestPolicy $policy): ?string
    {
        if (trim($url) === '') {
            return 'A URL is required.';
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        // Not policy-negotiable: no legitimate declaration wants file://.
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return 'The URL must be a valid http or https address.';
        }

        if (!$policy->permits($method)) {
            return sprintf(
                'This request was not sent. You asked for %s, but the request_policy you declared allows only: %s. '
                . 'Either use an allowed method, or declare %s in request_policy.allowed_methods.',
                strtoupper($method),
                implode(', ', $policy->allowedMethods),
                strtoupper($method),
            );
        }

        if (!$policy->permitsHost($host)) {
            return sprintf(
                'This request was not sent. The host "%s" is not in the allowed_hosts you declared: %s.',
                $host,
                implode(', ', $policy->allowedHosts),
            );
        }

        if ($policy->allowPrivateHosts || !$this->isPrivateHost($host)) {
            return null;
        }

        return sprintf($this->privateHostMessage, $host);
    }

    /**
     * The address {@see refuse()} validated, for pinning into the connection.
     *
     * Reads the same cache the check populated, so the address the socket uses
     * is the address the decision was made about — not a second lookup that
     * could answer differently.
     */
    public function validatedAddressFor(string $url): ?string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '') {
            return null;
        }

        return $this->addressesForCached($host)[0] ?? null;
    }

    /**
     * Whether a host is, or resolves to, an address on a non-public network.
     */
    protected function isPrivateHost(string $host): bool
    {
        if ($host === '') {
            return true;
        }

        foreach ($this->addressesForCached($host) as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return true;
            }
        }

        return false;
    }

    /**
     * The IP addresses a host maps to — the literal itself, or its DNS records.
     *
     * A name that does not resolve is reported as private so an unresolvable
     * host fails the gate rather than slipping past it.
     *
     * Protected because it is the seam that keeps tests off the network: a test
     * subclass overrides it with a fixed host-to-address map.
     *
     * @return array<int, string>
     */
    protected function addressesFor(string $host): array
    {
        $unbracketed = trim($host, '[]');

        if (filter_var($unbracketed, FILTER_VALIDATE_IP) !== false) {
            return [$unbracketed];
        }

        if ($host === 'localhost') {
            return ['127.0.0.1'];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if ($records === false || $records === []) {
            return ['127.0.0.1'];
        }

        $addresses = [];

        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($address) && $address !== '') {
                $addresses[] = $address;
            }
        }

        return $addresses === [] ? ['127.0.0.1'] : $addresses;
    }

    /**
     * @return array<int, string>
     */
    private function addressesForCached(string $host): array
    {
        return $this->resolved[$host] ??= $this->addressesFor($host);
    }
}
