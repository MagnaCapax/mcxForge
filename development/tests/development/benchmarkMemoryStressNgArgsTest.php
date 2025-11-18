<?php

declare(strict_types=1);

namespace mcxForge\Tests;

require_once __DIR__ . '/../../../bin/benchmarkMemoryStressNg.php';

final class benchmarkMemoryStressNgArgsTest extends testCase
{
    public function testDefaultArguments(): void
    {
        [$duration, $workers, $percent, $scoreOnly, $colorEnabled] =
            \benchmarkMemoryStressNgParseArguments(['benchmarkMemoryStressNg.php']);

        $this->assertEquals(120, $duration);
        $this->assertTrue($workers >= 1);
        $this->assertEquals(80, $percent);
        $this->assertEquals(false, $scoreOnly);
        $this->assertEquals(true, $colorEnabled);
    }

    public function testCustomArguments(): void
    {
        [$duration, $workers, $percent, $scoreOnly, $colorEnabled] =
            \benchmarkMemoryStressNgParseArguments([
                'benchmarkMemoryStressNg.php',
                '--duration=300',
                '--workers=8',
                '--percent=90',
                '--score-only',
                '--no-color',
            ]);

        $this->assertEquals(300, $duration);
        $this->assertEquals(8, $workers);
        $this->assertEquals(90, $percent);
        $this->assertEquals(true, $scoreOnly);
        $this->assertEquals(false, $colorEnabled);
    }
}

