#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * storageTestSmart.php
 *
 * Run SMART self-tests on all SMART-capable devices (as reported by
 * inventoryStorage.php) and show power-on hours and the latest recorded
 * self-test entry per device.
 *
 * This script is read-only with respect to user data; it only starts
 * SMART self-tests and reads their status.
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

function storageTestSmartMain(array $argv): int
{
    [$testType, $resultsOnly, $colorEnabled] = parseSmartArguments($argv);

    $devices = loadSmartCapableDevices();
    if ($devices === null) {
        \mcxForge\Logger::logStderr("Error: failed to obtain device list from inventoryStorage.php\n");
        return EXIT_ERROR;
    }

    if (count($devices) === 0) {
        \mcxForge\Logger::logStderr("No SMART-capable devices found.\n");
        return EXIT_OK;
    }

    if (!$resultsOnly) {
        startSmartTests($devices, $testType);
    }

    $results = collectSmartResults($devices);
    renderSmartResultsHuman($testType, $results, $resultsOnly, $colorEnabled);

    return EXIT_OK;
}

/**
 * @return array{0:string,1:bool,2:bool}
 */
function parseSmartArguments(array $argv): array
{
    $testType = 'short';
    $resultsOnly = false;
    $colorEnabled = true;

    if (getenv('NO_COLOR') !== false) {
        $colorEnabled = false;
    }

    $args = $argv;
    array_shift($args);

    foreach ($args as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            printSmartHelp();
            exit(EXIT_OK);
        }

        if (str_starts_with($arg, '--test=')) {
            $value = substr($arg, strlen('--test='));
            if (!in_array($value, ['short', 'long'], true)) {
                \mcxForge\Logger::logStderr("Error: unsupported test type '$value'. Use short or long.\n");
                exit(EXIT_ERROR);
            }
            $testType = $value;
            continue;
        }

        if ($arg === '--results-only') {
            $resultsOnly = true;
            continue;
        }

        if ($arg === '--no-color') {
            $colorEnabled = false;
            continue;
        }

        \mcxForge\Logger::logStderr("Error: unrecognized argument '$arg'. Use --help for usage.\n");
        exit(EXIT_ERROR);
    }

    return [$testType, $resultsOnly, $colorEnabled];
}

/**
 * @return array<int,array<string,mixed>>|null
 */
function loadSmartCapableDevices(): ?array
{
    $scriptPath = __DIR__ . '/inventoryStorage.php';

    if (!is_file($scriptPath) || !is_readable($scriptPath)) {
        return null;
    }

    $cmd = escapeshellcmd('php') . ' ' .
        escapeshellarg($scriptPath) .
        ' --format=json --smart-only';

    $output = [];
    $exitCode = 0;
    exec($cmd, $output, $exitCode);

    if ($exitCode !== 0) {
        return null;
    }

    $json = implode("\n", $output);
    $decoded = json_decode($json, true);

    if (!is_array($decoded)) {
        return null;
    }

    $devices = [];
    foreach ($decoded as $group) {
        if (!is_array($group)) {
            continue;
        }
        foreach ($group as $device) {
            if (!is_array($device) || !isset($device['path'])) {
                continue;
            }
            $devices[] = $device;
        }
    }

    return $devices;
}

/**
 * @param array<int,array<string,mixed>> $devices
 */
function startSmartTests(array $devices, string $testType): void
{
    foreach ($devices as $device) {
        $path = (string)$device['path'];
        if ($path === '') {
            continue;
        }

        $cmd = sprintf(
            'smartctl -t %s %s 2>&1',
            escapeshellarg($testType),
            escapeshellarg($path)
        );

        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            $message = trim(implode(' ', $output));
            $logLine = sprintf(
                "Warning: smartctl test start failed for %s (%s)\n",
                $path,
                $message
            );
            \mcxForge\Logger::logStderr($logLine);
        }
    }
}

/**
 * @param array<int,array<string,mixed>> $devices
 * @return array<int,array<string,mixed>>
 */
function collectSmartResults(array $devices): array
{
    $results = [];

    foreach ($devices as $device) {
        $path = (string)$device['path'];
        if ($path === '') {
            continue;
        }

        $info = runSmartInfo($path);

        $results[] = [
            'path' => $path,
            'bus' => $device['bus'] ?? null,
            'model' => $device['model'] ?? null,
            'powerOnHours' => $info['powerOnHours'],
            'lastSelfTestLine' => $info['lastSelfTestLine'],
        ];
    }

    return $results;
}

/**
 * @return array{powerOnHours: int|null, lastSelfTestLine: string|null}
 */
function runSmartInfo(string $path): array
{
    $cmd = sprintf(
        'smartctl -a %s 2>&1',
        escapeshellarg($path)
    );

    $output = [];
    $exitCode = 0;
    exec($cmd, $output, $exitCode);

    if ($exitCode !== 0) {
        $logLine = sprintf("Warning: smartctl info failed for %s\n", $path);
        \mcxForge\Logger::logStderr($logLine);
        return [
            'powerOnHours' => null,
            'lastSelfTestLine' => null,
        ];
    }

    return parseSmartOutput($output);
}

/**
 * @param array<int,string> $lines
 * @return array{powerOnHours:int|null,lastSelfTestLine:string|null}
 */
function parseSmartOutput(array $lines): array
{
    $powerOnHours = null;
    $lastSelfTestLine = null;

    $inSelfTestSection = false;

    foreach ($lines as $line) {
        $trimmed = trim($line);

        // Power-on hours: handle both ATA and NVMe styles.
        if ($powerOnHours === null) {
            if (preg_match('/\bPower_On_Hours\b/', $trimmed)) {
                $parts = preg_split('/\s+/', $trimmed);
                if (is_array($parts) && count($parts) > 0) {
                    $raw = $parts[count($parts) - 1];
                    if (is_numeric($raw)) {
                        $powerOnHours = (int)$raw;
                    }
                }
            } elseif (preg_match('/Power On Hours:/i', $trimmed)) {
                if (preg_match('/(\d+)/', $trimmed, $matches)) {
                    $powerOnHours = (int)$matches[1];
                }
            }
        }

        // Self-test section detection and first log line.
        if (stripos($trimmed, 'Self-test log structure revision number') !== false) {
            $inSelfTestSection = true;
            continue;
        }

        if ($inSelfTestSection && str_starts_with($trimmed, '#')) {
            $lastSelfTestLine = $trimmed;
            // Use the first entry (most recent) only.
            $inSelfTestSection = false;
        }
    }

    return [
        'powerOnHours' => $powerOnHours,
        'lastSelfTestLine' => $lastSelfTestLine,
    ];
}

/**
 * @param array<int,array<string,mixed>> $results
 */
function renderSmartResultsHuman(string $testType, array $results, bool $resultsOnly, bool $colorEnabled): void
{
    $titleColor = $colorEnabled ? "\033[1;34m" : '';
    $deviceColor = $colorEnabled ? "\033[0;36m" : '';
    $valueColor = $colorEnabled ? "\033[1;32m" : '';
    $resetColor = $colorEnabled ? "\033[0m" : '';

    if (!$resultsOnly) {
        fwrite(
            STDOUT,
            sprintf(
                "%s[storageTestSmart]%s SMART %s test commands issued to %d device(s).\n",
                $titleColor,
                $resetColor,
                $testType,
                count($results)
            )
        );
        fwrite(
            STDOUT,
            sprintf(
                "%s[storageTestSmart]%s Note: self-tests may still be running; results show the latest recorded entry.\n\n",
                $titleColor,
                $resetColor
            )
        );
    }

    foreach ($results as $result) {
        $path = (string)($result['path'] ?? '');
        $bus = $result['bus'] ?? '';
        $model = $result['model'] ?? '';
        $hours = $result['powerOnHours'];
        $selfTest = $result['lastSelfTestLine'] ?? null;

        $label = $path;
        if ($bus || $model) {
            $parts = [];
            if ($bus) {
                $parts[] = $bus;
            }
            if ($model) {
                $parts[] = $model;
            }
            $label .= ' (' . implode(', ', $parts) . ')';
        }

        fwrite(
            STDOUT,
            sprintf(
                "%s[storageTestSmart]%s %s%s%s\n",
                $titleColor,
                $resetColor,
                $deviceColor,
                $label,
                $resetColor
            )
        );

        $hoursText = $hours === null ? 'N/A' : (string)$hours;
        fwrite(
            STDOUT,
            sprintf(
                "  Power_On_Hours: %s%s%s\n",
                $valueColor,
                $hoursText,
                $resetColor
            )
        );

        $selfText = $selfTest === null ? 'N/A' : (string)$selfTest;
        fwrite(
            STDOUT,
            sprintf(
                "  Last self-test: %s%s%s\n\n",
                $valueColor,
                $selfText,
                $resetColor
            )
        );
    }
}

function printSmartHelp(): void
{
    $help = <<<TEXT
Usage: storageTestSmart.php [--test=short|long] [--results-only] [--no-color]

Run SMART self-tests on all SMART-capable devices and show power-on hours and
the latest recorded self-test entry per device.

Options:
  --test=short      Issue smartctl -t short (default).
  --test=long       Issue smartctl -t long.
  --results-only    Do not start new tests; only display current SMART info
                    and the latest self-test log entries.
  --no-color        Disable ANSI colors in human output.
  -h, --help        Show this help message.

Notes:
  - Tests are started sequentially but run in parallel on the drives.
  - Results reflect the latest recorded self-test, which may be from a
    previous run if the new test has not completed yet.

TEXT;

    echo $help;
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(storageTestSmartMain($argv));
}
