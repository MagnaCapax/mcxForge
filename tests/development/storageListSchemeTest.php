<?php

declare(strict_types=1);

namespace mcxForge\Tests;

require_once __DIR__ . '/../../bin/storageList.php';

final class storageListSchemeTest extends testCase
{
    public function testDetermineSchemeRaid(): void
    {
        $device = [
            'pttype' => 'gpt',
            'fstype' => 'linux_raid_member',
        ];

        $scheme = \determineScheme($device);
        $this->assertEquals('RAID', $scheme);
    }

    public function testDetermineSchemeGpt(): void
    {
        $device = [
            'pttype' => 'gpt',
            'fstype' => 'ext4',
        ];

        $scheme = \determineScheme($device);
        $this->assertEquals('GPT', $scheme);
    }

    public function testDetermineSchemeBios(): void
    {
        $device = [
            'pttype' => 'dos',
            'fstype' => 'ext4',
        ];

        $scheme = \determineScheme($device);
        $this->assertEquals('BIOS', $scheme);
    }

    public function testDetermineSchemeNoneWhenUnknown(): void
    {
        $device = [
            'pttype' => '',
            'fstype' => '',
        ];

        $scheme = \determineScheme($device);
        $this->assertEquals('NONE', $scheme);
    }

    public function testDetermineSchemeRaidTakesPrecedence(): void
    {
        $device = [
            'pttype' => 'dos',
            'fstype' => 'linux_raid_member',
        ];

        $scheme = \determineScheme($device);
        $this->assertEquals('RAID', $scheme);
    }

    public function testDetermineSchemeCaseInsensitivity(): void
    {
        $device = [
            'pttype' => 'GpT',
            'fstype' => 'LiNuX_RaId_MeMbEr',
        ];

        $scheme = \determineScheme($device);
        $this->assertEquals('RAID', $scheme);
    }
}

