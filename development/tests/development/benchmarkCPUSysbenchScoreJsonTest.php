<?php

declare(strict_types=1);

namespace mcxForge\Tests;

require_once __DIR__ . '/../../../bin/benchmarkCPUSysbench.php';

final class benchmarkCPUSysbenchScoreJsonTest extends testCase
{
    public function testBuildScorePayloadShape(): void
    {
        $scoreTotal = 12345.67;
        $scorePerThread = 771.6;
        $threads = 16;
        $duration = 120;
        $logFile = '/tmp/benchmarkCPUSysbench-20251118.log';

        $payload = benchmarkCPUSysbenchBuildScorePayload($scoreTotal, $scorePerThread, $threads, $duration, $logFile);

        $this->assertEquals('mcxForge.cpu-benchmark.v1', $payload['schema']);
        $this->assertEquals('cpusysbench', $payload['benchmark']);
        $this->assertEquals('ok', $payload['status']);
        $this->assertEquals('events_per_second', $payload['metric']);
        $this->assertEquals('events/s', $payload['unit']);
        $this->assertEquals($scoreTotal, $payload['score']);
        $this->assertEquals($scorePerThread, $payload['scorePerThread']);
        $this->assertEquals($threads, $payload['threads']);
        $this->assertEquals($duration, $payload['durationSeconds']);
        $this->assertEquals($logFile, $payload['logFile']);
    }
}

