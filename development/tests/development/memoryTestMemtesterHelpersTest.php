<?php

declare(strict_types=1);

namespace mcxForge\Tests;

require_once __DIR__ . '/../../../bin/memoryTestMemtester.php';

final class memoryTestMemtesterHelpersTest extends testCase
{
    public function testBuildResultPayloadShape(): void
    {
        $payload = \memoryTestMemtesterBuildResultPayload(
            8,
            90,
            2,
            'ok',
            '/tmp/memoryTestMemtester-20251118.log'
        );

        $this->assertEquals('mcxForge.memory-test.v1', $payload['schema']);
        $this->assertEquals('memtester', $payload['benchmark']);
        $this->assertEquals('ok', $payload['status']);
        $this->assertEquals(8, $payload['threads']);
        $this->assertEquals(90, $payload['percentOfRam']);
        $this->assertEquals(2, $payload['passes']);
        $this->assertEquals('/tmp/memoryTestMemtester-20251118.log', $payload['logFile']);
    }
}

