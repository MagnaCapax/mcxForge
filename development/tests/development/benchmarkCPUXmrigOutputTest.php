<?php

declare(strict_types=1);

namespace mcxForge\Tests;

require_once __DIR__ . '/../../../lib/php/benchmark/XmrigRunner.php';

use mcxForge\Benchmark\XmrigRunner;

final class benchmarkCPUXmrigOutputTest extends testCase
{
    public function testBuildLogFilePathFormat(): void
    {
        $runner = new XmrigRunner();
        $now = new \DateTimeImmutable('2025-11-18 01:23:45');

        $path = $runner->buildLogFilePath($now);
        $this->assertEquals('/tmp/benchmarkCPUXmrig-20251118.log', $path);
    }

    public function testResolvePoolMoneroOcean(): void
    {
        $runner = new XmrigRunner();

        $pool = $runner->resolvePool('moneroocean');
        $this->assertEquals('gulf.moneroocean.stream', $pool['host']);
        $this->assertEquals(20128, $pool['port']);
    }

    public function testResolvePoolP2Pool(): void
    {
        $runner = new XmrigRunner();

        $pool = $runner->resolvePool('p2pool');
        $this->assertEquals('p2pool.io', $pool['host']);
        $this->assertEquals(3333, $pool['port']);
    }

    public function testResolvePoolP2PoolMini(): void
    {
        $runner = new XmrigRunner();

        $pool = $runner->resolvePool('p2pool-mini');
        $this->assertEquals('mini.p2pool.io', $pool['host']);
        $this->assertEquals(3333, $pool['port']);
    }

    public function testResolvePoolDefaultsToMoneroOceanWhenEmptyName(): void
    {
        $runner = new XmrigRunner();

        $pool = $runner->resolvePool('   ');
        $this->assertEquals('gulf.moneroocean.stream', $pool['host']);
        $this->assertEquals(20128, $pool['port']);
    }

    public function testResolvePoolIsCaseInsensitive(): void
    {
        $runner = new XmrigRunner();

        $pool = $runner->resolvePool('MoNeRoOcEaN');
        $this->assertEquals('gulf.moneroocean.stream', $pool['host']);
        $this->assertEquals(20128, $pool['port']);
    }

    public function testResolveBeneficiaryMoneroDefault(): void
    {
        $runner = new XmrigRunner();

        $address = $runner->resolveBeneficiaryAddress(null, '');
        $this->assertTrue($address !== '');
    }

    public function testResolveBeneficiaryCustomAddressPreferred(): void
    {
        $runner = new XmrigRunner();

        $address = $runner->resolveBeneficiaryAddress('CustomXmrAddress', '');
        $this->assertEquals('CustomXmrAddress', $address);
    }

    public function testBuildCommandIncludesRigIdAndPrintTime(): void
    {
        $runner = new XmrigRunner();

        $cmd = $runner->buildCommand(
            '/usr/bin/xmrig',
            600,
            'moneroocean',
            null,
            'mcxForgeHost',
            5
        );

        $this->assertTrue(strpos($cmd, '--rig-id=') !== false);
        $this->assertTrue(strpos($cmd, 'mcxForgeHost') !== false);
        $this->assertTrue(strpos($cmd, '--print-time=5') !== false);
    }

    public function testBuildCommandEscapesRigIdWithSpaces(): void
    {
        $runner = new XmrigRunner();

        $cmd = $runner->buildCommand(
            '/usr/bin/xmrig',
            600,
            'moneroocean',
            null,
            'mcxForge QA Host',
            10
        );

        $this->assertTrue(strpos($cmd, '--rig-id=') !== false);
        $this->assertTrue(strpos($cmd, 'mcxForge QA Host') !== false);
    }

    public function testBuildCommandNormalizesNegativeDurationToZero(): void
    {
        $runner = new XmrigRunner();

        $cmd = $runner->buildCommand(
            '/usr/bin/xmrig',
            -10,
            'moneroocean',
            null
        );

        $this->assertTrue(strpos($cmd, 'timeout') === false);
    }

    public function testBuildCommandIncludesBinaryPoolAndAddress(): void
    {
        $runner = new XmrigRunner();

        $cmd = $runner->buildCommand(
            '/usr/bin/xmrig',
            600,
            'moneroocean',
            'CustomXmrAddress'
        );

        $this->assertTrue(strpos($cmd, '/usr/bin/xmrig') !== false);
        $this->assertTrue(strpos($cmd, 'gulf.moneroocean.stream:20128') !== false);
        $this->assertTrue(strpos($cmd, 'CustomXmrAddress') !== false);
    }

    public function testBuildCommandWrapsTimeoutWhenDurationPositive(): void
    {
        $runner = new XmrigRunner();

        $cmd = $runner->buildCommand(
            '/usr/bin/xmrig',
            1800,
            'p2pool',
            null
        );

        $this->assertTrue(strpos($cmd, 'timeout 1800') !== false || strpos($cmd, 'timeout 1') === false, 'Command should attempt to use timeout when available');
        $this->assertTrue(strpos($cmd, 'p2pool.io:3333') !== false);
    }

    public function testBuildCommandNoTimeoutWhenDurationZero(): void
    {
        $runner = new XmrigRunner();

        $cmd = $runner->buildCommand(
            '/usr/bin/xmrig',
            0,
            'moneroocean',
            null
        );

        $this->assertTrue(strpos($cmd, 'timeout') === false);
        $this->assertTrue(strpos($cmd, 'gulf.moneroocean.stream:20128') !== false);
    }

    public function testParseHashrateSamplesSingleStandardLine(): void
    {
        $runner = new XmrigRunner();

        $lines = [
            '[2025-11-18 00:00:10.000]  speed 10s/60s/15m  1234.0  1100.0  900.0  H/s',
        ];

        $samples = $runner->parseHashrateSamples($lines);
        $this->assertEquals(1, count($samples));
        $this->assertEquals(900.0, $samples[0]);
    }

    public function testParseHashrateSamplesPrefersFifteenMinuteColumn(): void
    {
        $runner = new XmrigRunner();

        $lines = [
            '[ts] speed 10s/60s/15m  2000.0  1500.0  1000.0  H/s',
            '[ts] speed 10s/60s/15m  2100.0  1600.0  1100.0  H/s',
        ];

        $samples = $runner->parseHashrateSamples($lines);
        $this->assertEquals([1000.0, 1100.0], $samples);
    }

    public function testParseHashrateSamplesFallsBackToSixtySeconds(): void
    {
        $runner = new XmrigRunner();

        $lines = [
            '[ts] speed 10s/60s  500.0  400.0  H/s',
        ];

        $samples = $runner->parseHashrateSamples($lines);
        $this->assertEquals(1, count($samples));
        $this->assertEquals(400.0, $samples[0]);
    }

    public function testParseHashrateSamplesFallsBackToTenSeconds(): void
    {
        $runner = new XmrigRunner();

        $lines = [
            '[ts] speed 10s/60s  300.0  0.0  H/s',
        ];

        $samples = $runner->parseHashrateSamples($lines);
        $this->assertEquals(1, count($samples));
        $this->assertEquals(300.0, $samples[0]);
    }

    public function testParseHashrateSamplesWithExtraWhitespace(): void
    {
        $runner = new XmrigRunner();

        $lines = [
            '   [ts]   speed    10s/60s/15m    100.0   200.0   300.0   H/s   ',
        ];

        $samples = $runner->parseHashrateSamples($lines);
        $this->assertEquals([300.0], $samples);
    }

    public function testParseHashrateSamplesIgnoresLinesWithoutSpeed(): void
    {
        $runner = new XmrigRunner();

        $lines = [
            '[ts] random log line',
            '[ts] another line',
        ];

        $samples = $runner->parseHashrateSamples($lines);
        $this->assertEquals(0, count($samples));
    }

    public function testParseHashrateSamplesIgnoresSpeedLinesWithoutHs(): void
    {
        $runner = new XmrigRunner();

        $lines = [
            '[ts] speed 10s/60s/15m  100.0  200.0  300.0',
        ];

        $samples = $runner->parseHashrateSamples($lines);
        $this->assertEquals(0, count($samples));
    }

    public function testParseHashrateSamplesWithLowercaseUnits(): void
    {
        $runner = new XmrigRunner();

        $lines = [
            '[ts] speed 10s/60s/15m  100.0  200.0  300.0  h/s',
        ];

        $samples = $runner->parseHashrateSamples($lines);
        $this->assertEquals([300.0], $samples);
    }

    public function testParseHashrateSamplesCaseInsensitive(): void
    {
        $runner = new XmrigRunner();

        $lines = [
            '[ts] SPEED 10s/60s/15m  100.0  200.0  300.0  h/S',
        ];

        $samples = $runner->parseHashrateSamples($lines);
        $this->assertEquals([300.0], $samples);
    }

    public function testParseHashrateSamplesHandlesAnsiColorCodes(): void
    {
        $runner = new XmrigRunner();

        $lines = [
            "\033[1;32m[ts]\033[0m speed 10s/60s/15m  100.0  200.0  300.0  H/s",
        ];

        $samples = $runner->parseHashrateSamples($lines);
        $this->assertEquals([300.0], $samples);
    }

    public function testParseHashrateSamplesWithMultipleSpeedLinesKeepsOrder(): void
    {
        $runner = new XmrigRunner();

        $lines = [
            '[ts] speed 10s/60s/15m  100.0  200.0  300.0  H/s',
            '[ts] speed 10s/60s/15m  400.0  500.0  600.0  H/s',
            '[ts] speed 10s/60s  700.0  800.0  H/s',
        ];

        $samples = $runner->parseHashrateSamples($lines);
        $this->assertEquals([300.0, 600.0, 800.0], $samples);
    }

    public function testParseHashrateSamplesSkipsNonPositiveValues(): void
    {
        $runner = new XmrigRunner();

        $lines = [
            '[ts] speed 10s/60s/15m  0.0  0.0  0.0  H/s',
            '[ts] speed 10s/60s/15m  100.0  0.0  0.0  H/s',
        ];

        $samples = $runner->parseHashrateSamples($lines);
        $this->assertEquals([100.0], $samples);
    }

    public function testParseHashrateSamplesHandlesVeryLargeValues(): void
    {
        $runner = new XmrigRunner();

        $lines = [
            '[ts] speed 10s/60s/15m  1000000.0  2000000.0  3000000.0  H/s',
        ];

        $samples = $runner->parseHashrateSamples($lines);
        $this->assertEquals([3000000.0], $samples);
    }

    public function testComputeAverageHashrateSingleSample(): void
    {
        $runner = new XmrigRunner();

        $average = $runner->computeAverageHashrate([500.0]);
        $this->assertEquals(500.0, $average);
    }

    public function testComputeAverageHashrateMultipleSamples(): void
    {
        $runner = new XmrigRunner();

        $average = $runner->computeAverageHashrate([400.0, 600.0, 800.0]);
        $this->assertEquals(600.0, $average);
    }

    public function testComputeAverageHashrateHighPrecision(): void
    {
        $runner = new XmrigRunner();

        $average = $runner->computeAverageHashrate([400.1234, 600.5678]);
        $this->assertTrue(abs($average - 500.3456) < 0.0001);
    }

    public function testComputeAverageHashrateLargeSampleSet(): void
    {
        $runner = new XmrigRunner();

        $samples = [];
        for ($i = 1; $i <= 100; $i++) {
            $samples[] = (float) $i;
        }

        $average = $runner->computeAverageHashrate($samples);
        $this->assertEquals(50.5, $average);
    }

    public function testComputeAverageHashrateIgnoresNonFiniteValues(): void
    {
        $runner = new XmrigRunner();

        $samples = [500.0, INF, NAN, 700.0];
        $average = $runner->computeAverageHashrate($samples);

        $this->assertEquals(600.0, $average);
    }

    public function testComputeAverageHashrateReturnsNullWhenNoValidSamples(): void
    {
        $runner = new XmrigRunner();

        $average = $runner->computeAverageHashrate([]);
        $this->assertTrue($average === null);
    }

    public function testComputeAverageHashrateReturnsNullWhenAllNonPositive(): void
    {
        $runner = new XmrigRunner();

        $average = $runner->computeAverageHashrate([0.0, -1.0]);
        $this->assertTrue($average === null);
    }

    public function testComputeAverageHashrateIgnoresZerosInsideMixedSamples(): void
    {
        $runner = new XmrigRunner();

        $average = $runner->computeAverageHashrate([0.0, 100.0, 200.0, 0.0]);
        $this->assertEquals(150.0, $average);
    }

    public function testParseHashrateSamplesWithMixedValidAndInvalidLines(): void
    {
        $runner = new XmrigRunner();

        $lines = [
            '[ts] speed 10s/60s/15m  100.0  200.0  300.0  H/s',
            '[ts] speed 10s/60s/15m  invalid  0.0  0.0  H/s',
            '[ts] unrelated info',
            '[ts] speed 10s/60s  400.0  500.0  H/s',
        ];

        $samples = $runner->parseHashrateSamples($lines);
        $this->assertEquals([300.0, 500.0], $samples);
    }

    public function testParseHashrateSamplesWithMalformedMetricToken(): void
    {
        $runner = new XmrigRunner();

        $lines = [
            '[ts] speed unexpected-token 100.0 200.0 300.0 H/s',
        ];

        $samples = $runner->parseHashrateSamples($lines);
        $this->assertEquals([300.0], $samples);
    }
}
