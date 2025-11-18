<?php

declare(strict_types=1);

namespace mcxForge\Tests;

require_once __DIR__ . '/../../../bin/benchmarkMemorySysbench.php';

final class benchmarkMemorySysbenchArgsTest extends testCase
{
    public function testDefaultArguments(): void
    {
        [$threads, $totalSizeGiB, $blockSizeKiB, $accessMode, $operation, $scoreOnly, $colorEnabled] =
            \benchmarkMemorySysbenchParseArguments(['benchmarkMemorySysbench.php']);

        $this->assertTrue($threads >= 1);
        $this->assertEquals(4, $totalSizeGiB);
        $this->assertEquals(4, $blockSizeKiB);
        $this->assertEquals('seq', $accessMode);
        $this->assertEquals('read', $operation);
        $this->assertEquals(false, $scoreOnly);
        $this->assertEquals(true, $colorEnabled);
    }

    public function testCustomArguments(): void
    {
        [$threads, $totalSizeGiB, $blockSizeKiB, $accessMode, $operation, $scoreOnly, $colorEnabled] =
            \benchmarkMemorySysbenchParseArguments([
                'benchmarkMemorySysbench.php',
                '--threads=8',
                '--total-size=16',
                '--block-size=64',
                '--access-mode=rnd',
                '--operation=write',
                '--score-only',
                '--no-color',
            ]);

        $this->assertEquals(8, $threads);
        $this->assertEquals(16, $totalSizeGiB);
        $this->assertEquals(64, $blockSizeKiB);
        $this->assertEquals('rnd', $accessMode);
        $this->assertEquals('write', $operation);
        $this->assertEquals(true, $scoreOnly);
        $this->assertEquals(false, $colorEnabled);
    }
}

