<?php

declare(strict_types=1);

namespace mcxForge\Tests;

require_once __DIR__ . '/../../../bin/benchmarkMemorySysbench.php';

final class benchmarkMemorySysbenchScoreJsonTest extends testCase
{
    public function testBuildScorePayloadShape(): void
    {
        $payload = \benchmarkMemorySysbenchBuildScorePayload(
            12345.67,
            771.6,
            16,
            8,
            4,
            'rnd',
            'write',
            '/tmp/benchmarkMemorySysbench-20251118.log'
        );

        $this->assertEquals('mcxForge.memory-benchmark.v1', $payload['schema']);
        $this->assertEquals('memsysbench', $payload['benchmark']);
        $this->assertEquals('ok', $payload['status']);
        $this->assertEquals('throughput', $payload['metric']);
        $this->assertEquals('MiB/s', $payload['unit']);
        $this->assertEquals(12345.67, $payload['score']);
        $this->assertEquals(771.6, $payload['scorePerThread']);
        $this->assertEquals(16, $payload['threads']);
        $this->assertEquals(8, $payload['totalSizeGiB']);
        $this->assertEquals(4, $payload['blockSizeKiB']);
        $this->assertEquals('rnd', $payload['accessMode']);
        $this->assertEquals('write', $payload['operation']);
        $this->assertEquals('/tmp/benchmarkMemorySysbench-20251118.log', $payload['logFile']);
    }
}

