<?php

declare(strict_types=1);

namespace mcxForge\Tests;

require_once __DIR__ . '/../../../bin/storageList.php';

final class storageListGroupingTest extends testCase
{
    public function testDetermineBusTypeUsbByTran(): void
    {
        $device = [
            'tran' => 'usb',
            'name' => 'sda',
        ];

        $bus = \determineBusType($device);
        $this->assertEquals('USB', $bus);
    }

    public function testDetermineBusTypeSataByTran(): void
    {
        $device = [
            'tran' => 'sata',
            'name' => 'sdb',
        ];

        $bus = \determineBusType($device);
        $this->assertEquals('SATA', $bus);
    }

    public function testDetermineBusTypeSasAndScsi(): void
    {
        $sas = ['tran' => 'sas', 'name' => 'sdg'];
        $scsi = ['tran' => 'scsi', 'name' => 'sdh'];

        $this->assertEquals('SAS', \determineBusType($sas));
        $this->assertEquals('SAS', \determineBusType($scsi));
    }

    public function testDetermineBusTypeNvmeFallbackOnName(): void
    {
        $device = [
            'tran' => '',
            'name' => 'nvme0n1',
        ];

        $bus = \determineBusType($device);
        $this->assertEquals('NVME', $bus);
    }

    public function testDetermineBusTypeUnknownReturnsNull(): void
    {
        $device = [
            'tran' => 'firewire',
            'name' => 'sdz',
        ];

        $bus = \determineBusType($device);
        $this->assertTrue($bus === null, 'Unknown transport should result in null bus type');
    }

    public function testIsSmartCapableBusPredicate(): void
    {
        $this->assertTrue(\isSmartCapableBus('SATA'));
        $this->assertTrue(\isSmartCapableBus('SAS'));
        $this->assertTrue(\isSmartCapableBus('NVME'));
        $this->assertTrue(!\isSmartCapableBus('USB'));
    }

    public function testGroupDevicesByBusSkipsNonDiskTypes(): void
    {
        $devices = [
            ['type' => 'disk', 'tran' => 'sata', 'name' => 'sda', 'size' => 1024 * 1024 * 1024],
            ['type' => 'part', 'tran' => 'sata', 'name' => 'sda1', 'size' => 512 * 1024 * 1024],
        ];

        $groups = \groupDevicesByBus($devices, false);
        $this->assertEquals(1, count($groups['SATA']));
    }

    public function testGroupDevicesByBusComputesSizeGiBAndPath(): void
    {
        $devices = [
            ['type' => 'disk', 'tran' => 'sata', 'name' => 'sdc', 'size' => 2.5 * 1024 * 1024 * 1024],
        ];

        $groups = \groupDevicesByBus($devices, false);
        $this->assertEquals(1, count($groups['SATA']));

        $entry = $groups['SATA'][0];
        $this->assertEquals('/dev/sdc', $entry['path']);
        $this->assertEquals(3, $entry['sizeGiB'], 'Size should be rounded to nearest GiB');
    }

    public function testGroupDevicesByBusSmartOnlyFiltersByBus(): void
    {
        $devices = [
            ['type' => 'disk', 'tran' => 'usb', 'name' => 'sdd', 'size' => 100],
            ['type' => 'disk', 'tran' => 'sata', 'name' => 'sde', 'size' => 100],
        ];

        $groups = \groupDevicesByBus($devices, true);
        $this->assertEquals(0, count($groups['USB']));
        $this->assertEquals(1, count($groups['SATA']));
    }

    public function testGroupDevicesByBusSkipsUnknownBus(): void
    {
        $devices = [
            ['type' => 'disk', 'tran' => 'firewire', 'name' => 'sdf', 'size' => 100],
        ];

        $groups = \groupDevicesByBus($devices, false);
        $this->assertEquals(0, count($groups['USB']) + count($groups['SATA']) + count($groups['SAS']) + count($groups['NVME']));
    }

    public function testGroupDevicesByBusTrimsModelAndUsesUnknown(): void
    {
        $devices = [
            ['type' => 'disk', 'tran' => 'sata', 'name' => 'sdg', 'size' => 100, 'model' => "  ExampleDisk  "],
            ['type' => 'disk', 'tran' => 'sata', 'name' => 'sdh', 'size' => 100, 'model' => ""],
        ];

        $groups = \groupDevicesByBus($devices, false);
        $this->assertEquals('ExampleDisk', $groups['SATA'][0]['model']);
        $this->assertEquals('UNKNOWN', $groups['SATA'][1]['model']);
    }
}
