#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * storageList.php
 *
 * List block storage devices grouped by bus (USB, SATA, SAS, NVME) with
 * normalized sizes in GiB. Supports human-readable output (default),
 * JSON, and PHP serialize formats.
 *
 * This script is read-only and intended for use on live systems to feed
 * partitioning and monitoring logic.
 */

const EXIT_OK = 0;
const EXIT_ERROR = 1;

function main(array $argv): int
{
    [$format, $smartOnly, $colorEnabled] = parseArguments($argv);

    $devices = getBlockDevices();
    if ($devices === null) {
        fwrite(STDERR, "Error: failed to execute lsblk or parse its output.\n");
        return EXIT_ERROR;
    }

    $grouped = groupDevicesByBus($devices, $smartOnly);

    switch ($format) {
        case 'json':
            renderJson($grouped);
            break;
        case 'php':
            renderPhp($grouped);
            break;
        case 'human':
        default:
            renderHuman($grouped, $colorEnabled);
            break;
    }

    return EXIT_OK;
}

/**
 * @return array{0:string,1:bool,2:bool}
 */
function parseArguments(array $argv): array
{
    $format = 'human';
    $smartOnly = false;
    $colorEnabled = true;

    if (getenv('NO_COLOR') !== false) {
        $colorEnabled = false;
    }

    $args = $argv;
    array_shift($args); // script name

    foreach ($args as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            printHelp();
            exit(EXIT_OK);
        }

        if (str_starts_with($arg, '--format=')) {
            $value = substr($arg, strlen('--format='));
            if (!in_array($value, ['human', 'json', 'php'], true)) {
                fwrite(STDERR, "Error: unsupported format '$value'. Use human, json, or php.\n");
                exit(EXIT_ERROR);
            }
            $format = $value;
            continue;
        }

        if ($arg === '--smart-only') {
            $smartOnly = true;
            continue;
        }

        if ($arg === '--no-color') {
            $colorEnabled = false;
            continue;
        }

        fwrite(STDERR, "Error: unrecognized argument '$arg'. Use --help for usage.\n");
        exit(EXIT_ERROR);
    }

    return [$format, $smartOnly, $colorEnabled];
}

/**
 * @return array<int,array<string,mixed>>|null
 */
function getBlockDevices(): ?array
{
    $cmd = 'lsblk -J -b -d -o NAME,TYPE,SIZE,TRAN,MODEL,PTTYPE,FSTYPE 2>/dev/null';
    $output = shell_exec($cmd);

    if ($output === null || $output === '') {
        return null;
    }

    $decoded = json_decode($output, true);
    if (!is_array($decoded) || !isset($decoded['blockdevices']) || !is_array($decoded['blockdevices'])) {
        return null;
    }

    return $decoded['blockdevices'];
}

/**
 * @param array<int,array<string,mixed>> $devices
 * @return array<string,array<int,array<string,mixed>>>
 */
function groupDevicesByBus(array $devices, bool $smartOnly): array
{
    $groups = [
        'USB' => [],
        'SATA' => [],
        'SAS' => [],
        'NVME' => [],
    ];

    foreach ($devices as $device) {
        $type = strtolower((string)($device['type'] ?? ''));
        if ($type !== 'disk') {
            continue;
        }

        $bus = determineBusType($device);
        if ($bus === null || !isset($groups[$bus])) {
            continue;
        }

        if ($smartOnly && !isSmartCapableBus($bus)) {
            continue;
        }

        $name = (string)($device['name'] ?? '');
        if ($name === '') {
            continue;
        }

        $sizeBytes = (int)($device['size'] ?? 0);
        $sizeGiB = $sizeBytes > 0 ? (int)round($sizeBytes / (1024 * 1024 * 1024)) : 0;

        $modelRaw = (string)($device['model'] ?? '');
        $model = trim($modelRaw) !== '' ? trim($modelRaw) : 'UNKNOWN';

        $tran = strtolower((string)($device['tran'] ?? ''));
        $scheme = determineScheme($device);

        $groups[$bus][] = [
            'path' => '/dev/' . $name,
            'name' => $name,
            'bus' => $bus,
            'tran' => $tran,
            'sizeBytes' => $sizeBytes,
            'sizeGiB' => $sizeGiB,
            'model' => $model,
            'scheme' => $scheme,
        ];
    }

    return $groups;
}

/**
 * @param array<string,mixed> $device
 */
function determineBusType(array $device): ?string
{
    $tran = strtolower((string)($device['tran'] ?? ''));
    $name = (string)($device['name'] ?? '');

    if ($tran === 'usb') {
        return 'USB';
    }

    if ($tran === 'sata') {
        return 'SATA';
    }

    if ($tran === 'sas' || $tran === 'scsi') {
        return 'SAS';
    }

    if ($tran === 'nvme' || str_starts_with($name, 'nvme')) {
        return 'NVME';
    }

    return null;
}

/**
 * @param array<string,mixed> $device
 */
function determineScheme(array $device): string
{
    $pttype = strtolower((string)($device['pttype'] ?? ''));
    $fstype = strtolower((string)($device['fstype'] ?? ''));

    if ($fstype === 'linux_raid_member') {
        return 'RAID';
    }

    if ($pttype === 'gpt') {
        return 'GPT';
    }

    if ($pttype === 'dos') {
        return 'BIOS';
    }

    return 'NONE';
}

function isSmartCapableBus(string $bus): bool
{
    return in_array($bus, ['SATA', 'SAS', 'NVME'], true);
}

/**
 * @param array<string,array<int,array<string,mixed>>> $groups
 */
function renderHuman(array $groups, bool $colorEnabled): void
{
    $order = ['USB', 'SATA', 'SAS', 'NVME'];

    $sectionColor = $colorEnabled ? "\033[1;34m" : '';
    $deviceColor = $colorEnabled ? "\033[0;36m" : '';
    $schemeColor = $colorEnabled ? "\033[0;33m" : '';
    $resetColor = $colorEnabled ? "\033[0m" : '';

    $firstGroupPrinted = false;

    foreach ($order as $bus) {
        $devices = $groups[$bus] ?? [];
        if (count($devices) === 0) {
            continue;
        }

        if ($firstGroupPrinted) {
            echo PHP_EOL;
        }

        echo $sectionColor . $bus . $resetColor . PHP_EOL;

        foreach ($devices as $device) {
            $path = $deviceColor . $device['path'] . $resetColor;
            $size = (int)$device['sizeGiB'] . 'GiB';
            $model = $device['model'];
            $scheme = $schemeColor . $device['scheme'] . $resetColor;

            $line = sprintf(
                "  * %s; %s; %s; %s",
                $path,
                $size,
                $model,
                $scheme
            );
            echo $line . PHP_EOL;
        }

        $firstGroupPrinted = true;
    }
}

/**
 * @param array<string,array<int,array<string,mixed>>> $groups
 */
function renderJson(array $groups): void
{
    $encoded = json_encode($groups, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        fwrite(STDERR, "Error: failed to encode JSON output.\n");
        exit(EXIT_ERROR);
    }

    echo $encoded . PHP_EOL;
}

/**
 * @param array<string,array<int,array<string,mixed>>> $groups
 */
function renderPhp(array $groups): void
{
    echo serialize($groups) . PHP_EOL;
}

function printHelp(): void
{
    $help = <<<TEXT
Usage: storageList.php [--format=human|json|php] [--smart-only] [--no-color]

List block storage devices grouped by bus (USB, SATA, SAS, NVME) with sizes in GiB
and a simple scheme indicator (NONE, GPT, BIOS, RAID).

Options:
  --format=human    Human-readable output (default).
  --format=json     JSON output grouped by bus.
  --format=php      PHP serialize() output of the same structure.
  --smart-only      Only include devices that are typically SMART-capable
                    (SATA, SAS, NVME); USB devices are excluded.
  --no-color        Disable ANSI colors in human output.
  -h, --help        Show this help message.

TEXT;

    echo $help;
}

exit(main($argv));
