<?php

declare(strict_types=1);

namespace mcxForge\Tests;

require_once __DIR__ . '/../../../lib/php/benchmark/SysbenchMemoryRunner.php';

use mcxForge\Benchmark\SysbenchMemoryRunner;

final class benchmarkMemorySysbenchOutputTest extends testCase
{
    public function testParseThroughputFromTypicalOutput(): void
    {
        $runner = new SysbenchMemoryRunner();

        $lines = [
            'Running the test with following options:',
            'Number of threads: 4',
            'Initializing random number generator from current time',
            '',
            'Initializing worker threads...',
            '',
            'Threads started!',
            '',
            'Operations performed: 102400 (    0.00 ops/sec)',
            '102400.00 MiB transferred (12345.67 MiB/sec)',
        ];

        $throughput = $runner->parseThroughput($lines);
        $this->assertEquals(12345.67, $throughput);
    }
}

