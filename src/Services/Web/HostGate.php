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
     * Ranges refused alongside what filter_var's private and reserved flags
     * already cover: shared address space, IETF protocol assignments,
     * benchmarking, multicast, NAT64, 6to4, and deprecated site-local.
     */
    private const PRIVATE_CIDRS = [
        '100.64.0.0/10',
        '192.0.0.0/24',
        '198.18.0.0/15',
        '224.0.0.0/4',
        '64:ff9b::/96',
        '2002::/16',
        'fec0::/10',
    ];

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
     *
     * @param array<int, string>|null $allowedDomains an AiSystem-scoped allow-list;
     *        null or empty means unrestricted. Checked before DNS resolution and
     *        ahead of the model-declared policy, since it is an operator-set
     *        boundary rather than a guardrail the model can negotiate.
     * @param bool $fromExternalCaller the tool runs without a conversation (the
     *        external MCP transport), where allow_private_hosts is not honored
     * @param bool $methodFromRedirect the method was set by a 301/302/303
     *        rewrite rather than chosen by the caller, so the declared
     *        allowed_methods does not apply to it
     */
    public function refuse(
        string $url,
        string $method,
        RequestPolicy $policy,
        ?array $allowedDomains = null,
        bool $fromExternalCaller = false,
        bool $methodFromRedirect = false,
    ): ?string {
        if (trim($url) === '') {
            return 'A URL is required.';
        }

        // The HTTP client turns userinfo into an Authorization header, which
        // would bypass the credential-header rules entirely.
        if (parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
            return 'This request was not sent. Credentials in the URL (user:password@host) are not accepted. '
                . 'Remove them from the URL and call this tool again.';
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        // Not policy-negotiable: no legitimate declaration wants file://.
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return 'The URL must be a valid http or https address.';
        }

        if ($fromExternalCaller && $policy->allowPrivateHosts) {
            return 'This request was not sent. Private hosts cannot be declared on this transport: '
                . 'allow_private_hosts is honored only inside a chat conversation. Remove it to reach public hosts.';
        }

        if ($allowedDomains !== null && $allowedDomains !== [] && !in_array($host, array_map('strtolower', $allowedDomains), true)) {
            return sprintf(
                'This request was not sent. The host "%s" is not on this system\'s allowed domain list: %s.',
                $host,
                implode(', ', $allowedDomains),
            );
        }

        if (!$methodFromRedirect && !$policy->permits($method)) {
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
     * could answer differently. Null when the host did not resolve: such a
     * host passes the gate only under allow_private_hosts, and is then left
     * for the client to resolve rather than pinned to a substitute.
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

        $addresses = $this->addressesForCached($host);

        // An unresolvable host fails the gate rather than slipping past it.
        if ($addresses === []) {
            return true;
        }

        foreach ($addresses as $address) {
            if (self::isPrivateAddress($address)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an IP literal is loopback (127.0.0.0/8 or ::1).
     */
    public static function isLoopbackAddress(string $address): bool
    {
        $address = trim($address, '[]');

        return self::inCidr($address, '127.0.0.0/8') || self::inCidr($address, '::1/128');
    }

    private static function isPrivateAddress(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }

        foreach (self::PRIVATE_CIDRS as $cidr) {
            if (self::inCidr($address, $cidr)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an address falls inside a CIDR range of the same family.
     */
    private static function inCidr(string $address, string $cidr): bool
    {
        [$network, $prefix] = explode('/', $cidr, 2);

        $addressBytes = @inet_pton($address);
        $networkBytes = @inet_pton($network);

        if ($addressBytes === false || $networkBytes === false || strlen($addressBytes) !== strlen($networkBytes)) {
            return false;
        }

        $prefix = (int) $prefix;
        $fullBytes = intdiv($prefix, 8);

        if (strncmp($addressBytes, $networkBytes, $fullBytes) !== 0) {
            return false;
        }

        $remainingBits = $prefix % 8;

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($addressBytes[$fullBytes]) & $mask) === (ord($networkBytes[$fullBytes]) & $mask);
    }

    /**
     * The IP addresses a host maps to — the literal itself, or its DNS records.
     *
     * Empty when the name does not resolve. {@see isPrivateHost()} treats that
     * as private, and {@see validatedAddressFor()} as nothing to pin.
     * `localhost` is always `127.0.0.1` (RFC 6761 reserves it for loopback):
     * dns_get_record() does not read the hosts file, so without this a
     * permitted `http://localhost` would go unpinned and lose the loopback
     * exemption that lets configured credentials attach over plain http.
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

        if (strtolower($host) === 'localhost') {
            return ['127.0.0.1'];
        }

        return $this->dnsAddressesFor($host);
    }

    /**
     * The A and AAAA records DNS returns for a name; empty when it has none.
     *
     * Protected so a test can stand in for DNS while still exercising
     * {@see addressesFor()}'s own rules.
     *
     * @return array<int, string>
     */
    protected function dnsAddressesFor(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if ($records === false || $records === []) {
            return [];
        }

        $addresses = [];

        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($address) && $address !== '') {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }

    /**
     * @return array<int, string>
     */
    private function addressesForCached(string $host): array
    {
        return $this->resolved[$host] ??= $this->addressesFor($host);
    }
}
