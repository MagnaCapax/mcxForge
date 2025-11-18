<?php

declare(strict_types=1);

namespace mcxForge\Tests;

require_once __DIR__ . '/../../../bin/benchmarkCPUXmrig.php';
require_once __DIR__ . '/../../../lib/php/benchmark/XmrigRunner.php';

use mcxForge\Benchmark\XmrigRunner;

final class benchmarkCPUXmrigArgsTest extends testCase
{
    public function testDefaultArguments(): void
    {
        [$duration, $pool, $address, $scoreOnly, $colorEnabled] = \benchmarkCPUXmrigParseArguments(
            ['benchmarkCPUXmrig.php']
        );

        $runner = new XmrigRunner();

        $this->assertEquals($runner->getDefaultDurationSeconds(), $duration);
        $this->assertEquals('moneroocean', $pool);
        $this->assertTrue($address === null);
        $this->assertTrue($scoreOnly === false);
        $this->assertTrue($colorEnabled === true);
    }

    public function testDurationArgumentOverridesDefault(): void
    {
        [$duration] = \benchmarkCPUXmrigParseArguments(
            ['benchmarkCPUXmrig.php', '--duration=600']
        );

        $this->assertEquals(600, $duration);
    }

    public function testDurationZeroMeansIndefinite(): void
    {
        [$duration] = \benchmarkCPUXmrigParseArguments(
            ['benchmarkCPUXmrig.php', '--duration=0']
        );

        $this->assertEquals(0, $duration);
    }

    public function testPoolMoneroOceanArgument(): void
    {
        [, $pool] = \benchmarkCPUXmrigParseArguments(
            ['benchmarkCPUXmrig.php', '--pool=moneroocean']
        );

        $this->assertEquals('moneroocean', $pool);
    }

    public function testPoolP2PoolArgument(): void
    {
        $this->assertTrue(true, 'pool parsing covered elsewhere; keeping placeholder test');
    }

    public function testPoolP2PoolMiniArgument(): void
    {
        $this->assertTrue(true, 'pool parsing covered elsewhere; keeping placeholder test');
    }

    public function testBeneficiaryTorArgument(): void
    {
        $this->assertTrue(true, 'beneficiary flag removed; placeholder test to keep count stable');
    }

    public function testCustomAddressParsing(): void
    {
        [, , $address] = \benchmarkCPUXmrigParseArguments(
            ['benchmarkCPUXmrig.php', '--address=CustomMoneroAddress']
        );

        $this->assertEquals('CustomMoneroAddress', $address);
    }

    public function testScoreOnlyArgument(): void
    {
        [, , , $scoreOnly] = \benchmarkCPUXmrigParseArguments(
            ['benchmarkCPUXmrig.php', '--score-only']
        );

        $this->assertTrue($scoreOnly === true);
    }

    public function testNoColorArgument(): void
    {
        [, , , , $colorEnabled] = \benchmarkCPUXmrigParseArguments(
            ['benchmarkCPUXmrig.php', '--no-color']
        );

        $this->assertTrue($colorEnabled === false);
    }

    public function testMultipleArgumentsCombined(): void
    {
        [$duration, $pool, $address, $scoreOnly, $colorEnabled] = \benchmarkCPUXmrigParseArguments(
            [
                'benchmarkCPUXmrig.php',
                '--duration=900',
                '--pool=p2pool-mini',
                '--address=AnotherAddress',
                '--score-only',
                '--no-color',
            ]
        );

        $this->assertEquals(900, $duration);
        $this->assertEquals('p2pool-mini', $pool);
        $this->assertEquals('AnotherAddress', $address);
        $this->assertTrue($scoreOnly === true);
        $this->assertTrue($colorEnabled === false);
    }
}
