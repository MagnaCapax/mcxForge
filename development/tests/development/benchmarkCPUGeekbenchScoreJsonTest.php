<?php

declare(strict_types=1);

namespace mcxForge\Tests;

require_once __DIR__ . '/../../../bin/benchmarkCPUGeekbench.php';

final class benchmarkCPUGeekbenchScoreJsonTest extends testCase
{
    public function testBuildScorePayloadShape(): void
    {
        $major = '6';
        $score = 5678;
        $logFile = '/tmp/benchmarkGeekbench6-20251118.log';

        $payload = benchmarkGeekbenchBuildScorePayload($major, $score, $logFile);

        $this->assertEquals('mcxForge.cpu-benchmark.v1', $payload['schema']);
        $this->assertEquals('cpugeekbench', $payload['benchmark']);
        $this->assertEquals('ok', $payload['status']);
        $this->assertEquals('geekbench_score', $payload['metric']);
        $this->assertEquals('score', $payload['unit']);
        $this->assertEquals($score, $payload['score']);
        $this->assertEquals($logFile, $payload['logFile']);
    }
}

