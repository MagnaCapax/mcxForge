<?php

declare(strict_types=1);

namespace mcxForge\Tests;

require_once __DIR__ . '/../../../bin/benchmarkCPUXmrig.php';

final class benchmarkCPUXmrigScoreJsonTest extends testCase
{
    public function testBuildScorePayloadShape(): void
    {
        $scoreTotal = 1234.56;
        $scorePerThread = 308.64;
        $threads = 4;
        $duration = 1800;
        $logFile = '/tmp/benchmarkCPUXmrig-20251118.log';

        $payload = benchmarkCPUXmrigBuildScorePayload($scoreTotal, $scorePerThread, $threads, $duration, $logFile);

        $this->assertEquals('mcxForge.cpu-benchmark.v1', $payload['schema']);
        $this->assertEquals('cpuxmrig', $payload['benchmark']);
        $this->assertEquals('ok', $payload['status']);
        $this->assertEquals('hashrate', $payload['metric']);
        $this->assertEquals('H/s', $payload['unit']);
        $this->assertEquals($scoreTotal, $payload['score']);
        $this->assertEquals($scorePerThread, $payload['scorePerThread']);
        $this->assertEquals($threads, $payload['threads']);
        $this->assertEquals($duration, $payload['durationSeconds']);
        $this->assertEquals($logFile, $payload['logFile']);
    }
}

