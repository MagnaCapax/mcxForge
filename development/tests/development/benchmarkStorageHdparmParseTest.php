<?php

declare(strict_types=1);

namespace mcxForge\Tests;

require_once __DIR__ . '/../../../bin/benchmarkStorageHdparm.php';

final class benchmarkStorageHdparmParseTest extends testCase
{
    public function testParseBufferedReadMbps(): void
    {
        $lines = [
            '/dev/sda:',
            ' Timing cached reads:   1234 MB in  2.00 seconds = 617.0 MB/sec',
            ' Timing buffered disk reads:  1234 MB in  3.00 seconds = 411.5 MB/sec',
        ];

        $mbps = \benchmarkStorageHdparmParseBufferedReadMbps($lines);
        $this->assertEquals(411.5, $mbps);
    }

    public function testParseBufferedReadMbpsReturnsNullWhenNoMatch(): void
    {
        $lines = [
            'random line',
            'another line',
        ];

        $mbps = \benchmarkStorageHdparmParseBufferedReadMbps($lines);
        $this->assertTrue($mbps === null);
    }

    public function testParseArgumentsDefaults(): void
    {
        [$device, $runs, $scoreOnly, $colorEnabled] = \benchmarkStorageHdparmParseArguments(
            ['benchmarkStorageHdparm.php']
        );

        $this->assertTrue($device === null);
        $this->assertEquals(1, $runs);
        $this->assertTrue($scoreOnly === false);
        $this->assertTrue($colorEnabled === true);
    }
}

