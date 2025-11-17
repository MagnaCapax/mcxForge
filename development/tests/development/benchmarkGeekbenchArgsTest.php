<?php

declare(strict_types=1);

namespace mcxForge\Tests;

require_once __DIR__ . '/../../../bin/benchmarkGeekbench.php';
require_once __DIR__ . '/../../../lib/php/Benchmark/GeekbenchRunner.php';

use mcxForge\Benchmark\GeekbenchRunner;

final class benchmarkGeekbenchArgsTest extends testCase
{
    public function testDefaultArguments(): void
    {
        [$major, $scoreOnly, $colorEnabled] = \benchmarkGeekbenchParseArguments(
            ['benchmarkGeekbench.php']
        );

        $this->assertEquals('6', $major);
        $this->assertTrue($scoreOnly === false);
        $this->assertTrue($colorEnabled === true);
    }

    public function testVersionFiveArgument(): void
    {
        [$major] = \benchmarkGeekbenchParseArguments(
            ['benchmarkGeekbench.php', '--version=5']
        );

        $this->assertEquals('5', $major);
    }

    public function testScoreOnlyArgument(): void
    {
        [, $scoreOnly] = \benchmarkGeekbenchParseArguments(
            ['benchmarkGeekbench.php', '--score-only']
        );

        $this->assertTrue($scoreOnly === true);
    }

    public function testNoColorArgument(): void
    {
        [, , $colorEnabled] = \benchmarkGeekbenchParseArguments(
            ['benchmarkGeekbench.php', '--no-color']
        );

        $this->assertTrue($colorEnabled === false);
    }

    public function testResolveVersionStringWithDefaults(): void
    {
        $runner = new GeekbenchRunner();

        $v6 = $runner->resolveVersionString('6');
        $v5 = $runner->resolveVersionString('5');

        $this->assertTrue($v6 !== '');
        $this->assertTrue($v5 !== '');
        $this->assertTrue($v6 !== $v5);
    }
}

