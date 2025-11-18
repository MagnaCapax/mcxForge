<?php

declare(strict_types=1);

namespace mcxForge\Tests;

require_once __DIR__ . '/../../../lib/php/benchmark/StressNgRunner.php';

use mcxForge\Benchmark\StressNgRunner;

final class benchmarkCPUStressNgParseScoreTest extends testCase
{
    public function testParseScoreExtractsRealTimeBogoOps(): void
    {
        $runner = new StressNgRunner();

        $lines = [
            'stress-ng: info:  [1477] setting to a 2 mins run per stressor',
            'stress-ng: info:  [1477] dispatching hogs: 4 cpu',
            'stress-ng: metrc: [1477] stressor       bogo ops real time  usr time  sys time   bogo ops/s     bogo ops/s',
            'stress-ng: metrc: [1477]                           (secs)    (secs)    (secs)   (real time) (usr+sys time)',
            'stress-ng: metrc: [1477] cpu              697476    120.00    479.71      0.10      5812.26        1453.65',
        ];

        $score = $runner->parseScore($lines);
        $this->assertEquals(5812.26, $score);
    }
}

