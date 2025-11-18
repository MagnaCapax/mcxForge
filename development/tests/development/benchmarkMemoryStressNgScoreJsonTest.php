<?php

declare(strict_types=1);

namespace mcxForge\Tests;

require_once __DIR__ . '/../../../bin/benchmarkMemoryStressNg.php';

final class benchmarkMemoryStressNgScoreJsonTest extends testCase
{
    public function testBuildScorePayloadShape(): void
    {
        $payload = \benchmarkMemoryStressNgBuildScorePayload(
            5812.26,
            1453.07,
            4,
            120,
            80,
            '/tmp/benchmarkMemoryStressNg-20251118.log'
        );

        $this->assertEquals('mcxForge.memory-benchmark.v1', $payload['schema']);
        $this->assertEquals('memstressng', $payload['benchmark']);
        $this->assertEquals('ok', $payload['status']);
        $this->assertEquals('bogo_ops_per_second', $payload['metric']);
        $this->assertEquals('bogo ops/s', $payload['unit']);
        $this->assertEquals(5812.26, $payload['score']);
        $this->assertEquals(1453.07, $payload['scorePerThread']);
        $this->assertEquals(4, $payload['threads']);
        $this->assertEquals(120, $payload['durationSeconds']);
        $this->assertEquals(80, $payload['percentOfRam']);
        $this->assertEquals('/tmp/benchmarkMemoryStressNg-20251118.log', $payload['logFile']);
    }
}

