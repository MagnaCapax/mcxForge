<?php

declare(strict_types=1);

namespace mcxForge\Tests;

require_once __DIR__ . '/../common/testCase.php';
require_once __DIR__ . '/../../../lib/php/StorageWipe.php';

final class storageWipePlanTest extends testCase
{
    /**
     * @param array<string,mixed> $device
     * @param array<string,mixed> $options
     * @return array<int,array<string,mixed>>
     */
    private function buildPlan(array $device, array $options): array
    {
        $ref = new \ReflectionClass(\mcxForge\StorageWipeRunner::class);
        $method = $ref->getMethod('buildWipePlanForDevice');
        $method->setAccessible(true);

        /** @var array<int,array<string,mixed>> $plan */
        $plan = $method->invoke(null, $device, $options);

        return $plan;
    }

    /**
     * @param array<string,mixed> $node
     */
    private function callFindRootDiskInTopologyNode(array $node): ?string
    {
        $ref = new \ReflectionClass(\mcxForge\StorageWipeRunner::class);
        $method = $ref->getMethod('findRootDiskInTopologyNode');
        $method->setAccessible(true);

        /** @var string|null $result */
        $result = $method->invoke(null, $node);

        return $result;
    }

    /**
     * @param array<string,mixed> $node
     * @param array<string,int>   $targetSet
     * @param array<int,array<string,mixed>> $arraysToStop
     */
    private function callCollectMdArraysFromNode(array $node, array $targetSet, array &$arraysToStop): void
    {
        $ref = new \ReflectionClass(\mcxForge\StorageWipeRunner::class);
        $method = $ref->getMethod('collectMdArraysFromNode');
        $method->setAccessible(true);
        $method->invokeArgs(null, [&$node, $targetSet, &$arraysToStop]);
    }

    /**
     * @param array<string,mixed> $node
     * @param array<int,string>   $disks
     * @param array<int,string>   $mountpoints
     */
    private function callCollectDisksAndMountpoints(array $node, array &$disks, array &$mountpoints): void
    {
        $ref = new \ReflectionClass(\mcxForge\StorageWipeRunner::class);
        $method = $ref->getMethod('collectDisksAndMountpoints');
        $method->setAccessible(true);
        $method->invokeArgs(null, [&$node, &$disks, &$mountpoints]);
    }

    public function testBaselinePlanHasCoreSteps(): void
    {
        $device = [
            'path' => '/dev/sda',
            'sizeBytes' => 100 * 1024 * 1024 * 1024,
            'bus' => 'SATA',
            'isSsd' => false,
        ];
        $options = [
            'passes' => 0,
            'secureErase' => false,
            'autoSecureErase' => true,
            'randomDataWrite' => false,
        ];

        $plan = $this->buildPlan($device, $options);
        $this->assertEquals(3, count($plan), 'Baseline plan should have three steps');
        $this->assertTrue(str_contains($plan[0]['description'], 'wipefs -a'));
        $this->assertTrue(str_contains($plan[1]['description'], 'blkdiscard'));
        $this->assertTrue(str_contains($plan[2]['description'], 'dd zero header'));
    }

    public function testBaselinePlanHasNoFullCoverageStep(): void
    {
        $device = [
            'path' => '/dev/sdb',
            'sizeBytes' => 10 * 1024 * 1024 * 1024,
            'bus' => 'SATA',
            'isSsd' => false,
        ];
        $options = [
            'passes' => 0,
            'secureErase' => false,
            'autoSecureErase' => true,
            'randomDataWrite' => false,
        ];

        $plan = $this->buildPlan($device, $options);
        foreach ($plan as $step) {
            $this->assertTrue($step['coversWholeDevice'] === false);
        }
    }

    public function testSinglePassAddsFullDeviceOverwrite(): void
    {
        $device = [
            'path' => '/dev/sdc',
            'sizeBytes' => 5 * 1024 * 1024 * 1024,
            'bus' => 'SATA',
            'isSsd' => false,
        ];
        $options = [
            'passes' => 1,
            'secureErase' => false,
            'autoSecureErase' => true,
            'randomDataWrite' => false,
        ];

        $plan = $this->buildPlan($device, $options);
        $this->assertEquals(4, count($plan));
        $last = $plan[3];
        $this->assertTrue($last['coversWholeDevice'] === true);
        $this->assertTrue(str_contains($last['command'], 'dd if=/dev/zero'));
    }

    public function testMultiPassAddsMultipleFullOverwrites(): void
    {
        $device = [
            'path' => '/dev/sdd',
            'sizeBytes' => 20 * 1024 * 1024 * 1024,
            'bus' => 'SATA',
            'isSsd' => false,
        ];
        $options = [
            'passes' => 3,
            'secureErase' => false,
            'autoSecureErase' => true,
            'randomDataWrite' => false,
        ];

        $plan = $this->buildPlan($device, $options);
        $full = array_filter($plan, static fn ($step) => $step['coversWholeDevice'] === true);
        $this->assertEquals(3, count($full), 'Expected three full-device overwrite passes');
    }

    public function testExplicitSecureEraseAddsHdparmStepsOnHdd(): void
    {
        $device = [
            'path' => '/dev/sde',
            'sizeBytes' => 50 * 1024 * 1024 * 1024,
            'bus' => 'SATA',
            'isSsd' => false,
        ];
        $options = [
            'passes' => 0,
            'secureErase' => true,
            'autoSecureErase' => true,
            'randomDataWrite' => false,
        ];

        $plan = $this->buildPlan($device, $options);
        $descriptions = array_column($plan, 'description');
        $this->assertTrue($this->containsSubstring($descriptions, 'hdparm identify /dev/sde'));
        $this->assertTrue($this->containsSubstring($descriptions, 'hdparm security-set-pass on /dev/sde'));
        $this->assertTrue($this->containsSubstring($descriptions, 'hdparm security-erase on /dev/sde'));
    }

    public function testAutoAtaSecureEraseForSsdSata(): void
    {
        $device = [
            'path' => '/dev/sdf',
            'sizeBytes' => 50 * 1024 * 1024 * 1024,
            'bus' => 'SATA',
            'isSsd' => true,
        ];
        $options = [
            'passes' => 0,
            'secureErase' => false,
            'autoSecureErase' => true,
            'randomDataWrite' => false,
        ];

        $plan = $this->buildPlan($device, $options);
        $descriptions = array_column($plan, 'description');
        $this->assertTrue($this->containsSubstring($descriptions, 'hdparm security-erase on /dev/sdf'));
    }

    public function testNoAutoSecureEraseWhenDisabled(): void
    {
        $device = [
            'path' => '/dev/sdg',
            'sizeBytes' => 50 * 1024 * 1024 * 1024,
            'bus' => 'SATA',
            'isSsd' => true,
        ];
        $options = [
            'passes' => 0,
            'secureErase' => false,
            'autoSecureErase' => false,
            'randomDataWrite' => false,
        ];

        $plan = $this->buildPlan($device, $options);
        $cmds = array_column($plan, 'command');
        $this->assertTrue(!$this->containsSubstring($cmds, 'hdparm --security-erase'));
    }

    public function testAutoNvmeSecureEraseAddsNvmeCommands(): void
    {
        $device = [
            'path' => '/dev/nvme0n1',
            'sizeBytes' => 200 * 1024 * 1024 * 1024,
            'bus' => 'NVME',
            'isSsd' => true,
        ];
        $options = [
            'passes' => 0,
            'secureErase' => false,
            'autoSecureErase' => true,
            'randomDataWrite' => false,
        ];

        $plan = $this->buildPlan($device, $options);
        $cmds = array_column($plan, 'command');
        $this->assertTrue($this->containsSubstring($cmds, 'nvme id-ctrl'));
        $this->assertTrue($this->containsSubstring($cmds, 'nvme format'));
    }

    public function testExplicitSecureEraseForNvmeWhenAutoDisabled(): void
    {
        $device = [
            'path' => '/dev/nvme1n1',
            'sizeBytes' => 200 * 1024 * 1024 * 1024,
            'bus' => 'NVME',
            'isSsd' => true,
        ];
        $options = [
            'passes' => 0,
            'secureErase' => true,
            'autoSecureErase' => false,
            'randomDataWrite' => false,
        ];

        $plan = $this->buildPlan($device, $options);
        $cmds = array_column($plan, 'command');
        $this->assertTrue($this->containsSubstring($cmds, 'nvme format'));
    }

    public function testRandomDataWriteAddsScriptStep(): void
    {
        $device = [
            'path' => '/dev/sdh',
            'sizeBytes' => 30 * 1024 * 1024 * 1024,
            'bus' => 'SATA',
            'isSsd' => false,
        ];
        $options = [
            'passes' => 0,
            'secureErase' => false,
            'autoSecureErase' => true,
            'randomDataWrite' => true,
            'randomDurationSeconds' => 600,
            'randomWorkersPerDevice' => 4,
        ];

        $plan = $this->buildPlan($device, $options);
        $last = $plan[count($plan) - 1];
        $this->assertTrue(str_contains($last['description'], 'random data write'));
        $this->assertTrue(str_contains($last['command'], 'bash -c'));
        $this->assertTrue($last['coversWholeDevice'] === false);
    }

    public function testRandomDataWriteRespectsWorkerCountAndDuration(): void
    {
        $device = [
            'path' => '/dev/sdi',
            'sizeBytes' => 30 * 1024 * 1024 * 1024,
            'bus' => 'SATA',
            'isSsd' => false,
        ];
        $options = [
            'passes' => 0,
            'secureErase' => false,
            'autoSecureErase' => true,
            'randomDataWrite' => true,
            'randomDurationSeconds' => 120,
            'randomWorkersPerDevice' => 3,
        ];

        $plan = $this->buildPlan($device, $options);
        $cmd = $plan[count($plan) - 1]['command'];
        // Command shape: bash -c '<script>' -- '/dev/sdi' 120 3
        $this->assertTrue(str_contains($cmd, " '/dev/sdi' 120 3"), 'Device, duration and workers should be passed to script');
    }

    public function testPassesAndSecureErasePlanOrder(): void
    {
        $device = [
            'path' => '/dev/sdj',
            'sizeBytes' => 40 * 1024 * 1024 * 1024,
            'bus' => 'SATA',
            'isSsd' => true,
        ];
        $options = [
            'passes' => 2,
            'secureErase' => true,
            'autoSecureErase' => true,
            'randomDataWrite' => false,
        ];

        $plan = $this->buildPlan($device, $options);
        $descriptions = array_column($plan, 'description');

        $wipefsIndex = $this->indexOfFirstContaining($descriptions, 'wipefs -a');
        $passIndex = $this->indexOfFirstContaining($descriptions, 'full-device zero overwrite pass');
        $eraseIndex = $this->indexOfFirstContaining($descriptions, 'security-erase');

        $this->assertTrue($wipefsIndex < $passIndex);
        $this->assertTrue($passIndex < $eraseIndex);
    }

    public function testCoverageFlagsSetOnFullPassAndSecureErase(): void
    {
        $device = [
            'path' => '/dev/sdk',
            'sizeBytes' => 40 * 1024 * 1024 * 1024,
            'bus' => 'SATA',
            'isSsd' => true,
        ];
        $options = [
            'passes' => 1,
            'secureErase' => true,
            'autoSecureErase' => true,
            'randomDataWrite' => false,
        ];

        $plan = $this->buildPlan($device, $options);
        $coverageSteps = array_filter($plan, static fn ($step) => $step['coversWholeDevice'] === true);
        $this->assertTrue(count($coverageSteps) >= 2, 'Expected both pass and secure erase to be full coverage');
    }

    public function testCoverageFlagsSetOnNvmeFormat(): void
    {
        $device = [
            'path' => '/dev/nvme2n1',
            'sizeBytes' => 40 * 1024 * 1024 * 1024,
            'bus' => 'NVME',
            'isSsd' => true,
        ];
        $options = [
            'passes' => 0,
            'secureErase' => true,
            'autoSecureErase' => false,
            'randomDataWrite' => false,
        ];

        $plan = $this->buildPlan($device, $options);
        $coverageSteps = array_filter($plan, static fn ($step) => $step['coversWholeDevice'] === true);
        $this->assertTrue(count($coverageSteps) === 1);

        $commands = array_column($coverageSteps, 'command');
        $this->assertTrue($this->containsSubstring($commands, 'nvme format'));
    }

    public function testSizeMiBMinimumForTinyDevice(): void
    {
        $device = [
            'path' => '/dev/sdl',
            'sizeBytes' => 512 * 1024,
            'bus' => 'SATA',
            'isSsd' => false,
        ];
        $options = [
            'passes' => 1,
            'secureErase' => false,
            'autoSecureErase' => true,
            'randomDataWrite' => false,
        ];

        $plan = $this->buildPlan($device, $options);
        $full = array_filter($plan, static fn ($step) => $step['coversWholeDevice'] === true);
        $this->assertEquals(1, count($full));
        $cmd = current($full)['command'];
        $this->assertTrue(str_contains($cmd, 'count=1'), 'Tiny devices should still use count=1 MiB');
    }

    public function testFindRootDiskWhenRootOnDiskNode(): void
    {
        $node = [
            'name' => 'sda',
            'type' => 'disk',
            'mountpoint' => '/',
        ];

        $disk = $this->callFindRootDiskInTopologyNode($node);
        $this->assertEquals('sda', $disk);
    }

    public function testFindRootDiskWhenRootOnPartitionUsesPkname(): void
    {
        $node = [
            'name' => 'sda',
            'type' => 'disk',
            'mountpoint' => null,
            'children' => [
                [
                    'name' => 'sda1',
                    'type' => 'part',
                    'mountpoint' => '/',
                    'pkname' => 'sda',
                ],
            ],
        ];

        $disk = $this->callFindRootDiskInTopologyNode($node);
        $this->assertEquals('sda', $disk);
    }

    public function testFindRootDiskReturnsNullWhenNoRoot(): void
    {
        $node = [
            'name' => 'sdb',
            'type' => 'disk',
            'mountpoint' => null,
        ];

        $disk = $this->callFindRootDiskInTopologyNode($node);
        $this->assertTrue($disk === null);
    }

    public function testCollectDisksAndMountpointsCollectsFromRoot(): void
    {
        $node = [
            'name' => 'sdc',
            'type' => 'disk',
            'mountpoint' => '/mnt/data',
        ];

        $disks = [];
        $mounts = [];
        $this->callCollectDisksAndMountpoints($node, $disks, $mounts);

        $this->assertEquals(['sdc'], $disks);
        $this->assertEquals(['/mnt/data'], $mounts);
    }

    public function testCollectDisksAndMountpointsCollectsFromChildren(): void
    {
        $node = [
            'name' => 'md0',
            'type' => 'raid1',
            'mountpoint' => '/srv',
            'children' => [
                [
                    'name' => 'sdd',
                    'type' => 'disk',
                    'mountpoint' => null,
                ],
                [
                    'name' => 'sde',
                    'type' => 'disk',
                    'mountpoint' => null,
                ],
            ],
        ];

        $disks = [];
        $mounts = [];
        $this->callCollectDisksAndMountpoints($node, $disks, $mounts);

        sort($disks);
        sort($mounts);

        $this->assertEquals(['sdd', 'sde'], $disks);
        $this->assertEquals(['/srv'], $mounts);
    }

    public function testCollectMdArraysFindsArrayWithTargetMember(): void
    {
        $node = [
            'name' => 'md0',
            'type' => 'raid1',
            'path' => '/dev/md0',
            'mountpoint' => '/srv',
            'children' => [
                [
                    'name' => 'sdf',
                    'type' => 'disk',
                    'mountpoint' => null,
                ],
                [
                    'name' => 'sdg',
                    'type' => 'disk',
                    'mountpoint' => null,
                ],
            ],
        ];

        $targetSet = ['sdf' => 1];
        $arrays = [];
        $this->callCollectMdArraysFromNode($node, $targetSet, $arrays);

        $this->assertEquals(1, count($arrays));
        $this->assertEquals('/dev/md0', $arrays[0]['path']);
        sort($arrays[0]['memberDisks']);
        $this->assertEquals(['sdf', 'sdg'], $arrays[0]['memberDisks']);
    }

    public function testCollectMdArraysIgnoresArrayWithoutTargetMember(): void
    {
        $node = [
            'name' => 'md1',
            'type' => 'raid1',
            'path' => '/dev/md1',
            'mountpoint' => '/srv2',
            'children' => [
                [
                    'name' => 'sdh',
                    'type' => 'disk',
                    'mountpoint' => null,
                ],
            ],
        ];

        $targetSet = ['sdi' => 1];
        $arrays = [];
        $this->callCollectMdArraysFromNode($node, $targetSet, $arrays);
        $this->assertEquals(0, count($arrays));
    }

    public function testCollectMdArraysCapturesMountpoints(): void
    {
        $node = [
            'name' => 'md2',
            'type' => 'raid5',
            'path' => '/dev/md2',
            'mountpoint' => '/data',
            'children' => [
                [
                    'name' => 'sdd',
                    'type' => 'disk',
                    'mountpoint' => '/data/sub',
                ],
            ],
        ];

        $targetSet = ['sdd' => 1];
        $arrays = [];
        $this->callCollectMdArraysFromNode($node, $targetSet, $arrays);

        $this->assertEquals(1, count($arrays));
        sort($arrays[0]['mountpoints']);
        $this->assertEquals(['/data', '/data/sub'], $arrays[0]['mountpoints']);
    }

    public function testRandomDataWriteDescriptionContainsDevicePath(): void
    {
        $device = [
            'path' => '/dev/sdm',
            'sizeBytes' => 60 * 1024 * 1024 * 1024,
            'bus' => 'SATA',
            'isSsd' => false,
        ];
        $options = [
            'passes' => 0,
            'secureErase' => false,
            'autoSecureErase' => true,
            'randomDataWrite' => true,
            'randomDurationSeconds' => 300,
            'randomWorkersPerDevice' => 2,
        ];

        $plan = $this->buildPlan($device, $options);
        $last = $plan[count($plan) - 1];
        $this->assertTrue(str_contains($last['description'], '/dev/sdm'));
    }

    public function testSecureEraseAndRandomDataWritePlanHasExpectedOrder(): void
    {
        $device = [
            'path' => '/dev/sdn',
            'sizeBytes' => 80 * 1024 * 1024 * 1024,
            'bus' => 'SATA',
            'isSsd' => true,
        ];
        $options = [
            'passes' => 1,
            'secureErase' => true,
            'autoSecureErase' => true,
            'randomDataWrite' => true,
            'randomDurationSeconds' => 300,
            'randomWorkersPerDevice' => 2,
        ];

        $plan = $this->buildPlan($device, $options);
        $descriptions = array_column($plan, 'description');

        $passIndex = $this->indexOfFirstContaining($descriptions, 'full-device zero overwrite pass');
        $eraseIndex = $this->indexOfFirstContaining($descriptions, 'security-erase');
        $randomIndex = $this->indexOfFirstContaining($descriptions, 'random data write');

        $this->assertTrue($passIndex < $eraseIndex);
        $this->assertTrue($eraseIndex < $randomIndex);
    }

    public function testRandomDataWriteDoesNotSetCoverageFlag(): void
    {
        $device = [
            'path' => '/dev/sdo',
            'sizeBytes' => 80 * 1024 * 1024 * 1024,
            'bus' => 'SATA',
            'isSsd' => false,
        ];
        $options = [
            'passes' => 0,
            'secureErase' => false,
            'autoSecureErase' => true,
            'randomDataWrite' => true,
            'randomDurationSeconds' => 300,
            'randomWorkersPerDevice' => 2,
        ];

        $plan = $this->buildPlan($device, $options);
        $last = $plan[count($plan) - 1];
        $this->assertTrue($last['coversWholeDevice'] === false);
    }

    public function testNoAutoSecureEraseEvenForNvmeWhenDisabled(): void
    {
        $device = [
            'path' => '/dev/nvme3n1',
            'sizeBytes' => 100 * 1024 * 1024 * 1024,
            'bus' => 'NVME',
            'isSsd' => true,
        ];
        $options = [
            'passes' => 0,
            'secureErase' => false,
            'autoSecureErase' => false,
            'randomDataWrite' => false,
        ];

        $plan = $this->buildPlan($device, $options);
        $cmds = array_column($plan, 'command');
        $this->assertTrue(!$this->containsSubstring($cmds, 'nvme format'));
    }

    public function testHdparmSecureEraseCommandsAreShellEscaped(): void
    {
        $device = [
            'path' => "/dev/sdp",
            'sizeBytes' => 100 * 1024 * 1024 * 1024,
            'bus' => 'SATA',
            'isSsd' => true,
        ];
        $options = [
            'passes' => 0,
            'secureErase' => true,
            'autoSecureErase' => true,
            'randomDataWrite' => false,
        ];

        $plan = $this->buildPlan($device, $options);
        $cmds = array_column($plan, 'command');
        $escaped = "hdparm --user-master u --security-erase mcxforge '/dev/sdp'";
        $this->assertTrue($this->containsSubstring($cmds, $escaped));
    }

    public function testNvmeSecureEraseCommandsAreShellEscaped(): void
    {
        $device = [
            'path' => "/dev/nvme4n1",
            'sizeBytes' => 100 * 1024 * 1024 * 1024,
            'bus' => 'NVME',
            'isSsd' => true,
        ];
        $options = [
            'passes' => 0,
            'secureErase' => true,
            'autoSecureErase' => true,
            'randomDataWrite' => false,
        ];

        $plan = $this->buildPlan($device, $options);
        $cmds = array_column($plan, 'command');
        $escaped = "nvme format '" . "/dev/nvme4n1" . "'";
        $this->assertTrue($this->containsSubstring($cmds, $escaped));
    }

    /**
     * @param array<int,string> $values
     */
    private function containsSubstring(array $values, string $needle): bool
    {
        foreach ($values as $value) {
            if (strpos($value, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int,string> $values
     */
    private function indexOfFirstContaining(array $values, string $needle): int
    {
        foreach ($values as $index => $value) {
            if (strpos($value, $needle) !== false) {
                return (int) $index;
            }
        }

        return -1;
    }
}
