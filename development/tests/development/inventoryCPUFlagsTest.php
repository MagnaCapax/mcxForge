<?php

declare(strict_types=1);

namespace mcxForge\Tests;

require_once __DIR__ . '/../../../bin/inventoryCPU.php';
require_once __DIR__ . '/../common/testCase.php';

final class inventoryCPUFlagsTest extends testCase
{
    public function testParseFlagsDetectsVmxVirtualization(): void
    {
        $flags = 'fpu vmx sse4_2';
        $parsed = \inventoryCPUParseFlags($flags);

        $this->assertEquals('vmx', $parsed['virtualization']);
        $this->assertTrue($parsed['sse4_2']);
    }

    public function testParseFlagsDetectsSvmVirtualization(): void
    {
        $flags = 'fpu svm avx avx2';
        $parsed = \inventoryCPUParseFlags($flags);

        $this->assertEquals('svm', $parsed['virtualization']);
        $this->assertTrue($parsed['avx']);
        $this->assertTrue($parsed['avx2']);
    }

    public function testParseFlagsNoVirtualizationWhenFlagsMissing(): void
    {
        $flags = 'fpu sse4_2 aes';
        $parsed = \inventoryCPUParseFlags($flags);

        $this->assertTrue($parsed['virtualization'] === null);
        $this->assertTrue($parsed['aes']);
        $this->assertTrue($parsed['sse4_2']);
    }

    public function testParseFlagsDetectsSecurityFeatures(): void
    {
        $flags = 'smep smap';
        $parsed = \inventoryCPUParseFlags($flags);

        $this->assertTrue($parsed['smep']);
        $this->assertTrue($parsed['smap']);
    }

    public function testParseFlagsHandlesEmptyString(): void
    {
        $parsed = \inventoryCPUParseFlags('');

        $this->assertTrue($parsed['virtualization'] === null);
        $this->assertTrue($parsed['aes'] === false);
        $this->assertTrue($parsed['avx'] === false);
    }

    public function testParseFlagsIgnoresUnknownFlags(): void
    {
        $flags = 'fpu someweirdflag anotherflag';
        $parsed = \inventoryCPUParseFlags($flags);

        $this->assertTrue($parsed['virtualization'] === null);
        $this->assertTrue($parsed['aes'] === false);
        $this->assertTrue($parsed['avx'] === false);
    }
}

