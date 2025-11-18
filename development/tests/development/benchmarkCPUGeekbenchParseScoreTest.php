<?php

declare(strict_types=1);

namespace mcxForge\Tests;

require_once __DIR__ . '/../../../lib/php/benchmark/GeekbenchRunner.php';

use mcxForge\Benchmark\GeekbenchRunner;

final class benchmarkCPUGeekbenchParseScoreTest extends testCase
{
    public function testParseGeekbench6MultiCorePreferred(): void
    {
        $runner = new GeekbenchRunner();

        $lines = [
            'Geekbench 6.5.0 Tryout for Linux x86 (64-bit)',
            'Single-Core Score           1234',
            'Multi-Core Score            5678',
        ];

        $score = $runner->parseScore($lines, '6');
        $this->assertEquals(5678, $score);
    }

    public function testParseGeekbench5MultiCorePreferred(): void
    {
        $runner = new GeekbenchRunner();

        $lines = [
            'Geekbench 5.5.1 Tryout for Linux x86 (64-bit)',
            'Single-Core Score           4321',
            'Multi-Core Score            8765',
        ];

        $score = $runner->parseScore($lines, '5');
        $this->assertEquals(8765, $score);
    }

    public function testParseGeekbenchWhenMultiCoreMissingUsesSingleCore(): void
    {
        $runner = new GeekbenchRunner();

        $lines = [
            'Geekbench 6.5.0 Tryout for Linux x86 (64-bit)',
            'Single-Core Score           2222',
        ];

        $score = $runner->parseScore($lines, '6');
        $this->assertEquals(2222, $score);
    }

    public function testParseGeekbenchScoreIgnoresShortNumbers(): void
    {
        $runner = new GeekbenchRunner();

        $lines = [
            'Multi-Core Score            99',
            'Single-Core Score           1234',
        ];

        $score = $runner->parseScore($lines, '6');
        $this->assertEquals(99, $score);
    }

    public function testParseGeekbenchScoreReturnsNullWhenNoMatches(): void
    {
        $runner = new GeekbenchRunner();

        $lines = [
            'Geekbench output without score headings',
            'More details, but no Single-Core or Multi-Core lines here',
        ];

        $score = $runner->parseScore($lines, '6');
        $this->assertTrue($score === null);
    }

    public function testBuildLogFilePathFormat(): void
    {
        $runner = new GeekbenchRunner();

        $date = new \DateTimeImmutable('2025-11-17 12:00:00');
        $path5 = $runner->buildLogFilePath('5', $date);
        $path6 = $runner->buildLogFilePath('6', $date);

        $this->assertEquals('/tmp/benchmarkGeekbench5-20251117.log', $path5);
        $this->assertEquals('/tmp/benchmarkGeekbench6-20251117.log', $path6);
    }

    public function testParseScoreIsCaseInsensitive(): void
    {
        $runner = new GeekbenchRunner();

        $lines = [
            'geekbench 6 output',
            'single-core score           1111',
            'multi-core score            2222',
        ];

        $score = $runner->parseScore($lines, '6');
        $this->assertEquals(2222, $score);
    }

    public function testParseScoreUsesFirstMultiCoreLine(): void
    {
        $runner = new GeekbenchRunner();

        $lines = [
            'Multi-Core Score            3000',
            'Some detail line',
            'Multi-Core Score            9999',
        ];

        $score = $runner->parseScore($lines, '6');
        $this->assertEquals(3000, $score);
    }

    public function testParseScoreHandlesTrailingText(): void
    {
        $runner = new GeekbenchRunner();

        $lines = [
            'Multi-Core Score            4444',
        ];

        $score = $runner->parseScore($lines, '6');
        $this->assertEquals(4444, $score);
    }
}
