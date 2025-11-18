<?php

declare(strict_types=1);

namespace mcxForge\Tests;

require_once __DIR__ . '/../../../bin/benchmarkCPUStressNg.php';

final class benchmarkCPUStressNgScoreJsonTest extends testCase
{
    public function testBuildScorePayloadShape(): void
    {
        $scoreTotal = 5812.26;
        $scorePerThread = 1453.07;
        $threads = 4;
        $duration = 120;
        $logFile = '/tmp/benchmarkCPUStressNg-20251118.log';

        $payload = benchmarkCPUStressNgBuildScorePayload($scoreTotal, $scorePerThread, $threads, $duration, $logFile);

        $this->assertEquals('mcxForge.cpu-benchmark.v1', $payload['schema']);
        $this->assertEquals('cpustressng', $payload['benchmark']);
        $this->assertEquals('ok', $payload['status']);
        $this->assertEquals('bogo_ops_per_second', $payload['metric']);
        $this->assertEquals('bogo ops/s', $payload['unit']);
        $this->assertEquals($scoreTotal, $payload['score']);
        $this->assertEquals($scorePerThread, $payload['scorePerThread']);
        $this->assertEquals($threads, $payload['threads']);
        $this->assertEquals($duration, $payload['durationSeconds']);
        $this->assertEquals($logFile, $payload['logFile']);
    }
}

