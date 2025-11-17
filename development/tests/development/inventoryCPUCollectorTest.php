<?php

declare(strict_types=1);

namespace mcxForge\Tests;

require_once __DIR__ . '/../../../bin/inventoryCPU.php';
require_once __DIR__ . '/../common/testCase.php';

final class inventoryCPUCollectorTest extends testCase
{
    public function testCollectCpuInventoryHasBasicFields(): void
    {
        $info = \collectCpuInventory();

        $this->assertTrue(isset($info['vendor']));
        $this->assertTrue(isset($info['modelName']));
        $this->assertTrue(isset($info['logicalCores']));
        $this->assertTrue(isset($info['physicalCores']));
        $this->assertTrue(isset($info['sockets']));
    }

    public function testTopologyCountsArePositive(): void
    {
        $info = \collectCpuInventory();

        $this->assertTrue((int) $info['logicalCores'] >= 1);
        $this->assertTrue((int) $info['sockets'] >= 1);
        $this->assertTrue((int) $info['physicalCores'] >= 1);
    }

    public function testCacheInfoHasExpectedKeys(): void
    {
        $info = \collectCpuInventory();

        $this->assertTrue(isset($info['cache']));
        /** @var array<string,mixed> $cache */
        $cache = $info['cache'];
        $this->assertTrue(array_key_exists('L1d', $cache));
        $this->assertTrue(array_key_exists('L1i', $cache));
        $this->assertTrue(array_key_exists('L2', $cache));
        $this->assertTrue(array_key_exists('L3', $cache));
    }

    public function testFeaturesBlockIncludesExpectedFlags(): void
    {
        $info = \collectCpuInventory();

        $this->assertTrue(isset($info['features']));
        /** @var array<string,mixed> $features */
        $features = $info['features'];
        foreach (['aes', 'avx', 'avx2', 'sse4_2', 'smep', 'smap'] as $name) {
            $this->assertTrue(array_key_exists($name, $features));
        }
    }

    public function testEnvironmentBlockHasExpectedKeys(): void
    {
        $info = \collectCpuInventory();

        $this->assertTrue(isset($info['environment']));
        /** @var array<string,mixed> $env */
        $env = $info['environment'];
        $this->assertTrue(array_key_exists('isVirtualMachine', $env));
        $this->assertTrue(array_key_exists('hypervisorVendor', $env));
        $this->assertTrue(array_key_exists('role', $env));
    }

    public function testIommuBlockHasExpectedKeys(): void
    {
        $info = \collectCpuInventory();

        $this->assertTrue(isset($info['iommu']));
        /** @var array<string,mixed> $iommu */
        $iommu = $info['iommu'];
        $this->assertTrue(array_key_exists('enabled', $iommu));
        $this->assertTrue(array_key_exists('groupCount', $iommu));
    }

    public function testSriovBlockHasExpectedKeys(): void
    {
        $info = \collectCpuInventory();

        $this->assertTrue(isset($info['sriov']));
        /** @var array<string,mixed> $sriov */
        $sriov = $info['sriov'];
        $this->assertTrue(array_key_exists('hasSriovCapableDevices', $sriov));
    }

    public function testFamilyModelSteppingAreIntegersOrNull(): void
    {
        $info = \collectCpuInventory();

        $this->assertTrue(array_key_exists('family', $info));
        $this->assertTrue(array_key_exists('model', $info));
        $this->assertTrue(array_key_exists('stepping', $info));
        $this->assertTrue(
            $info['family'] === null || is_int($info['family']),
            'family should be int or null'
        );
        $this->assertTrue(
            $info['model'] === null || is_int($info['model']),
            'model should be int or null'
        );
        $this->assertTrue(
            $info['stepping'] === null || is_int($info['stepping']),
            'stepping should be int or null'
        );
    }

    public function testFeatureFlagsAreBoolean(): void
    {
        $info = \collectCpuInventory();
        /** @var array<string,mixed> $features */
        $features = $info['features'];

        foreach (['aes', 'avx', 'avx2', 'sse4_2', 'smep', 'smap'] as $name) {
            $this->assertTrue(is_bool($features[$name]), "feature {$name} should be boolean");
        }
    }
}
