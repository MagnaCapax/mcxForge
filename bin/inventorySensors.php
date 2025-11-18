#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * inventorySensors.php
 *
 * Collect basic hardware sensor readings for the current host. Reports
 * temperatures, fan speeds, and voltages from lm-sensors when available,
 * and computes a simple health status (OK / HIGH / CRITICAL / UNAVAILABLE).
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

function inventorySensorsMain(array $argv): int
{
    [$format, $colorEnabled] = inventorySensorsParseArguments($argv);

    $inventory = inventorySensorsCollect();

    switch ($format) {
        case 'json':
            inventorySensorsRenderJson($inventory);
            break;
        case 'php':
            inventorySensorsRenderPhp($inventory);
            break;
        case 'human':
        default:
            inventorySensorsRenderHuman($inventory, $colorEnabled);
            break;
    }

    return EXIT_OK;
}

/**
 * @return array{0:string,1:bool}
 */
function inventorySensorsParseArguments(array $argv): array
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
            inventorySensorsPrintHelp();
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
function inventorySensorsCollect(): array
{
    $hasSensors = inventorySensorsHasBinary();

    if (!$hasSensors) {
        return [
            'status' => 'UNAVAILABLE',
            'sensorsPresent' => false,
            'chips' => [],
        ];
    }

    $output = @shell_exec('sensors 2>/dev/null');
    if (!is_string($output) || trim($output) === '') {
        return [
            'status' => 'UNAVAILABLE',
            'sensorsPresent' => true,
            'chips' => [],
        ];
    }

    $chips = inventorySensorsParseOutput($output);

    $overall = 'OK';
    foreach ($chips as $chip) {
        /** @var array<int,array<string,mixed>> $entries */
        $entries = $chip['entries'] ?? [];
        foreach ($entries as $entry) {
            $status = strtoupper((string) ($entry['status'] ?? 'OK'));
            if ($status === 'CRITICAL') {
                $overall = 'CRITICAL';
                break 2;
            }
            if ($status === 'HIGH' && $overall === 'OK') {
                $overall = 'HIGH';
            }
        }
    }

    return [
        'status' => $overall,
        'sensorsPresent' => true,
        'chips' => $chips,
    ];
}

function inventorySensorsHasBinary(): bool
{
    $result = @shell_exec('command -v sensors 2>/dev/null');
    return is_string($result) && trim($result) !== '';
}

/**
 * @return array<int,array<string,mixed>>
 */
function inventorySensorsParseOutput(string $output): array
{
    $lines = preg_split('/\R/', $output);
    if (!is_array($lines)) {
        return [];
    }

    $chips = [];
    $currentChip = null;

    foreach ($lines as $line) {
        $raw = (string) $line;
        $trimmed = trim($raw);

        if ($trimmed === '') {
            $currentChip = null;
            continue;
        }

        if ($currentChip === null) {
            $currentChip = [
                'name' => $trimmed,
                'entries' => [],
            ];
            $chips[] = &$currentChip;
            continue;
        }

        if (stripos($trimmed, 'Adapter:') === 0) {
            $adapter = trim(substr($trimmed, strlen('Adapter:')));
            $currentChip['adapter'] = $adapter;
            continue;
        }

        if (strpos($trimmed, ':') === false) {
            continue;
        }

        [$label, $rest] = array_map('trim', explode(':', $trimmed, 2));
        if ($label === '') {
            continue;
        }

        $entry = inventorySensorsParseEntry($label, $rest);
        $currentChip['entries'][] = $entry;
    }

    unset($currentChip);

    return $chips;
}

/**
 * @return array<string,mixed>
 */
function inventorySensorsParseEntry(string $label, string $rest): array
{
    $value = null;
    $unit = null;
    $high = null;
    $crit = null;

    if (preg_match('/([-+0-9.]+)\s*°C/', $rest, $m) === 1) {
        $value = (float) $m[1];
        $unit = 'C';

        if (preg_match('/high\s*=\s*([-+0-9.]+)\s*°C/i', $rest, $hm) === 1) {
            $high = (float) $hm[1];
        }
        if (preg_match('/crit\s*=\s*([-+0-9.]+)\s*°C/i', $rest, $cm) === 1) {
            $crit = (float) $cm[1];
        }
    } elseif (preg_match('/([0-9.]+)\s*RPM/i', $rest, $m) === 1) {
        $value = (float) $m[1];
        $unit = 'RPM';
    } elseif (preg_match('/([-+0-9.]+)\s*V(?!A|AR)/', $rest, $m) === 1) {
        $value = (float) $m[1];
        $unit = 'V';
    }

    $status = 'OK';
    if ($unit === 'C' && $value !== null) {
        if ($crit !== null && $value >= $crit) {
            $status = 'CRITICAL';
        } elseif ($high !== null && $value >= $high) {
            $status = 'HIGH';
        }
    }

    return [
        'label' => $label,
        'raw' => $rest,
        'value' => $value,
        'unit' => $unit,
        'high' => $high,
        'critical' => $crit,
        'status' => $status,
    ];
}

/**
 * @param array<string,mixed> $inventory
 */
function inventorySensorsRenderHuman(array $inventory, bool $colorEnabled): void
{
    $sectionColor = $colorEnabled ? "\033[1;34m" : '';
    $statusOkColor = $colorEnabled ? "\033[0;32m" : '';
    $statusWarnColor = $colorEnabled ? "\033[0;33m" : '';
    $statusCritColor = $colorEnabled ? "\033[0;31m" : '';
    $resetColor = $colorEnabled ? "\033[0m" : '';

    $status = strtoupper((string) ($inventory['status'] ?? 'UNAVAILABLE'));
    $chips = $inventory['chips'] ?? [];

    echo $sectionColor . "Sensors" . $resetColor . PHP_EOL;

    $statusColor = $statusOkColor;
    if ($status === 'HIGH') {
        $statusColor = $statusWarnColor;
    } elseif ($status === 'CRITICAL') {
        $statusColor = $statusCritColor;
    }

    echo sprintf(
        "  Overall status: %s%s%s\n",
        $statusColor,
        $status,
        $resetColor
    );

    if ($status === 'UNAVAILABLE') {
        echo "  Sensors command not available or returned no data.\n";
        return;
    }

    foreach ($chips as $chip) {
        $name = $chip['name'] ?? 'unknown';
        $adapter = $chip['adapter'] ?? null;

        echo PHP_EOL;
        echo sprintf("%s%s%s\n", $sectionColor, $name, $resetColor);
        if ($adapter !== null) {
            echo sprintf("  Adapter: %s\n", $adapter);
        }

        /** @var array<int,array<string,mixed>> $entries */
        $entries = $chip['entries'] ?? [];
        foreach ($entries as $entry) {
            $label = $entry['label'] ?? '';
            $value = $entry['value'] ?? null;
            $unit = $entry['unit'] ?? null;
            $high = $entry['high'] ?? null;
            $crit = $entry['critical'] ?? null;
            $entryStatus = strtoupper((string) ($entry['status'] ?? 'OK'));

            $entryColor = '';
            if ($entryStatus === 'HIGH') {
                $entryColor = $statusWarnColor;
            } elseif ($entryStatus === 'CRITICAL') {
                $entryColor = $statusCritColor;
            } else {
                $entryColor = $statusOkColor;
            }

            $valueText = $value !== null ? sprintf('%.1f%s', $value, $unit !== null ? $unit : '') : 'N/A';

            $thresholds = [];
            if ($high !== null) {
                $thresholds[] = sprintf('high=%.1f%s', $high, $unit === 'C' ? 'C' : '');
            }
            if ($crit !== null) {
                $thresholds[] = sprintf('crit=%.1f%s', $crit, $unit === 'C' ? 'C' : '');
            }
            $thresholdText = count($thresholds) > 0 ? ' [' . implode(', ', $thresholds) . ']' : '';

            echo sprintf(
                "  * %s: %s%s%s%s\n",
                $label,
                $entryColor,
                $valueText,
                $resetColor,
                $thresholdText
            );
        }
    }
}

/**
 * @param array<string,mixed> $inventory
 */
function inventorySensorsRenderJson(array $inventory): void
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
function inventorySensorsRenderPhp(array $inventory): void
{
    echo serialize($inventory) . PHP_EOL;
}

function inventorySensorsPrintHelp(): void
{
    $help = <<<TEXT
Usage: inventorySensors.php [--format=human|json|php] [--no-color]

Collect basic hardware sensor readings (temperatures, fan speeds, voltages)
from lm-sensors when available and report an overall health status.

Options:
  --format=human    Human-readable output (default).
  --format=json     JSON output with per-chip sensor entries and status.
  --format=php      PHP serialize() output of the same structure.
  --no-color        Disable ANSI colors in human output.
  -h, --help        Show this help message.

Notes:
  - Uses the 'sensors' command when available; if lm-sensors is not installed
    or produces no output, overall status is reported as UNAVAILABLE.

TEXT;

    echo $help;
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(inventorySensorsMain($argv));
}

