<?php

declare(strict_types=1);

namespace mcxForge\Tests;

require_once __DIR__ . '/../../../bin/inventoryCPU.php';
require_once __DIR__ . '/../common/testCase.php';

final class inventoryCPUEnvironmentTest extends testCase
{
    public function testDetectEnvironmentShape(): void
    {
        $info = \collectCpuInventory();
        /** @var array<string,mixed> $env */
        $env = $info['environment'];

        $this->assertTrue(is_bool($env['isVirtualMachine']));
        $this->assertTrue(
            $env['hypervisorVendor'] === null || is_string($env['hypervisorVendor']),
            'hypervisorVendor should be string or null'
        );
        $this->assertTrue(is_string($env['role']));
    }

    public function testDetectIommuShape(): void
    {
        $info = \collectCpuInventory();
        /** @var array<string,mixed> $iommu */
        $iommu = $info['iommu'];

        $this->assertTrue(is_bool($iommu['enabled']));
        $this->assertTrue(is_int($iommu['groupCount']));
    }

    public function testDetectSriovShape(): void
    {
        $info = \collectCpuInventory();
        /** @var array<string,mixed> $sriov */
        $sriov = $info['sriov'];

        $this->assertTrue(is_bool($sriov['hasSriovCapableDevices']));
    }
}

