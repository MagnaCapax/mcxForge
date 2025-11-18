#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * inventoryMemory.php
 *
 * Collect memory inventory for the current host. Reports:
 *  - Total system memory from /proc/meminfo.
 *  - Per‑DIMM information from dmidecode when available (slot, size,
 *    type, speed, manufacturer, part number, ECC indication).
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

function inventoryMemoryMain(array $argv): int
{
    [$format, $colorEnabled] = inventoryMemoryParseArguments($argv);

    $inventory = inventoryMemoryCollect();

    switch ($format) {
        case 'json':
            inventoryMemoryRenderJson($inventory);
            break;
        case 'php':
            inventoryMemoryRenderPhp($inventory);
            break;
        case 'human':
        default:
            inventoryMemoryRenderHuman($inventory, $colorEnabled);
            break;
    }

    return EXIT_OK;
}

/**
 * @return array{0:string,1:bool}
 */
function inventoryMemoryParseArguments(array $argv): array
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
            inventoryMemoryPrintHelp();
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
function inventoryMemoryCollect(): array
{
    $meminfo = inventoryMemoryReadMeminfo();
    $dimms = inventoryMemoryCollectDimms();

    $totalBytes = $meminfo['memTotalBytes'];
    $totalMiB = $totalBytes !== null ? (int) round($totalBytes / (1024 * 1024)) : null;

    $eccPresent = null;
    $eccSeenTrue = false;
    $eccSeenFalse = false;

    foreach ($dimms as $dimm) {
        $ecc = $dimm['ecc'] ?? null;
        if ($ecc === true) {
            $eccSeenTrue = true;
        } elseif ($ecc === false) {
            $eccSeenFalse = true;
        }
    }

    if ($eccSeenTrue && !$eccSeenFalse) {
        $eccPresent = true;
    } elseif ($eccSeenFalse && !$eccSeenTrue) {
        $eccPresent = false;
    } elseif ($eccSeenTrue && $eccSeenFalse) {
        $eccPresent = null;
    }

    return [
        'summary' => [
            'totalBytes' => $totalBytes,
            'totalMiB' => $totalMiB,
            'memFreeBytes' => $meminfo['memFreeBytes'],
            'memAvailableBytes' => $meminfo['memAvailableBytes'],
            'dimmCount' => count($dimms),
            'eccPresent' => $eccPresent,
            'dmidecodeUsed' => $meminfo['dmidecodeUsed'],
        ],
        'dimms' => $dimms,
    ];
}

/**
 * @return array{memTotalBytes: ?int, memFreeBytes: ?int, memAvailableBytes: ?int, dmidecodeUsed: bool}
 */
function inventoryMemoryReadMeminfo(): array
{
    $memTotal = null;
    $memFree = null;
    $memAvailable = null;

    if (is_readable('/proc/meminfo')) {
        $lines = @file('/proc/meminfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($lines)) {
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if (str_starts_with($trimmed, 'MemTotal:')) {
                    $memTotal = inventoryMemoryParseMeminfoValue($trimmed);
                } elseif (str_starts_with($trimmed, 'MemFree:')) {
                    $memFree = inventoryMemoryParseMeminfoValue($trimmed);
                } elseif (str_starts_with($trimmed, 'MemAvailable:')) {
                    $memAvailable = inventoryMemoryParseMeminfoValue($trimmed);
                }
            }
        }
    }

    return [
        'memTotalBytes' => $memTotal,
        'memFreeBytes' => $memFree,
        'memAvailableBytes' => $memAvailable,
        'dmidecodeUsed' => inventoryMemoryHasDmidecode(),
    ];
}

/**
 * @return ?int
 */
function inventoryMemoryParseMeminfoValue(string $line): ?int
{
    if (preg_match('/^\S+:\s+(\d+)\s+kB/', $line, $matches) !== 1) {
        return null;
    }

    $kib = (int) $matches[1];
    if ($kib <= 0) {
        return null;
    }

    return $kib * 1024;
}

function inventoryMemoryHasDmidecode(): bool
{
    $result = @shell_exec('command -v dmidecode 2>/dev/null');
    return is_string($result) && trim($result) !== '';
}

/**
 * @return array<int,array<string,mixed>>
 */
function inventoryMemoryCollectDimms(): array
{
    if (!inventoryMemoryHasDmidecode()) {
        return [];
    }

    $output = @shell_exec('dmidecode --type 17 2>/dev/null');
    if (!is_string($output) || trim($output) === '') {
        return [];
    }

    return inventoryMemoryParseDmidecode17($output);
}

/**
 * @return array<int,array<string,mixed>>
 */
function inventoryMemoryParseDmidecode17(string $output): array
{
    $blocks = preg_split('/\n\s*\n/', $output);
    if (!is_array($blocks)) {
        return [];
    }

    $dimms = [];

    foreach ($blocks as $block) {
        if (stripos($block, 'Memory Device') === false) {
            continue;
        }

        $lines = explode("\n", $block);
        $data = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || strpos($trimmed, ':') === false) {
                continue;
            }

            [$key, $value] = array_map('trim', explode(':', $trimmed, 2));
            if ($key === '') {
                continue;
            }
            $data[$key] = $value;
        }

        $sizeRaw = $data['Size'] ?? '';
        if ($sizeRaw === '' || stripos($sizeRaw, 'No Module Installed') !== false || stripos($sizeRaw, 'Not Installed') !== false) {
            continue;
        }

        $sizeMiB = null;
        if (preg_match('/(\d+)\s*MB/i', $sizeRaw, $m) === 1) {
            $sizeMiB = (int) $m[1];
        } elseif (preg_match('/(\d+)\s*GB/i', $sizeRaw, $m) === 1) {
            $sizeMiB = (int) $m[1] * 1024;
        }

        if ($sizeMiB === null || $sizeMiB <= 0) {
            continue;
        }

        $sizeBytes = $sizeMiB * 1024 * 1024;

        $dataWidth = null;
        $totalWidth = null;

        if (isset($data['Data Width']) && preg_match('/(\d+)\s*bits/i', $data['Data Width'], $m) === 1) {
            $dataWidth = (int) $m[1];
        }
        if (isset($data['Total Width']) && preg_match('/(\d+)\s*bits/i', $data['Total Width'], $m) === 1) {
            $totalWidth = (int) $m[1];
        }

        $ecc = null;
        if ($dataWidth !== null && $totalWidth !== null && $dataWidth > 0 && $totalWidth > 0) {
            $ecc = $totalWidth > $dataWidth;
        }

        $speedConfigured = null;
        if (isset($data['Configured Memory Speed']) && preg_match('/(\d+)\s*MT\/s/i', $data['Configured Memory Speed'], $m) === 1) {
            $speedConfigured = (int) $m[1];
        } elseif (isset($data['Speed']) && preg_match('/(\d+)\s*MT\/s/i', $data['Speed'], $m) === 1) {
            $speedConfigured = (int) $m[1];
        }

        $locator = $data['Locator'] ?? '';
        $bankLocator = $data['Bank Locator'] ?? '';
        $type = $data['Type'] ?? '';
        $typeDetail = $data['Type Detail'] ?? '';
        $manufacturer = $data['Manufacturer'] ?? '';
        $serial = $data['Serial Number'] ?? '';
        $part = $data['Part Number'] ?? '';

        $dimms[] = [
            'locator' => $locator !== '' ? $locator : null,
            'bankLocator' => $bankLocator !== '' ? $bankLocator : null,
            'sizeMiB' => $sizeMiB,
            'sizeBytes' => $sizeBytes,
            'type' => $type !== '' ? $type : null,
            'typeDetail' => $typeDetail !== '' ? $typeDetail : null,
            'configuredSpeedMTps' => $speedConfigured,
            'manufacturer' => $manufacturer !== '' ? $manufacturer : null,
            'serialNumber' => $serial !== '' ? $serial : null,
            'partNumber' => $part !== '' ? $part : null,
            'ecc' => $ecc,
        ];
    }

    return $dimms;
}

/**
 * @param array<string,mixed> $inventory
 */
function inventoryMemoryRenderHuman(array $inventory, bool $colorEnabled): void
{
    /** @var array<string,mixed> $summary */
    $summary = $inventory['summary'] ?? [];
    /** @var array<int,array<string,mixed>> $dimms */
    $dimms = $inventory['dimms'] ?? [];

    $sectionColor = $colorEnabled ? "\033[1;34m" : '';
    $labelColor = $colorEnabled ? "\033[0;36m" : '';
    $valueColor = $colorEnabled ? "\033[0;37m" : '';
    $resetColor = $colorEnabled ? "\033[0m" : '';

    $totalMiB = isset($summary['totalMiB']) && $summary['totalMiB'] !== null ? (int) $summary['totalMiB'] : null;
    $eccPresent = $summary['eccPresent'] ?? null;
    $eccText = 'unknown';
    if ($eccPresent === true) {
        $eccText = 'present';
    } elseif ($eccPresent === false) {
        $eccText = 'not detected';
    }

    echo $sectionColor . "Memory" . $resetColor . PHP_EOL;
    echo sprintf(
        "  %sTotal:%s %s%s%s\n",
        $labelColor,
        $resetColor,
        $valueColor,
        $totalMiB !== null ? $totalMiB . ' MiB' : 'unknown',
        $resetColor
    );
    echo sprintf(
        "  %sDIMMs:%s %s%d%s\n",
        $labelColor,
        $resetColor,
        $valueColor,
        count($dimms),
        $resetColor
    );
    echo sprintf(
        "  %sECC:%s %s%s%s\n",
        $labelColor,
        $resetColor,
        $valueColor,
        $eccText,
        $resetColor
    );

    if (count($dimms) === 0) {
        echo PHP_EOL;
        echo "No populated DIMMs reported by dmidecode.\n";
        return;
    }

    echo PHP_EOL;
    echo $sectionColor . "DIMMs" . $resetColor . PHP_EOL;

    foreach ($dimms as $dimm) {
        $slot = $dimm['locator'] ?? 'UNKNOWN';
        $sizeMiB = isset($dimm['sizeMiB']) ? (int) $dimm['sizeMiB'] : 0;
        $type = $dimm['type'] ?? 'UNKNOWN';
        $speed = $dimm['configuredSpeedMTps'] ?? null;
        $manufacturer = $dimm['manufacturer'] ?? 'UNKNOWN';
        $part = $dimm['partNumber'] ?? null;
        $ecc = $dimm['ecc'] ?? null;

        $eccStr = 'unknown';
        if ($ecc === true) {
            $eccStr = 'yes';
        } elseif ($ecc === false) {
            $eccStr = 'no';
        }

        $speedStr = $speed !== null ? $speed . ' MT/s' : 'N/A';
        $partStr = $part !== null ? $part : 'N/A';

        echo sprintf(
            "  * %s; %d MiB; %s; %s; mfg=%s; part=%s; ECC=%s\n",
            $slot,
            $sizeMiB,
            $type,
            $speedStr,
            $manufacturer,
            $partStr,
            $eccStr
        );
    }
}

/**
 * @param array<string,mixed> $inventory
 */
function inventoryMemoryRenderJson(array $inventory): void
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
function inventoryMemoryRenderPhp(array $inventory): void
{
    echo serialize($inventory) . PHP_EOL;
}

function inventoryMemoryPrintHelp(): void
{
    $help = <<<TEXT
Usage: inventoryMemory.php [--format=human|json|php] [--no-color]

Collect memory inventory (total memory and per-DIMM slots) from the current host.

Options:
  --format=human    Human-readable output (default).
  --format=json     JSON output with summary and DIMM list.
  --format=php      PHP serialize() output of the same structure.
  --no-color        Disable ANSI colors in human output.
  -h, --help        Show this help message.

Notes:
  - Total memory is read from /proc/meminfo.
  - Per-DIMM data is collected from 'dmidecode --type 17' when available; when
    dmidecode is missing or cannot be executed, only the summary fields are set.

TEXT;

    echo $help;
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(inventoryMemoryMain($argv));
}

