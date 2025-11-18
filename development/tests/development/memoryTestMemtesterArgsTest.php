<?php

declare(strict_types=1);

namespace mcxForge\Tests;

require_once __DIR__ . '/../../../bin/memoryTestMemtester.php';

final class memoryTestMemtesterArgsTest extends testCase
{
    public function testDefaultArguments(): void
    {
        [$threads, $percent, $passes, $force, $colorEnabled] =
            \memoryTestMemtesterParseArguments(['memoryTestMemtester.php']);

        $this->assertTrue($threads >= 1);
        $this->assertEquals(90, $percent);
        $this->assertEquals(1, $passes);
        $this->assertEquals(false, $force);
        $this->assertEquals(true, $colorEnabled);
    }

    public function testCustomArguments(): void
    {
        [$threads, $percent, $passes, $force, $colorEnabled] =
            \memoryTestMemtesterParseArguments([
                'memoryTestMemtester.php',
                '--threads=8',
                '--percent=95',
                '--passes=3',
                '--force',
                '--no-color',
            ]);

        $this->assertEquals(8, $threads);
        $this->assertEquals(95, $percent);
        $this->assertEquals(3, $passes);
        $this->assertEquals(true, $force);
        $this->assertEquals(false, $colorEnabled);
    }
}

