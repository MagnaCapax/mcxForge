<?php

declare(strict_types=1);

namespace mcxForge\Tests;

require_once __DIR__ . '/../../../bin/benchmarkStorageSequentialRead.php';

final class benchmarkStorageSequentialReadParseTest extends testCase
{
    public function testParseMbpsFromDdSummaryMb(): void
    {
        $lines = [
            '1073741824 bytes (1.1 GB, 1.0 GiB) copied, 5.0001 s, 214.7 MB/s',
        ];

        $mbps = \benchmarkStorageSequentialReadParseMbps($lines);
        $this->assertEquals(214.7, $mbps);
    }

    public function testParseMbpsFromDdSummaryMib(): void
    {
        $lines = [
            '1073741824 bytes (1.1 GB, 1.0 GiB) copied, 5.0001 s, 200.0 MiB/s',
        ];

        $mbps = \benchmarkStorageSequentialReadParseMbps($lines);
        $this->assertEquals(200.0, $mbps);
    }

    public function testParseMbpsReturnsNullWhenNoSummary(): void
    {
        $lines = [
            'random line',
            'another line',
        ];

        $mbps = \benchmarkStorageSequentialReadParseMbps($lines);
        $this->assertTrue($mbps === null);
    }

    public function testPlanRegionBasic(): void
    {
        $sizeBytes = 500 * 1024 * 1024 * 1024; // 500 GiB
        $plan = \benchmarkStorageSequentialReadPlanRegion($sizeBytes, 60, 1);

        $this->assertTrue(is_array($plan));
        [$bsBytes, $skipBlocks, $countBlocks] = $plan;

        $this->assertEquals(1024 * 1024, $bsBytes);
        $this->assertTrue($skipBlocks >= 0);
        $this->assertTrue($countBlocks >= 1);
    }

    public function testParseArgumentsDefaults(): void
    {
        [$device, $runtime, $bsMiB, $scoreOnly, $colorEnabled] = \benchmarkStorageSequentialReadParseArguments(
            ['benchmarkStorageSequentialRead.php']
        );

        $this->assertTrue($device === null);
        $this->assertEquals(60, $runtime);
        $this->assertEquals(1, $bsMiB);
        $this->assertTrue($scoreOnly === false);
        $this->assertTrue($colorEnabled === true);
    }
}

