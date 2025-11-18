<?php

declare(strict_types=1);

namespace mcxForge\Tests;

require_once __DIR__ . '/../../../bin/benchmarkCPUGeekbench.php';
require_once __DIR__ . '/../../../lib/php/benchmark/GeekbenchRunner.php';

use mcxForge\Benchmark\GeekbenchRunner;

final class benchmarkCPUGeekbenchArgsTest extends testCase
{
    public function testDefaultArguments(): void
    {
        [$major, $scoreOnly, $colorEnabled] = \benchmarkGeekbenchParseArguments(
            ['benchmarkCPUGeekbench.php']
        );

        $this->assertEquals('6', $major);
        $this->assertTrue($scoreOnly === false);
        $this->assertTrue($colorEnabled === true);
    }

    public function testVersionFiveArgument(): void
    {
        [$major] = \benchmarkGeekbenchParseArguments(
            ['benchmarkCPUGeekbench.php', '--version=5']
        );

        $this->assertEquals('5', $major);
    }

    public function testScoreOnlyArgument(): void
    {
        [, $scoreOnly] = \benchmarkGeekbenchParseArguments(
            ['benchmarkCPUGeekbench.php', '--score-only']
        );

        $this->assertTrue($scoreOnly === true);
    }

    public function testNoColorArgument(): void
    {
        [, , $colorEnabled] = \benchmarkGeekbenchParseArguments(
            ['benchmarkCPUGeekbench.php', '--no-color']
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

    public function testResolveVersionStringUsesCommonEnvOverride(): void
    {
        $runner = new GeekbenchRunner();

        $previousCommon = getenv('GEEKBENCH_VER');
        putenv('GEEKBENCH_VER=9.9.9');

        $v6 = $runner->resolveVersionString('6');
        $v5 = $runner->resolveVersionString('5');

        $this->assertEquals('9.9.9', $v6);
        $this->assertEquals('9.9.9', $v5);

        if ($previousCommon === false) {
            putenv('GEEKBENCH_VER');
        } else {
            putenv('GEEKBENCH_VER=' . $previousCommon);
        }
    }

    public function testResolveVersionStringPrefersMajorSpecificEnv(): void
    {
        $runner = new GeekbenchRunner();

        $prevCommon = getenv('GEEKBENCH_VER');
        $prev5 = getenv('GEEKBENCH5_VER');

        putenv('GEEKBENCH_VER=9.9.9');
        putenv('GEEKBENCH5_VER=5.9.9');

        $v5 = $runner->resolveVersionString('5');
        $v6 = $runner->resolveVersionString('6');

        $this->assertEquals('5.9.9', $v5);
        $this->assertEquals('9.9.9', $v6);

        if ($prevCommon === false) {
            putenv('GEEKBENCH_VER');
        } else {
            putenv('GEEKBENCH_VER=' . $prevCommon);
        }
        if ($prev5 === false) {
            putenv('GEEKBENCH5_VER');
        } else {
            putenv('GEEKBENCH5_VER=' . $prev5);
        }
    }

    public function testBuildDownloadUrlDefaults(): void
    {
        $runner = new GeekbenchRunner();

        $url = $runner->buildDownloadUrl('6.5.0');
        $this->assertEquals('https://cdn.geekbench.com/Geekbench-6.5.0-Linux.tar.gz', $url);
    }

    public function testBuildDownloadUrlUsesCommonEnvOverride(): void
    {
        $runner = new GeekbenchRunner();

        $previous = getenv('GEEKBENCH_URL');
        putenv('GEEKBENCH_URL=https://example.invalid/geekbench-custom.tar.gz');

        $url = $runner->buildDownloadUrl('6.5.0');
        $this->assertEquals('https://example.invalid/geekbench-custom.tar.gz', $url);

        if ($previous === false) {
            putenv('GEEKBENCH_URL');
        } else {
            putenv('GEEKBENCH_URL=' . $previous);
        }
    }

    public function testBuildDownloadUrlPrefersMajorSpecificEnv(): void
    {
        $runner = new GeekbenchRunner();

        $prevCommon = getenv('GEEKBENCH_URL');
        $prev6 = getenv('GEEKBENCH6_URL');

        putenv('GEEKBENCH_URL=https://example.invalid/common.tar.gz');
        putenv('GEEKBENCH6_URL=https://example.invalid/v6.tar.gz');

        $url6 = $runner->buildDownloadUrl('6.5.0');
        $url5 = $runner->buildDownloadUrl('5.5.1');

        $this->assertEquals('https://example.invalid/v6.tar.gz', $url6);
        $this->assertEquals('https://example.invalid/common.tar.gz', $url5);

        if ($prevCommon === false) {
            putenv('GEEKBENCH_URL');
        } else {
            putenv('GEEKBENCH_URL=' . $prevCommon);
        }
        if ($prev6 === false) {
            putenv('GEEKBENCH6_URL');
        } else {
            putenv('GEEKBENCH6_URL=' . $prev6);
        }
    }

    public function testBuildBaseDirectoryAndTarballPath(): void
    {
        $runner = new GeekbenchRunner();

        $base = $runner->buildBaseDirectory('6.5.0');
        $tar = $runner->buildTarballPath('6.5.0');

        $this->assertEquals('/opt/Geekbench-6.5.0-Linux', $base);
        $this->assertEquals('/tmp/Geekbench-6.5.0-Linux.tar.gz', $tar);
    }

    public function testDetermineMajorFromVersionString(): void
    {
        $runner = new GeekbenchRunner();

        $this->assertEquals('6', $runner->determineMajorFromVersionString('6.5.0'));
        $this->assertEquals('5', $runner->determineMajorFromVersionString('5.5.1'));
        $this->assertEquals('6', $runner->determineMajorFromVersionString(''));
        $this->assertEquals('6', $runner->determineMajorFromVersionString('9.1.2'));
    }

    public function testBuildLogFilePathUsesResolvedMajor(): void
    {
        $runner = new GeekbenchRunner();

        $date = new \DateTimeImmutable('2025-11-17 02:03:04');
        $pathFrom6 = $runner->buildLogFilePath('6', $date);
        $pathFromPadded = $runner->buildLogFilePath(' 6 ', $date);

        $this->assertEquals('/tmp/benchmarkGeekbench6-20251117.log', $pathFrom6);
        $this->assertEquals('/tmp/benchmarkGeekbench6-20251117.log', $pathFromPadded);
    }
}
