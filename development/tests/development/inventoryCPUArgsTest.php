<?php

declare(strict_types=1);

namespace mcxForge\Tests;

require_once __DIR__ . '/../../../bin/inventoryCPU.php';
require_once __DIR__ . '/../common/testCase.php';

final class inventoryCPUArgsTest extends testCase
{
    public function testDefaultArguments(): void
    {
        [$format, $colorEnabled] = \inventoryCPUParseArguments(
            ['inventoryCPU.php']
        );

        $this->assertEquals('human', $format);
        $this->assertTrue($colorEnabled === true);
    }

    public function testJsonFormatArgument(): void
    {
        [$format] = \inventoryCPUParseArguments(
            ['inventoryCPU.php', '--format=json']
        );

        $this->assertEquals('json', $format);
    }

    public function testPhpFormatArgument(): void
    {
        [$format] = \inventoryCPUParseArguments(
            ['inventoryCPU.php', '--format=php']
        );

        $this->assertEquals('php', $format);
    }

    public function testNoColorArgument(): void
    {
        [, $colorEnabled] = \inventoryCPUParseArguments(
            ['inventoryCPU.php', '--no-color']
        );

        $this->assertTrue($colorEnabled === false);
    }
}

