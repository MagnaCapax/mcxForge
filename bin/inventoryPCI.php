#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * inventoryPCI.php
 *
 * Collect PCI device inventory for the current host using lspci when available.
 * Reports bus address, class description, vendor/device, and a coarse category
 * (NETWORK, STORAGE, GPU, OTHER).
 *
 * Supports human‑readable output (default), JSON, and PHP serialize formats.
 * This script is read‑only and intended for use on live systems to feed
 * qualification and diagnostics flows.
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

function inventoryPCIMain(array $argv): int
{
    [$format, $colorEnabled] = inventoryPCIParseArguments($argv);

    $inventory = inventoryPCICollect();

    switch ($format) {
        case 'json':
            inventoryPCIRenderJson($inventory);
            break;
        case 'php':
            inventoryPCIRenderPhp($inventory);
            break;
        case 'human':
        default:
            inventoryPCIRenderHuman($inventory, $colorEnabled);
            break;
    }

    return EXIT_OK;
}

/**
 * @return array{0:string,1:bool}
 */
function inventoryPCIParseArguments(array $argv): array
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
            inventoryPCIPrintHelp();
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
function inventoryPCICollect(): array
{
    $hasLspci = inventoryPCIHasLspci();
    if (!$hasLspci) {
        return [
            'devices' => [],
            'summary' => [
                'total' => 0,
                'byCategory' => [],
            ],
            'lspciUsed' => false,
        ];
    }

    $output = @shell_exec('lspci -nn 2>/dev/null');
    if (!is_string($output) || trim($output) === '') {
        return [
            'devices' => [],
            'summary' => [
                'total' => 0,
                'byCategory' => [],
            ],
            'lspciUsed' => true,
        ];
    }

    $lines = preg_split('/\R/', trim($output));
    if (!is_array($lines)) {
        return [
            'devices' => [],
            'summary' => [
                'total' => 0,
                'byCategory' => [],
            ],
            'lspciUsed' => true,
        ];
    }

    $devices = [];
    $categoryCounts = [];

    foreach ($lines as $line) {
        $trimmed = trim((string) $line);
        if ($trimmed === '') {
            continue;
        }

        $device = inventoryPCIParseLspciLine($trimmed);
        if ($device === null) {
            continue;
        }

        $category = $device['category'];
        if (!isset($categoryCounts[$category])) {
            $categoryCounts[$category] = 0;
        }
        $categoryCounts[$category]++;

        $devices[] = $device;
    }

    return [
        'devices' => $devices,
        'summary' => [
            'total' => count($devices),
            'byCategory' => $categoryCounts,
        ],
        'lspciUsed' => true,
    ];
}

function inventoryPCIHasLspci(): bool
{
    $result = @shell_exec('command -v lspci 2>/dev/null');
    return is_string($result) && trim($result) !== '';
}

/**
 * @return array<string,mixed>|null
 */
function inventoryPCIParseLspciLine(string $line): ?array
{
    if (!preg_match('/^([0-9a-fA-F]{2}:[0-9a-fA-F]{2}\.[0-7])\s+([^\[]+)\s+\[([0-9a-fA-F]{4})\]:\s+(.+?)\s+\[([0-9a-fA-F]{4}):([0-9a-fA-F]{4})\](?:\s+\(rev\s+([0-9a-fA-F]{2})\))?/u', $line, $m)) {
        return null;
    }

    $slot = $m[1];
    $classDesc = trim($m[2]);
    $classCode = $m[3];
    $desc = trim($m[4]);
    $vendorId = $m[5];
    $deviceId = $m[6];
    $revision = isset($m[7]) ? $m[7] : null;

    $category = inventoryPCICategorizeClass($classDesc);

    return [
        'slot' => $slot,
        'class' => $classDesc,
        'classCode' => $classCode,
        'description' => $desc,
        'vendorId' => $vendorId,
        'deviceId' => $deviceId,
        'revision' => $revision,
        'category' => $category,
    ];
}

function inventoryPCICategorizeClass(string $classDesc): string
{
    $lower = strtolower($classDesc);

    if (strpos($lower, 'ethernet controller') !== false || strpos($lower, 'network controller') !== false) {
        return 'NETWORK';
    }

    if (
        strpos($lower, 'sata') !== false
        || strpos($lower, 'sas') !== false
        || strpos($lower, 'raid bus controller') !== false
        || strpos($lower, 'non-volatile memory controller') !== false
        || strpos($lower, 'serial attached scsi') !== false
    ) {
        return 'STORAGE';
    }

    if (
        strpos($lower, 'vga compatible controller') !== false
        || strpos($lower, '3d controller') !== false
        || strpos($lower, 'display controller') !== false
    ) {
        return 'GPU';
    }

    return 'OTHER';
}

/**
 * @param array<string,mixed> $inventory
 */
function inventoryPCIRenderHuman(array $inventory, bool $colorEnabled): void
{
    /** @var array<int,array<string,mixed>> $devices */
    $devices = $inventory['devices'] ?? [];
    /** @var array<string,int> $byCategory */
    $byCategory = $inventory['summary']['byCategory'] ?? [];

    $sectionColor = $colorEnabled ? "\033[1;34m" : '';
    $slotColor = $colorEnabled ? "\033[0;36m" : '';
    $resetColor = $colorEnabled ? "\033[0m" : '';

    echo $sectionColor . "PCI devices" . $resetColor . PHP_EOL;

    if (count($devices) === 0) {
        echo "  (no PCI devices reported or lspci unavailable)\n";
    }

    foreach ($devices as $dev) {
        $slot = $dev['slot'] ?? '';
        $category = $dev['category'] ?? 'OTHER';
        $class = $dev['class'] ?? '';
        $desc = $dev['description'] ?? '';
        $vendorId = $dev['vendorId'] ?? '';
        $deviceId = $dev['deviceId'] ?? '';
        $revision = $dev['revision'] ?? null;

        $idText = $vendorId !== '' && $deviceId !== '' ? sprintf('%s:%s', $vendorId, $deviceId) : 'unknown';
        $revText = $revision !== null ? sprintf(' rev %s', $revision) : '';

        echo sprintf(
            "  * %s%s%s; %s; %s; %s%s\n",
            $slotColor,
            $slot,
            $resetColor,
            $category,
            $class,
            $desc,
            $revText
        );
        echo sprintf("      ID: %s\n", $idText);
    }

    echo PHP_EOL;

    $total = (int) ($inventory['summary']['total'] ?? 0);
    $network = (int) ($byCategory['NETWORK'] ?? 0);
    $storage = (int) ($byCategory['STORAGE'] ?? 0);
    $gpu = (int) ($byCategory['GPU'] ?? 0);
    $other = (int) ($byCategory['OTHER'] ?? 0);

    echo sprintf(
        "Summary: total=%d; NETWORK=%d; STORAGE=%d; GPU=%d; OTHER=%d\n",
        $total,
        $network,
        $storage,
        $gpu,
        $other
    );
}

/**
 * @param array<string,mixed> $inventory
 */
function inventoryPCIRenderJson(array $inventory): void
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
function inventoryPCIRenderPhp(array $inventory): void
{
    echo serialize($inventory) . PHP_EOL;
}

function inventoryPCIPrintHelp(): void
{
    $help = <<<TEXT
Usage: inventoryPCI.php [--format=human|json|php] [--no-color]

Collect PCI device inventory for the current host using lspci.

Options:
  --format=human    Human-readable output (default).
  --format=json     JSON output with per-device details and summary counts.
  --format=php      PHP serialize() output of the same structure.
  --no-color        Disable ANSI colors in human output.
  -h, --help        Show this help message.

Notes:
  - Requires the 'lspci' command; when it is not available, an empty inventory
    is returned with lspciUsed=false.

TEXT;

    echo $help;
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(inventoryPCIMain($argv));
}

