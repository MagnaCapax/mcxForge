#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * inventoryFirmware.php
 *
 * Collect firmware versions for key components on the current host:
 *  - Platform firmware (BIOS) via DMI.
 *  - Storage devices via smartctl -i when available.
 *  - Network interfaces via ethtool -i when available.
 *
 * Supports human‑readable output (default), JSON, and PHP serialize formats.
 * This script is read‑only and intended for use on live systems to feed
 * qualification and diagnostics flows.
 *
 * @author Aleksi Ursin
 */

require_once __DIR__ . '/../lib/php/Logger.php';
require_once __DIR__ . '/inventorySystem.php';
require_once __DIR__ . '/inventoryStorage.php';
require_once __DIR__ . '/inventoryNetwork.php';

\mcxForge\Logger::initStreamLogging();

if (!defined('EXIT_OK')) {
    define('EXIT_OK', 0);
}
if (!defined('EXIT_ERROR')) {
    define('EXIT_ERROR', 1);
}

function inventoryFirmwareMain(array $argv): int
{
    [$format, $colorEnabled] = inventoryFirmwareParseArguments($argv);

    $inventory = inventoryFirmwareCollect();

    switch ($format) {
        case 'json':
            inventoryFirmwareRenderJson($inventory);
            break;
        case 'php':
            inventoryFirmwareRenderPhp($inventory);
            break;
        case 'human':
        default:
            inventoryFirmwareRenderHuman($inventory, $colorEnabled);
            break;
    }

    return EXIT_OK;
}

/**
 * @return array{0:string,1:bool}
 */
function inventoryFirmwareParseArguments(array $argv): array
{
    $format = 'human';
    $colorEnabled = true;

    if (getenv('NO_COLOR') !== false) {
        $colorEnabled = false;
    }

    $args = $argv;
    array_shift($args);

    foreach ($args as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            inventoryFirmwarePrintHelp();
            exit(EXIT_OK);
        }

        if (str_starts_with($arg, '--format=')) {
            $value = substr($arg, strlen('--format='));
            if (!in_array($value, ['human', 'json', 'php'], true)) {
                \mcxForge\Logger::logStderr("Error: unsupported format '$value'. Use human, json, or php.\n");
                exit(EXIT_ERROR);
            }
            $format = $value;
            continue;
        }

        if ($arg === '--no-color') {
            $colorEnabled = false;
            continue;
        }

        \mcxForge\Logger::logStderr("Error: unrecognized argument '$arg'. Use --help for usage.\n");
        exit(EXIT_ERROR);
    }

    return [$format, $colorEnabled];
}

/**
 * @return array<string,mixed>
 */
function inventoryFirmwareCollect(): array
{
    $systemInfo = inventorySystemCollect();

    $platform = [
        'biosVendor' => $systemInfo['bios']['vendor'] ?? null,
        'biosVersion' => $systemInfo['bios']['version'] ?? null,
        'biosDate' => $systemInfo['bios']['date'] ?? null,
    ];

    $storageFirmware = inventoryFirmwareCollectStorage();
    $networkFirmware = inventoryFirmwareCollectNetwork();

    return [
        'platform' => $platform,
        'storage' => $storageFirmware,
        'network' => $networkFirmware,
    ];
}

/**
 * @return array<int,array<string,mixed>>
 */
function inventoryFirmwareCollectStorage(): array
{
    $blockDevices = getBlockDevices();
    if ($blockDevices === null) {
        return [];
    }

    $groups = groupDevicesByBus($blockDevices, false);

    $paths = [];
    foreach ($groups as $group) {
        foreach ($group as $device) {
            $path = $device['path'] ?? null;
            if (!is_string($path) || $path === '') {
                continue;
            }
            $model = $device['model'] ?? null;
            $paths[$path] = [
                'path' => $path,
                'model' => $model,
                'bus' => $device['bus'] ?? null,
            ];
        }
    }

    $smartctlPresent = inventoryFirmwareHasSmartctl();

    $results = [];
    foreach ($paths as $info) {
        $path = $info['path'];
        $model = $info['model'];
        $bus = $info['bus'] ?? null;

        $firmware = null;
        if ($smartctlPresent) {
            $firmware = inventoryFirmwareReadSmartctl($path);
        }

        $results[] = [
            'path' => $path,
            'model' => $model,
            'bus' => $bus,
            'firmwareVersion' => $firmware,
        ];
    }

    return $results;
}

function inventoryFirmwareHasSmartctl(): bool
{
    $result = @shell_exec('command -v smartctl 2>/dev/null');
    return is_string($result) && trim($result) !== '';
}

/**
 * @return string|null
 */
function inventoryFirmwareReadSmartctl(string $devicePath): ?string
{
    $cmd = sprintf('smartctl -i %s 2>/dev/null', escapeshellarg($devicePath));
    $output = @shell_exec($cmd);
    if (!is_string($output) || trim($output) === '') {
        return null;
    }

    $lines = preg_split('/\R/', $output);
    if (!is_array($lines)) {
        return null;
    }

    foreach ($lines as $line) {
        $trimmed = trim((string) $line);
        if (stripos($trimmed, 'Firmware Version:') === 0) {
            $value = trim(substr($trimmed, strlen('Firmware Version:')));
            return $value !== '' ? $value : null;
        }
        if (stripos($trimmed, 'firmware revision:') === 0) {
            $value = trim(substr($trimmed, strlen('firmware revision:')));
            return $value !== '' ? $value : null;
        }
    }

    return null;
}

/**
 * @return array<int,array<string,mixed>>
 */
function inventoryFirmwareCollectNetwork(): array
{
    $network = inventoryNetworkCollect();
    if ($network === null) {
        return [];
    }

    /** @var array<int,array<string,mixed>> $interfaces */
    $interfaces = $network['interfaces'] ?? [];

    $results = [];

    foreach ($interfaces as $iface) {
        $name = $iface['name'] ?? null;
        if (!is_string($name) || $name === '') {
            continue;
        }

        $driver = $iface['driver'] ?? null;
        $firmware = $iface['firmwareVersion'] ?? null;
        $busInfo = $iface['busInfo'] ?? null;

        if ($driver === null && $firmware === null && $busInfo === null) {
            continue;
        }

        $results[] = [
            'name' => $name,
            'driver' => $driver,
            'firmwareVersion' => $firmware,
            'busInfo' => $busInfo,
        ];
    }

    return $results;
}

/**
 * @param array<string,mixed> $inventory
 */
function inventoryFirmwareRenderHuman(array $inventory, bool $colorEnabled): void
{
    $sectionColor = $colorEnabled ? "\033[1;34m" : '';
    $labelColor = $colorEnabled ? "\033[0;36m" : '';
    $resetColor = $colorEnabled ? "\033[0m" : '';

    /** @var array<string,string|null> $platform */
    $platform = $inventory['platform'] ?? [];
    /** @var array<int,array<string,mixed>> $storage */
    $storage = $inventory['storage'] ?? [];
    /** @var array<int,array<string,mixed>> $network */
    $network = $inventory['network'] ?? [];

    echo $sectionColor . "Platform firmware" . $resetColor . PHP_EOL;
    echo sprintf(
        "  %sBIOS vendor:%s %s\n",
        $labelColor,
        $resetColor,
        $platform['biosVendor'] ?? 'unknown'
    );
    echo sprintf(
        "  %sBIOS version:%s %s\n",
        $labelColor,
        $resetColor,
        $platform['biosVersion'] ?? 'unknown'
    );
    echo sprintf(
        "  %sBIOS date:%s %s\n",
        $labelColor,
        $resetColor,
        $platform['biosDate'] ?? 'unknown'
    );

    echo PHP_EOL;
    echo $sectionColor . "Storage firmware" . $resetColor . PHP_EOL;
    if (count($storage) === 0) {
        echo "  (no storage devices reported or smartctl unavailable)\n";
    }
    foreach ($storage as $dev) {
        $path = $dev['path'] ?? 'unknown';
        $model = $dev['model'] ?? 'unknown';
        $bus = $dev['bus'] ?? null;
        $fw = $dev['firmwareVersion'] ?? null;

        echo sprintf(
            "  * %s; model=%s; bus=%s; firmware=%s\n",
            $path,
            $model,
            $bus !== null ? $bus : 'unknown',
            $fw !== null ? $fw : 'unknown'
        );
    }

    echo PHP_EOL;
    echo $sectionColor . "Network firmware" . $resetColor . PHP_EOL;
    if (count($network) === 0) {
        echo "  (no network firmware information available)\n";
    }
    foreach ($network as $iface) {
        $name = $iface['name'] ?? 'unknown';
        $driver = $iface['driver'] ?? 'unknown';
        $fw = $iface['firmwareVersion'] ?? 'unknown';
        $busInfo = $iface['busInfo'] ?? 'unknown';

        echo sprintf(
            "  * %s; driver=%s; firmware=%s; bus=%s\n",
            $name,
            $driver,
            $fw,
            $busInfo
        );
    }
}

/**
 * @param array<string,mixed> $inventory
 */
function inventoryFirmwareRenderJson(array $inventory): void
{
    $encoded = json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        \mcxForge\Logger::logStderr("Error: failed to encode JSON output.\n");
        exit(EXIT_ERROR);
    }

    echo $encoded . PHP_EOL;
}

/**
 * @param array<string,mixed> $inventory
 */
function inventoryFirmwareRenderPhp(array $inventory): void
{
    echo serialize($inventory) . PHP_EOL;
}

function inventoryFirmwarePrintHelp(): void
{
    $help = <<<TEXT
Usage: inventoryFirmware.php [--format=human|json|php] [--no-color]

Collect firmware versions for platform (BIOS), storage, and network devices.

Options:
  --format=human    Human-readable output (default).
  --format=json     JSON output with platform, storage, and network firmware.
  --format=php      PHP serialize() output of the same structure.
  --no-color        Disable ANSI colors in human output.
  -h, --help        Show this help message.

Notes:
  - Platform firmware is read from DMI via inventorySystem.php.
  - Storage firmware uses smartctl -i on block devices where available.
  - Network firmware uses ethtool -i for interfaces where available.

TEXT;

    echo $help;
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(inventoryFirmwareMain($argv));
}

