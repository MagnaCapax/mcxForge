<?php

declare(strict_types=1);

namespace mcxForge\Tests;

require_once __DIR__ . '/../../../bin/inventoryCPU.php';
require_once __DIR__ . '/../common/testCase.php';

final class inventoryCPUTopologyTest extends testCase
{
    public function testLogicalCoresAtLeastPhysical(): void
    {
        $topo = \inventoryCPUReadTopology();

        $this->assertTrue($topo['logicalCores'] >= $topo['physicalCores']);
    }

    public function testThreadsPerCoreAtLeastOne(): void
    {
        $topo = \inventoryCPUReadTopology();

        $this->assertTrue($topo['threadsPerCore'] >= 1);
    }

    public function testCoresPerSocketAtLeastOne(): void
    {
        $topo = \inventoryCPUReadTopology();

        $this->assertTrue($topo['coresPerSocket'] >= 1);
    }
}

