<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Jvjvjv\CodeTalker\Services\Web\HostGate;
use Jvjvjv\CodeTalker\Services\Web\RequestPolicy;
use Jvjvjv\CodeTalker\Tests\TestCase;

class HostGateTest extends TestCase
{
    /**
     * A gate whose names resolve from a fixed map, so no test touches DNS.
     * IP literals still go through the real implementation.
     */
    private function gate(): HostGate
    {
        return new class('refused: %s') extends HostGate {
            /** @return array<int, string> */
            protected function addressesFor(string $host): array
            {
                return match ($host) {
                    'public.test' => ['93.184.216.34'],
                    'nowhere.test' => [],
                    'mixed.test' => ['93.184.216.34', '10.0.0.5'],
                    default => parent::addressesFor($host),
                };
            }
        };
    }

    private function refuse(string $url, ?RequestPolicy $policy = null, bool $fromExternalCaller = false): ?string
    {
        return $this->gate()->refuse($url, 'GET', $policy ?? RequestPolicy::publicHostsOnly(), null, $fromExternalCaller);
    }

    private function permissive(): RequestPolicy
    {
        return RequestPolicy::declared(['allow_private_hosts' => true]);
    }

    // -------------------------------------------------------------- userinfo

    public function testAUrlCarryingUserinfoIsRefusedBeforeAnyOtherCheck(): void
    {
        foreach (['https://user@public.test/', 'https://user:pass@public.test/', 'https://token@127.0.0.1/'] as $url) {
            $refusal = $this->refuse($url, $this->permissive());

            $this->assertNotNull($refusal, $url . ' should be refused');
            $this->assertStringContainsString('Credentials in the URL', $refusal);
            $this->assertStringNotContainsString($url, $refusal);
        }
    }

    // ----------------------------------------------------------- resolution

    public function testAnUnresolvableHostIsRefused(): void
    {
        $this->assertSame('refused: nowhere.test', $this->refuse('https://nowhere.test/'));
    }

    public function testAnUnresolvableHostIsNeverGivenASubstituteAddress(): void
    {
        $gate = $this->gate();

        $this->assertNull($gate->refuse('http://nowhere.test/', 'GET', $this->permissive()));
        $this->assertNull($gate->validatedAddressFor('http://nowhere.test/'));
    }

    public function testLocalhostIsLoopbackWithoutADnsLookup(): void
    {
        // DNS that knows nothing, as on a host whose resolver does not answer
        // for localhost; dns_get_record() never reads the hosts file.
        $gate = new class('refused: %s') extends HostGate {
            /** @return array<int, string> */
            protected function dnsAddressesFor(string $host): array
            {
                return [];
            }
        };

        $this->assertSame('refused: localhost', $gate->refuse('http://localhost:8080/', 'GET', RequestPolicy::publicHostsOnly()));
        $this->assertNull($gate->refuse('http://LocalHost:8080/', 'GET', $this->permissive()));
        $this->assertSame('127.0.0.1', $gate->validatedAddressFor('http://LocalHost:8080/'));
    }

    public function testAHostWithAnyPrivateAddressIsRefused(): void
    {
        $this->assertSame('refused: mixed.test', $this->refuse('https://mixed.test/'));
    }

    // ------------------------------------------------------------ deny list

    public function testAddressesOutsideFilterVarsRangesAreRefused(): void
    {
        foreach ([
            '100.100.100.200',   // shared address space
            '192.0.0.1',         // IETF protocol assignments
            '198.18.0.1',        // benchmarking
            '224.0.0.1',         // multicast
            '239.255.255.250',
            '[64:ff9b::7f00:1]', // NAT64
            '[2002:7f00:1::]',   // 6to4
            '[fec0::1]',         // site-local
        ] as $host) {
            $this->assertNotNull($this->refuse('http://' . $host . '/'), $host . ' should be refused');
        }
    }

    public function testRangesFilterVarAlreadyCoversAreStillRefused(): void
    {
        foreach (['127.0.0.1', '10.0.0.5', '169.254.169.254', '0.0.0.0', '[::1]', '[::ffff:10.0.0.1]', '[fc00::1]'] as $host) {
            $this->assertNotNull($this->refuse('http://' . $host . '/'), $host . ' should be refused');
        }
    }

    public function testPublicAddressesAreNotRefused(): void
    {
        foreach (['8.8.8.8', '100.128.0.1', '198.20.0.1', '[2001:4860:4860::8888]', 'public.test'] as $host) {
            $this->assertNull($this->refuse('http://' . $host . '/'), $host . ' should be permitted');
        }
    }

    // ------------------------------------------------------------- transport

    public function testAnExternalCallerCannotDeclarePrivateHosts(): void
    {
        $refusal = $this->refuse('http://127.0.0.1/', $this->permissive(), fromExternalCaller: true);

        $this->assertStringContainsString('cannot be declared on this transport', (string) $refusal);
    }

    public function testAnExternalCallerReachesPublicHosts(): void
    {
        $this->assertNull($this->refuse('https://public.test/', fromExternalCaller: true));
    }

    // --------------------------------------------------------------- method

    public function testARedirectInducedMethodIsNotCheckedAgainstTheDeclaredMethods(): void
    {
        $policy = RequestPolicy::declared(['allowed_methods' => ['POST']]);

        $this->assertNotNull($this->gate()->refuse('https://public.test/', 'GET', $policy));
        $this->assertNull($this->gate()->refuse('https://public.test/', 'GET', $policy, methodFromRedirect: true));
    }

    public function testLoopbackAddressesAreRecognised(): void
    {
        $this->assertTrue(HostGate::isLoopbackAddress('127.0.0.1'));
        $this->assertTrue(HostGate::isLoopbackAddress('127.8.9.10'));
        $this->assertTrue(HostGate::isLoopbackAddress('::1'));
        $this->assertFalse(HostGate::isLoopbackAddress('10.0.0.5'));
        $this->assertFalse(HostGate::isLoopbackAddress('93.184.216.34'));
    }
}
