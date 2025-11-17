<?php

declare(strict_types=1);

namespace mcxForge\Tests;

require_once __DIR__ . '/../../../bin/inventoryCPU.php';
require_once __DIR__ . '/../common/testCase.php';

final class inventoryCPUHumanRenderTest extends testCase
{
    /**
     * @return array<string,mixed>
     */
    private function minimalInfo(): array
    {
        return [
            'vendor' => 'TestVendor',
            'modelName' => 'Test CPU Model',
            'family' => 6,
            'model' => 158,
            'stepping' => 12,
            'mhz' => 3200.0,
            'bogoMips' => 6000.0,
            'sockets' => 1,
            'physicalCores' => 4,
            'logicalCores' => 8,
            'coresPerSocket' => 4,
            'threadsPerCore' => 2,
            'cache' => [
                'L1d' => '32K',
                'L1i' => '32K',
                'L2' => '256K',
                'L3' => '8192K',
            ],
            'virtualization' => 'vmx',
            'features' => [
                'aes' => true,
                'avx' => true,
                'avx2' => false,
                'sse4_2' => true,
                'smep' => false,
                'smap' => false,
            ],
            'environment' => [
                'isVirtualMachine' => false,
                'hypervisorVendor' => null,
                'role' => 'host',
            ],
            'iommu' => [
                'enabled' => true,
                'groupCount' => 4,
            ],
            'sriov' => [
                'hasSriovCapableDevices' => true,
            ],
        ];
    }

    public function testHumanRenderIncludesCpuInventoryHeader(): void
    {
        $info = $this->minimalInfo();

        ob_start();
        \inventoryCPURenderHuman($info, false);
        $out = (string) ob_get_clean();

        $this->assertTrue(strpos($out, 'CPU Inventory') !== false);
        $this->assertTrue(strpos($out, 'Topology') !== false);
        $this->assertTrue(strpos($out, 'Cache') !== false);
        $this->assertTrue(strpos($out, 'Features') !== false);
    }

    public function testHumanRenderIncludesModelAndFamilyTriplet(): void
    {
        $info = $this->minimalInfo();

        ob_start();
        \inventoryCPURenderHuman($info, false);
        $out = (string) ob_get_clean();

        $this->assertTrue(strpos($out, 'Test CPU Model') !== false);
        $this->assertTrue(strpos($out, 'Family/Model/Stepping') !== false);
    }

    public function testHumanRenderHandlesNullCacheGracefully(): void
    {
        $info = $this->minimalInfo();
        $info['cache'] = [
            'L1d' => null,
            'L1i' => null,
            'L2' => null,
            'L3' => null,
        ];

        ob_start();
        \inventoryCPURenderHuman($info, false);
        $out = (string) ob_get_clean();

        $this->assertTrue(strpos($out, 'L1d: N/A') !== false);
        $this->assertTrue(strpos($out, 'L3: N/A') !== false);
    }
}

