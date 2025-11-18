<?php

declare(strict_types=1);

namespace mcxForge\Tests;

require_once __DIR__ . '/../../../bin/benchmarkStorageIOPing.php';

final class benchmarkStorageIOPingParseTest extends testCase
{
    public function testParseIOPSFromSummaryLine(): void
    {
        $lines = [
            '--- /dev/sda (block device 232 GiB) ioping statistics ---',
            '10 requests completed in 9.0 ms, 4 KiB read, 1.11 k iops, 4.52 MiB/s',
        ];

        $score = \benchmarkStorageIOPingParseIOPS($lines);
        $this->assertEquals(1110.0, $score);
    }

    public function testParseIOPSFromSummaryLineWithoutKPrefix(): void
    {
        $lines = [
            '10 requests completed in 9.0 ms, 4 KiB read, 123.4 iops, 4.52 MiB/s',
        ];

        $score = \benchmarkStorageIOPingParseIOPS($lines);
        $this->assertEquals(123.4, $score);
    }

    public function testParseIOPSReturnsNullWhenNoSummaryPresent(): void
    {
        $lines = [
            'random line',
            'another line without iops',
        ];

        $score = \benchmarkStorageIOPingParseIOPS($lines);
        $this->assertTrue($score === null);
    }

    public function testParseArgumentsDefaults(): void
    {
        [$device, $count, $scoreOnly, $colorEnabled] = \benchmarkStorageIOPingParseArguments(
            ['benchmarkStorageIOPing.php']
        );

        $this->assertTrue($device === null);
        $this->assertEquals(10, $count);
        $this->assertTrue($scoreOnly === false);
        $this->assertTrue($colorEnabled === true);
    }

    public function testParseArgumentsWithDeviceAndCount(): void
    {
        [$device, $count] = \benchmarkStorageIOPingParseArguments(
            ['benchmarkStorageIOPing.php', '--device=/dev/sda', '--count=25']
        );

        $this->assertEquals('/dev/sda', $device);
        $this->assertEquals(25, $count);
    }
}
