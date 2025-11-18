#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * benchmarkStorageIOPing.php
 *
 * Run a non-destructive latency/IOPS check against block devices using
 * ioping. By default all detected disks are tested in read-only mode
 * and a single SCORE value is emitted based on the best device.
 *
 * This wrapper never uses destructive ioping flags and only issues
 * read-style probes against block devices.
 *
 * @author Aleksi Ursin
 */

if (!defined('EXIT_OK')) {
    define('EXIT_OK', 0);
}
if (!defined('EXIT_ERROR')) {
    define('EXIT_ERROR', 1);
}

require_once __DIR__ . '/inventoryStorage.php';

/**
 * @return int
 */
function benchmarkStorageIOPingMain(array $argv): int
{
    [$deviceFilter, $count, $scoreOnly, $colorEnabled] = benchmarkStorageIOPingParseArguments($argv);

    if (!benchmarkStorageIOPingHasIOPing()) {
        fwrite(STDERR, "Error: ioping not found in PATH. Install ioping to use this benchmark.\n");
        return EXIT_ERROR;
    }

    $devices = benchmarkStorageIOPingDiscoverDevices($deviceFilter);
    if (count($devices) === 0) {
        fwrite(STDERR, "Error: no suitable block devices found for ioping benchmark.\n");
        return EXIT_ERROR;
    }

    $logFile = benchmarkStorageIOPingBuildLogFilePath();

    $titleColor = $colorEnabled ? "\033[1;34m" : '';
    $scoreColor = $colorEnabled ? "\033[1;32m" : '';
    $errorColor = $colorEnabled ? "\033[1;31m" : '';
    $resetColor = $colorEnabled ? "\033[0m" : '';

    if (!$scoreOnly) {
        fwrite(
            STDOUT,
            sprintf(
                "%s[benchmarkStorageIOPing]%s Running ioping (%d requests) on %d device(s)...%s\n",
                $titleColor,
                $resetColor,
                $count,
                count($devices),
                $resetColor
            )
        );
    }

    $results = [];
    $bestScore = null;

    foreach ($devices as $device) {
        $path = $device['path'];
        $label = sprintf('%s (%s %sGiB)', $path, $device['model'], $device['sizeGiB']);

        $cmd = benchmarkStorageIOPingBuildCommand($path, $count);
        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);

        $text = implode(PHP_EOL, $output) . PHP_EOL;
        file_put_contents($logFile, $text, FILE_APPEND);

        $iops = benchmarkStorageIOPingParseIOPS($output);

        $results[] = [
            'device' => $device,
            'exitCode' => $exitCode,
            'iops' => $iops,
        ];

        if ($iops !== null && $exitCode === 0) {
            if ($bestScore === null || $iops > $bestScore) {
                $bestScore = $iops;
            }
        }

        if (!$scoreOnly) {
            if ($exitCode !== 0 || $iops === null) {
                fwrite(
                    STDERR,
                    sprintf(
                        "%s[benchmarkStorageIOPing] %s: failed (exit=%d, parsed_iops=%s)%s\n",
                        $errorColor,
                        $label,
                        $exitCode,
                        $iops === null ? 'null' : (string)$iops,
                        $resetColor
                    )
                );
            } else {
                fwrite(
                    STDOUT,
                    sprintf(
                        "%s[benchmarkStorageIOPing]%s %s: %s%.1f%s IOPS\n",
                        $titleColor,
                        $resetColor,
                        $label,
                        $scoreColor,
                        $iops,
                        $resetColor
                    )
                );
            }
        }
    }

    if ($bestScore === null) {
        fwrite(
            STDERR,
            sprintf(
                "%s[benchmarkStorageIOPing] Warning: could not parse IOPS for any device (see %s)%s\n",
                $errorColor,
                $logFile,
                $resetColor
            )
        );
        return EXIT_ERROR;
    }

    if (!$scoreOnly) {
        fwrite(
            STDOUT,
            sprintf(
                "%s[benchmarkStorageIOPing]%s Log file: %s\n",
                $titleColor,
                $resetColor,
                $logFile
            )
        );
        fwrite(
            STDOUT,
            sprintf(
                "%s[benchmarkStorageIOPing]%s Best device IOPS: %s%.1f%s\n",
                $titleColor,
                $resetColor,
                $scoreColor,
                $bestScore,
                $resetColor
            )
        );
    }

    fwrite(STDOUT, sprintf("{{SCORE:%.1f}}\n", $bestScore));

    return EXIT_OK;
}

/**
 * @return array{0:?string,1:int,2:bool,3:bool}
 */
function benchmarkStorageIOPingParseArguments(array $argv): array
{
    $device = null;
    $count = 10;
    $scoreOnly = false;
    $colorEnabled = true;

    if (getenv('NO_COLOR') !== false) {
        $colorEnabled = false;
    }

    $args = $argv;
    array_shift($args);

    foreach ($args as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            benchmarkStorageIOPingPrintHelp();
            exit(EXIT_OK);
        }

        if ($arg === '--score-only') {
            $scoreOnly = true;
            continue;
        }

        if ($arg === '--no-color') {
            $colorEnabled = false;
            continue;
        }

        if (str_starts_with($arg, '--device=')) {
            $value = substr($arg, strlen('--device='));
            $value = trim($value);
            if ($value === '') {
                fwrite(STDERR, "Error: --device must not be empty\n");
                exit(EXIT_ERROR);
            }
            $device = $value;
            continue;
        }

        if (str_starts_with($arg, '--count=')) {
            $value = substr($arg, strlen('--count='));
            $value = trim($value);
            if (!ctype_digit($value) || (int)$value <= 0) {
                fwrite(STDERR, "Error: invalid --count value '{$value}'\n");
                exit(EXIT_ERROR);
            }
            $count = (int)$value;
            continue;
        }

        fwrite(STDERR, "Error: unrecognized argument '{$arg}'. Use --help for usage.\n");
        exit(EXIT_ERROR);
    }

    return [$device, $count, $scoreOnly, $colorEnabled];
}

function benchmarkStorageIOPingPrintHelp(): void
{
    $help = <<<TEXT
Usage: benchmarkStorageIOPing.php [--device=/dev/NAME] [--count=N] [--score-only] [--no-color]

Run a non-destructive latency/IOPS check for block devices using ioping.

By default all detected disks are tested with a small number of read-only
requests, and the best device IOPS is emitted as a SCORE line:

  {{SCORE:<best_device_iops>}}

Options:
  --device=/dev/NAME  Restrict benchmark to a single block device path.
  --count=N           Number of ioping requests per device (default: 10).
  --score-only        Print only the SCORE line, nothing else.
  --no-color          Disable ANSI colors in human output.
  -h, --help          Show this help message.

Notes:
  - This wrapper only uses ioping in read/latency mode, it does not attempt
    to perform destructive tests on block devices.
  - ioping must be installed in PATH.

TEXT;

    echo $help;
}

function benchmarkStorageIOPingHasIOPing(): bool
{
    $result = shell_exec('command -v ioping 2>/dev/null');
    return is_string($result) && trim($result) !== '';
}

/**
 * @return array<int,array<string,mixed>>
 */
function benchmarkStorageIOPingDiscoverDevices(?string $deviceFilter): array
{
    $blockDevices = getBlockDevices();
    if ($blockDevices === null) {
        return [];
    }

    $groups = groupDevicesByBus($blockDevices, false);

    $devices = [];
    foreach ($groups as $busGroup) {
        foreach ($busGroup as $device) {
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

    $mdDevices = benchmarkStorageIOPingDiscoverMdRaidDevices();
    foreach ($mdDevices as $md) {
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
function benchmarkStorageIOPingDiscoverMdRaidDevices(): array
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

function benchmarkStorageIOPingBuildLogFilePath(?\DateTimeImmutable $now = null): string
{
    $now = $now ?? new \DateTimeImmutable('now');
    $date = $now->format('Ymd');

    return sprintf('/tmp/benchmarkStorageIOPing-%s.log', $date);
}

function benchmarkStorageIOPingBuildCommand(string $devicePath, int $count): string
{
    $count = max(1, $count);

    return sprintf(
        'ioping -c %d -q %s 2>&1',
        $count,
        escapeshellarg($devicePath)
    );
}

/**
 * @param array<int,string> $lines
 */
function benchmarkStorageIOPingParseIOPS(array $lines): ?float
{
    foreach (array_reverse($lines) as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            continue;
        }
        if (stripos($trimmed, 'requests completed') === false || stripos($trimmed, 'iops') === false) {
            continue;
        }

        if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*(?:k\s*)?iops/i', $trimmed, $matches) === 1) {
            $value = (float)$matches[1];
            if (stripos($trimmed, 'k iops') !== false) {
                $value *= 1000.0;
            }
            return $value;
        }
    }

    return null;
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(benchmarkStorageIOPingMain($argv));
}
