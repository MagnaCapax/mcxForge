#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * inventoryStorageTopology.php
 *
 * Collect a logical storage topology for the current host using lsblk.
 * Reports disks, partitions, MD RAID devices, and mountpoints in a tree
 * structure suitable for rescue and templating flows.
 *
 * Supports human‑readable output (default), JSON, and PHP serialize formats.
 * This script is read‑only and intended for use on live systems.
 *
 * @author Aleksi Ursin
 */

require_once __DIR__ . '/../lib/php/Logger.php';

\mcxForge\Logger::initStreamLogging();

if (!defined('EXIT_OK')) {
    define('EXIT_OK', 0);
}
if (!defined('EXIT_ERROR')) {
    define('EXIT_ERROR', 1);
}

function inventoryStorageTopologyMain(array $argv): int
{
    [$format, $colorEnabled] = inventoryStorageTopologyParseArguments($argv);

    $topology = inventoryStorageTopologyCollect();
    if ($topology === null) {
        \mcxForge\Logger::logStderr("Error: failed to collect storage topology (lsblk output unavailable or invalid).\n");
        return EXIT_ERROR;
    }

    switch ($format) {
        case 'json':
            inventoryStorageTopologyRenderJson($topology);
            break;
        case 'php':
            inventoryStorageTopologyRenderPhp($topology);
            break;
        case 'human':
        default:
            inventoryStorageTopologyRenderHuman($topology, $colorEnabled);
            break;
    }

    return EXIT_OK;
}

/**
 * @return array{0:string,1:bool}
 */
function inventoryStorageTopologyParseArguments(array $argv): array
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
            inventoryStorageTopologyPrintHelp();
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
 * @return array<string,mixed>|null
 */
function inventoryStorageTopologyCollect(): ?array
{
    $cmd = 'lsblk -J -b -o NAME,TYPE,SIZE,MODEL,FSTYPE,MOUNTPOINT,PKNAME 2>/dev/null';
    $output = @shell_exec($cmd);
    if (!is_string($output) || trim($output) === '') {
        return null;
    }

    $decoded = json_decode($output, true);
    if (!is_array($decoded) || !isset($decoded['blockdevices']) || !is_array($decoded['blockdevices'])) {
        return null;
    }

    /** @var array<int,array<string,mixed>> $devices */
    $devices = $decoded['blockdevices'];

    $normalized = [];
    foreach ($devices as $dev) {
        $normalized[] = inventoryStorageTopologyNormalizeNode($dev);
    }

    return [
        'blockdevices' => $normalized,
    ];
}

/**
 * @param array<string,mixed> $node
 * @return array<string,mixed>
 */
function inventoryStorageTopologyNormalizeNode(array $node): array
{
    $name = (string) ($node['name'] ?? '');
    $type = strtolower((string) ($node['type'] ?? ''));
    $sizeBytesRaw = $node['size'] ?? 0;
    $sizeBytes = is_numeric($sizeBytesRaw) ? (int) $sizeBytesRaw : 0;
    $sizeGiB = $sizeBytes > 0 ? (int) round($sizeBytes / (1024 * 1024 * 1024)) : 0;
    $modelRaw = $node['model'] ?? '';
    $model = is_string($modelRaw) && trim($modelRaw) !== '' ? trim($modelRaw) : null;
    $fstypeRaw = $node['fstype'] ?? '';
    $fstype = is_string($fstypeRaw) && trim($fstypeRaw) !== '' ? trim($fstypeRaw) : null;
    $mountpointRaw = $node['mountpoint'] ?? '';
    $mountpoint = is_string($mountpointRaw) && trim($mountpointRaw) !== '' ? trim($mountpointRaw) : null;
    $pknameRaw = $node['pkname'] ?? '';
    $pkname = is_string($pknameRaw) && trim($pknameRaw) !== '' ? trim($pknameRaw) : null;

    $raidLevel = null;
    if (strpos($type, 'raid') === 0) {
        $raidLevel = strtoupper($type);
    }

    $result = [
        'name' => $name,
        'path' => $name !== '' ? '/dev/' . $name : null,
        'type' => $type,
        'raidLevel' => $raidLevel,
        'sizeBytes' => $sizeBytes,
        'sizeGiB' => $sizeGiB,
        'model' => $model,
        'fstype' => $fstype,
        'mountpoint' => $mountpoint,
        'pkname' => $pkname,
        'children' => [],
    ];

    if (isset($node['children']) && is_array($node['children'])) {
        /** @var array<int,array<string,mixed>> $children */
        $children = $node['children'];
        foreach ($children as $child) {
            $result['children'][] = inventoryStorageTopologyNormalizeNode($child);
        }
    }

    return $result;
}

/**
 * @param array<string,mixed> $topology
 */
function inventoryStorageTopologyRenderHuman(array $topology, bool $colorEnabled): void
{
    /** @var array<int,array<string,mixed>> $devices */
    $devices = $topology['blockdevices'] ?? [];

    $sectionColor = $colorEnabled ? "\033[1;34m" : '';
    $deviceColor = $colorEnabled ? "\033[0;36m" : '';
    $resetColor = $colorEnabled ? "\033[0m" : '';

    echo $sectionColor . "Storage topology" . $resetColor . PHP_EOL;

    if (count($devices) === 0) {
        echo "  (no block devices reported)\n";
        return;
    }

    foreach ($devices as $device) {
        inventoryStorageTopologyRenderNodeHuman($device, 0, $deviceColor, $resetColor);
    }
}

/**
 * @param array<string,mixed> $node
 */
function inventoryStorageTopologyRenderNodeHuman(array $node, int $depth, string $deviceColor, string $resetColor): void
{
    $indent = str_repeat('  ', $depth);

    $path = $node['path'] ?? null;
    $name = $path !== null ? $path : ($node['name'] ?? 'UNKNOWN');
    $type = $node['type'] ?? '';
    $sizeGiB = isset($node['sizeGiB']) ? (int) $node['sizeGiB'] : 0;
    $model = $node['model'] ?? null;
    $fstype = $node['fstype'] ?? null;
    $mount = $node['mountpoint'] ?? null;
    $raidLevel = $node['raidLevel'] ?? null;

    $details = [];
    if ($raidLevel !== null) {
        $details[] = $raidLevel;
    } elseif ($type !== '') {
        $details[] = $type;
    }

    if ($sizeGiB > 0) {
        $details[] = $sizeGiB . 'GiB';
    }
    if ($model !== null) {
        $details[] = $model;
    }
    if ($fstype !== null) {
        $details[] = 'fs=' . $fstype;
    }
    if ($mount !== null) {
        $details[] = 'mount=' . $mount;
    }

    echo sprintf(
        "%s* %s%s%s; %s\n",
        $indent,
        $deviceColor,
        $name,
        $resetColor,
        implode('; ', $details)
    );

    if (isset($node['children']) && is_array($node['children'])) {
        /** @var array<int,array<string,mixed>> $children */
        $children = $node['children'];
        foreach ($children as $child) {
            inventoryStorageTopologyRenderNodeHuman($child, $depth + 1, $deviceColor, $resetColor);
        }
    }
}

/**
 * @param array<string,mixed> $topology
 */
function inventoryStorageTopologyRenderJson(array $topology): void
{
    $encoded = json_encode($topology, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        \mcxForge\Logger::logStderr("Error: failed to encode JSON output.\n");
        exit(EXIT_ERROR);
    }

    echo $encoded . PHP_EOL;
}

/**
 * @param array<string,mixed> $topology
 */
function inventoryStorageTopologyRenderPhp(array $topology): void
{
    echo serialize($topology) . PHP_EOL;
}

function inventoryStorageTopologyPrintHelp(): void
{
    $help = <<<TEXT
Usage: inventoryStorageTopology.php [--format=human|json|php] [--no-color]

Collect a logical storage topology (disks, partitions, MD RAID, mountpoints)
for the current host using lsblk.

Options:
  --format=human    Human-readable tree output (default).
  --format=json     JSON output matching the lsblk blockdevices structure.
  --format=php      PHP serialize() output of the same structure.
  --no-color        Disable ANSI colors in human output.
  -h, --help        Show this help message.

Notes:
  - This is a read-only view over lsblk -J; it does not modify disks.

TEXT;

    echo $help;
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(inventoryStorageTopologyMain($argv));
}

