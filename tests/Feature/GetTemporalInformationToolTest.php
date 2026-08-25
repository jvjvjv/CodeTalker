<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Carbon\CarbonImmutable;
use Jvjvjv\CodeTalker\Services\Mcp\ToolResultConverter;
use Jvjvjv\CodeTalker\Services\Mcp\Tools\ChatBot\GetTemporalInformationTool;
use Jvjvjv\CodeTalker\Tests\TestCase;
use Laravel\Mcp\Request;

class GetTemporalInformationToolTest extends TestCase
{
    /** A fixed instant every assertion in this file is anchored to. */
    private const FROZEN_UTC = '2026-08-25T18:32:07+00:00';

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::FROZEN_UTC));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function runTool(array $input = []): array
    {
        return ToolResultConverter::toArray((new GetTemporalInformationTool())->handle(new Request($input)));
    }

    public function testItAnswersInTheApplicationTimezoneWhenNoneIsGiven(): void
    {
        config(['app.timezone' => 'Europe/Berlin']);

        $result = $this->runTool();

        $this->assertSame('Europe/Berlin', $result['timezone']);
        $this->assertSame('2026-08-25', $result['date']);
        $this->assertSame('20:32:07', $result['time']);
    }

    public function testItAnswersInARequestedIanaTimezone(): void
    {
        $result = $this->runTool(['timezone' => 'America/New_York']);

        $this->assertSame('America/New_York', $result['timezone']);
        $this->assertSame('2026-08-25', $result['date']);
        $this->assertSame('14:32:07', $result['time']);
        $this->assertSame('Tuesday', $result['day_of_week']);
        $this->assertSame('-04:00', $result['utc_offset']);
    }

    public function testTheUnderlyingInstantDoesNotChangeWithTheZone(): void
    {
        $utc = $this->runTool(['timezone' => 'UTC']);
        $tokyo = $this->runTool(['timezone' => 'Asia/Tokyo']);

        $this->assertSame($utc['unix_timestamp'], $tokyo['unix_timestamp']);
        $this->assertSame($utc['utc_iso8601'], $tokyo['utc_iso8601']);

        $this->assertNotSame($utc['iso8601'], $tokyo['iso8601']);
        $this->assertNotSame($utc['time'], $tokyo['time']);
        $this->assertNotSame($utc['utc_offset'], $tokyo['utc_offset']);
        $this->assertSame('2026-08-25', $utc['date']);
        $this->assertSame('2026-08-26', $tokyo['date']);
    }

    public function testItAcceptsAUtcOffsetWithAColon(): void
    {
        $result = $this->runTool(['timezone' => '-05:00']);

        $this->assertSame('-05:00', $result['utc_offset']);
        $this->assertSame('13:32:07', $result['time']);
    }

    public function testItAcceptsOffsetsWithoutAColonAndBareHourOffsets(): void
    {
        $withoutColon = $this->runTool(['timezone' => '+0530']);
        $bareHour = $this->runTool(['timezone' => '+5']);

        $this->assertSame('+05:30', $withoutColon['utc_offset']);
        $this->assertSame('00:02:07', $withoutColon['time']);

        $this->assertSame('+05:00', $bareHour['utc_offset']);
        $this->assertSame('23:32:07', $bareHour['time']);
    }

    public function testItRefusesAnUnresolvableTimezoneInsteadOfDefaulting(): void
    {
        foreach (['Pacific/Nowhere', 'EST5EDT7', 'yesterday'] as $unresolvable) {
            $result = $this->runTool(['timezone' => $unresolvable]);

            $this->assertArrayHasKey('error', $result, $unresolvable . ' should be refused');
            $this->assertStringContainsString('IANA timezone identifier', $result['error']);
            $this->assertStringContainsString('-05:00', $result['error']);
            $this->assertArrayNotHasKey('iso8601', $result);
        }
    }

    public function testTheResponseCarriesPreComputedCalendarParts(): void
    {
        $result = $this->runTool(['timezone' => 'America/New_York']);

        $this->assertSame('2026-08-25T14:32:07-04:00', $result['iso8601']);
        $this->assertSame('2026-08-25T18:32:07+00:00', $result['utc_iso8601']);
        $this->assertSame(1787682727, $result['unix_timestamp']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $result['date']);
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}:\d{2}$/', $result['time']);
        $this->assertStringContainsString('Tuesday', $result['human']);
        $this->assertStringContainsString('August 25, 2026', $result['human']);
        $this->assertStringContainsString('2:32 PM', $result['human']);
    }

    public function testEveryFieldReflectsTheFrozenInstant(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('1999-12-31T23:59:59+00:00'));

        $result = $this->runTool(['timezone' => 'UTC']);

        $this->assertSame('1999-12-31', $result['date']);
        $this->assertSame('23:59:59', $result['time']);
        $this->assertSame('Friday', $result['day_of_week']);
        $this->assertSame(946684799, $result['unix_timestamp']);
    }
}
