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

if (!defined('EXIT_OK')) {
    define('EXIT_OK', 0);
}
if (!defined('EXIT_ERROR')) {
    define('EXIT_ERROR', 1);
}

function storageTestSmartMain(array $argv): int
{
    [$testType, $resultsOnly] = parseSmartArguments($argv);

    $devices = loadSmartCapableDevices();
    if ($devices === null) {
        fwrite(STDERR, "Error: failed to obtain device list from inventoryStorage.php\n");
        return EXIT_ERROR;
    }

    if (count($devices) === 0) {
        fwrite(STDERR, "No SMART-capable devices found.\n");
        return EXIT_OK;
    }

    if (!$resultsOnly) {
        startSmartTests($devices, $testType);
    }

    $results = collectSmartResults($devices);
    renderSmartResultsHuman($testType, $results, $resultsOnly);

    return EXIT_OK;
}

/**
 * @return array{0:string,1:bool}
 */
function parseSmartArguments(array $argv): array
{
    $testType = 'short';
    $resultsOnly = false;

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
                fwrite(STDERR, "Error: unsupported test type '$value'. Use short or long.\n");
                exit(EXIT_ERROR);
            }
            $testType = $value;
            continue;
        }

        if ($arg === '--results-only') {
            $resultsOnly = true;
            continue;
        }

        fwrite(STDERR, "Error: unrecognized argument '$arg'. Use --help for usage.\n");
        exit(EXIT_ERROR);
    }

    return [$testType, $resultsOnly];
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
            $message = implode(' ', $output);
            fwrite(STDERR, sprintf(
                "Warning: smartctl test start failed for %s (%s)\n",
                $path,
                trim($message)
            ));
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
        fwrite(STDERR, sprintf(
            "Warning: smartctl info failed for %s\n",
            $path
        ));
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
function renderSmartResultsHuman(string $testType, array $results, bool $resultsOnly): void
{
    if (!$resultsOnly) {
        echo sprintf(
            "SMART %s test commands issued to %d device(s).\n",
            $testType,
            count($results)
        );
        echo "Note: self-tests may still be running; results show the latest recorded entry.\n\n";
    }

    foreach ($results as $result) {
        $path = $result['path'] ?? '';
        $bus = $result['bus'] ?? '';
        $model = $result['model'] ?? '';
        $hours = $result['powerOnHours'];
        $selfTest = $result['lastSelfTestLine'] ?? null;

        echo $path;
        if ($bus || $model) {
            echo ' (';
            if ($bus) {
                echo $bus;
            }
            if ($bus && $model) {
                echo ', ';
            }
            if ($model) {
                echo $model;
            }
            echo ')';
        }
        echo PHP_EOL;

        echo '  Power_On_Hours: ';
        echo $hours === null ? 'N/A' : (string)$hours;
        echo PHP_EOL;

        echo '  Last self-test: ';
        echo $selfTest === null ? 'N/A' : $selfTest;
        echo PHP_EOL . PHP_EOL;
    }
}

function printSmartHelp(): void
{
    $help = <<<TEXT
Usage: storageTestSmart.php [--test=short|long] [--results-only]

Run SMART self-tests on all SMART-capable devices and show power-on hours and
the latest recorded self-test entry per device.

Options:
  --test=short      Issue smartctl -t short (default).
  --test=long       Issue smartctl -t long.
  --results-only    Do not start new tests; only display current SMART info
                    and the latest self-test log entries.
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
