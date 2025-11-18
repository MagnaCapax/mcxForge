#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * benchmarkStorageHdparm.php
 *
 * Run hdparm -Tt (cached + buffered read tests) for block devices and
 * report per-device buffered read MB/s. By default all detected disks
 * are tested in read-only mode and a single SCORE value is emitted
 * based on the best device.
 *
 * This wrapper only reads from devices; it does not modify user data.
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
require_once __DIR__ . '/../lib/php/Logger.php';

\mcxForge\Logger::initStreamLogging();

function benchmarkStorageHdparmMain(array $argv): int
{
    [$deviceFilter, $runs, $scoreOnly, $colorEnabled] = benchmarkStorageHdparmParseArguments($argv);

    if (!benchmarkStorageHdparmHasHdparm()) {
        \mcxForge\Logger::logStderr("Error: hdparm not found in PATH. Install hdparm to use this benchmark.\n");
        return EXIT_ERROR;
    }

    $devices = benchmarkStorageHdparmDiscoverDevices($deviceFilter);
    if (count($devices) === 0) {
        \mcxForge\Logger::logStderr("Error: no suitable block devices found for hdparm benchmark.\n");
        return EXIT_ERROR;
    }

    $logFile = benchmarkStorageHdparmBuildLogFilePath();

    $titleColor = $colorEnabled ? "\033[1;34m" : '';
    $scoreColor = $colorEnabled ? "\033[1;32m" : '';
    $errorColor = $colorEnabled ? "\033[1;31m" : '';
    $resetColor = $colorEnabled ? "\033[0m" : '';

    if (!$scoreOnly) {
        fwrite(
            STDOUT,
            sprintf(
                "%s[benchmarkStorageHdparm]%s Running hdparm -Tt (%d run(s)) on %d device(s)...%s\n",
                $titleColor,
                $resetColor,
                $runs,
                count($devices),
                $resetColor
            )
        );
    }

    $bestScore = null;

    foreach ($devices as $device) {
        $path = $device['path'];
        $label = sprintf('%s (%s %sGiB)', $path, $device['model'], $device['sizeGiB']);

        $cmd = benchmarkStorageHdparmBuildCommand($path, $runs);
        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);

        $text = implode(PHP_EOL, $output) . PHP_EOL;
        file_put_contents($logFile, $text, FILE_APPEND);

        $mbps = benchmarkStorageHdparmParseBufferedReadMbps($output);

        if ($mbps !== null && $exitCode === 0) {
            if ($bestScore === null || $mbps > $bestScore) {
                $bestScore = $mbps;
            }
        }

        if (!$scoreOnly) {
            if ($exitCode !== 0 || $mbps === null) {
                fwrite(
                    STDERR,
                    sprintf(
                        "%s[benchmarkStorageHdparm] %s: failed (exit=%d, buffered_read_mb_s=%s)%s\n",
                        $errorColor,
                        $label,
                        $exitCode,
                        $mbps === null ? 'null' : (string)$mbps,
                        $resetColor
                    )
                );
            } else {
                fwrite(
                    STDOUT,
                    sprintf(
                        "%s[benchmarkStorageHdparm]%s %s: %s%.1f%s MB/s buffered reads\n",
                        $titleColor,
                        $resetColor,
                        $label,
                        $scoreColor,
                        $mbps,
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
                "%s[benchmarkStorageHdparm] Warning: could not parse buffered read MB/s for any device (see %s)%s\n",
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
                "%s[benchmarkStorageHdparm]%s Log file: %s\n",
                $titleColor,
                $resetColor,
                $logFile
            )
        );
        fwrite(
            STDOUT,
            sprintf(
                "%s[benchmarkStorageHdparm]%s Best device buffered reads: %s%.1f%s MB/s\n",
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
function benchmarkStorageHdparmParseArguments(array $argv): array
{
    $device = null;
    $runs = 1;
    $scoreOnly = false;
    $colorEnabled = true;

    if (getenv('NO_COLOR') !== false) {
        $colorEnabled = false;
    }

    $args = $argv;
    array_shift($args);

    foreach ($args as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            benchmarkStorageHdparmPrintHelp();
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
                \mcxForge\Logger::logStderr("Error: --device must not be empty\n");
                exit(EXIT_ERROR);
            }
            $device = $value;
            continue;
        }

        if (str_starts_with($arg, '--runs=')) {
            $value = substr($arg, strlen('--runs='));
            $value = trim($value);
            if (!ctype_digit($value) || (int)$value <= 0) {
                \mcxForge\Logger::logStderr("Error: invalid --runs value '{$value}'\n");
                exit(EXIT_ERROR);
            }
            $runs = (int)$value;
            continue;
        }

        \mcxForge\Logger::logStderr("Error: unrecognized argument '{$arg}'. Use --help for usage.\n");
        exit(EXIT_ERROR);
    }

    return [$device, $runs, $scoreOnly, $colorEnabled];
}

function benchmarkStorageHdparmPrintHelp(): void
{
    $help = <<<TEXT
Usage: benchmarkStorageHdparm.php [--device=/dev/NAME] [--runs=N] [--score-only] [--no-color]

Run hdparm cached/buffered read tests (-Tt) on block devices and report
buffered read throughput in MB/s.

By default all detected disks are tested, and the best device buffered
read MB/s is emitted as a SCORE line:

  {{SCORE:<best_buffered_read_mb_s>}}

Options:
  --device=/dev/NAME  Restrict benchmark to a single block device path.
  --runs=N            Number of hdparm passes (-Tt) per device (default: 1).
  --score-only        Print only the SCORE line, nothing else.
  --no-color          Disable ANSI colors in human output.
  -h, --help          Show this help message.

Notes:
  - This wrapper only reads from devices using hdparm; it does not modify
    user data or partition tables.
  - hdparm must be installed in PATH.

TEXT;

    echo $help;
}

function benchmarkStorageHdparmHasHdparm(): bool
{
    $result = shell_exec('command -v hdparm 2>/dev/null');
    return is_string($result) && trim($result) !== '';
}

/**
 * @return array<int,array<string,mixed>>
 */
function benchmarkStorageHdparmDiscoverDevices(?string $deviceFilter): array
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

    $mdDevices = benchmarkStorageHdparmDiscoverMdRaidDevices();
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
function benchmarkStorageHdparmDiscoverMdRaidDevices(): array
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

function benchmarkStorageHdparmBuildLogFilePath(?\DateTimeImmutable $now = null): string
{
    $now = $now ?? new \DateTimeImmutable('now');
    $date = $now->format('Ymd');

    return sprintf('/tmp/benchmarkStorageHdparm-%s.log', $date);
}

function benchmarkStorageHdparmBuildCommand(string $devicePath, int $runs): string
{
    $runs = max(1, $runs);

    // hdparm does multiple tests internally; -Tt is standard for cached + buffered.
    return sprintf(
        'hdparm -Tt %s 2>&1',
        escapeshellarg($devicePath)
    );
}

/**
 * @param array<int,string> $lines
 */
function benchmarkStorageHdparmParseBufferedReadMbps(array $lines): ?float
{
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            continue;
        }
        if (stripos($trimmed, 'buffered disk reads') === false) {
            continue;
        }

        // Example: " Timing buffered disk reads: 1234 MB in  3.00 seconds = 411.5 MB/sec"
        if (preg_match('/=\s*([0-9]+(?:\.[0-9]+)?)\s*MB\/sec/i', $trimmed, $matches) === 1) {
            return (float)$matches[1];
        }
    }

    return null;
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(benchmarkStorageHdparmMain($argv));
}
