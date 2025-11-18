<?php

declare(strict_types=1);

namespace mcxForge\Storage;

require_once __DIR__ . '/../../bin/inventoryStorage.php';

/**
 * Devices
 *
 * Shared helpers for discovering storage devices for benchmarks.
 *
 * Responsibilities:
 *  - Discover physical block devices via inventoryStorage.php helpers.
 *  - Append MD RAID devices discovered via lsblk.
 *  - Apply an optional device filter on /dev/NAME paths.
 *
 * Returned device arrays follow the shape used by inventoryStorage.php:
 *  - path, name, bus, tran, sizeBytes, sizeGiB, model, scheme.
 *
 * @author Aleksi Ursin
 */
final class Devices
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public static function discoverDevices(?string $deviceFilter = null): array
    {
        $blockDevices = \getBlockDevices();
        if ($blockDevices === null) {
            return [];
        }

        $groups = \groupDevicesByBus($blockDevices, false);

        $devices = [];
        foreach ($groups as $busGroup) {
            foreach ($busGroup as $device) {
                if (!is_array($device)) {
                    continue;
                }
                $path = (string)($device['path'] ?? '');
                if ($path === '') {
                    continue;
                }
                if ($deviceFilter !== null && $path !== $deviceFilter) {
                    continue;
                }
                $devices[] = $device;
            }
        }

        $mdDevices = self::discoverMdRaidDevices();
        foreach ($mdDevices as $md) {
            if (!is_array($md)) {
                continue;
            }
            $path = (string)($md['path'] ?? '');
            if ($path === '') {
                continue;
            }
            if ($deviceFilter !== null && $path !== $deviceFilter) {
                continue;
            }
            $devices[] = $md;
        }

        return $devices;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function discoverMdRaidDevices(): array
    {
        $cmd = 'lsblk -J -b -d -o NAME,TYPE,SIZE,MODEL 2>/dev/null';
        $raw = shell_exec($cmd);
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['blockdevices']) || !is_array($decoded['blockdevices'])) {
            return [];
        }

        $devices = [];
        foreach ($decoded['blockdevices'] as $dev) {
            if (!is_array($dev)) {
                continue;
            }
            $type = strtolower((string)($dev['type'] ?? ''));
            $name = (string)($dev['name'] ?? '');
            if ($name === '') {
                continue;
            }
            if (strpos($type, 'raid') !== 0 && !str_starts_with($name, 'md')) {
                continue;
            }

            $sizeBytes = (int)($dev['size'] ?? 0);
            $sizeGiB = $sizeBytes > 0 ? (int)round($sizeBytes / (1024 * 1024 * 1024)) : 0;

            $modelRaw = (string)($dev['model'] ?? '');
            $model = trim($modelRaw) !== '' ? trim($modelRaw) : 'MD RAID';

            $devices[] = [
                'path' => '/dev/' . $name,
                'name' => $name,
                'bus' => 'MD',
                'tran' => '',
                'sizeBytes' => $sizeBytes,
                'sizeGiB' => $sizeGiB,
                'model' => $model,
                'scheme' => 'MD',
            ];
        }

        return $devices;
    }
}

