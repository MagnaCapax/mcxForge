#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * benchmarkStorageSequentialRead.php
 *
 * Run a non-destructive sequential read benchmark using dd for block
 * devices. For each device a region around the logical midpoint is
 * read and throughput is measured in MiB/s.
 *
 * By default all detected disks are tested, using direct I/O (no cache)
 * and a moderate amount of data per device to approximate a ~60 second
 * run on typical hardware. The best device throughput is emitted as a
 * SCORE line.
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

function benchmarkStorageSequentialReadMain(array $argv): int
{
    [$deviceFilter, $runtimeTarget, $bsMiB, $scoreOnly, $colorEnabled] = benchmarkStorageSequentialReadParseArguments($argv);

    $devices = benchmarkStorageSequentialReadDiscoverDevices($deviceFilter);
    if (count($devices) === 0) {
        fwrite(STDERR, "Error: no suitable block devices found for sequential read benchmark.\n");
        return EXIT_ERROR;
    }

    $logFile = benchmarkStorageSequentialReadBuildLogFilePath();

    $titleColor = $colorEnabled ? "\033[1;34m" : '';
    $scoreColor = $colorEnabled ? "\033[1;32m" : '';
    $errorColor = $colorEnabled ? "\033[1;31m" : '';
    $resetColor = $colorEnabled ? "\033[0m" : '';

    if (!$scoreOnly) {
        fwrite(
            STDOUT,
            sprintf(
                "%s[benchmarkStorageSequentialRead]%s Running dd sequential read (~%ds target) on %d device(s)...%s\n",
                $titleColor,
                $resetColor,
                $runtimeTarget,
                count($devices),
                $resetColor
            )
        );
    }

    $bestScore = null;

    foreach ($devices as $device) {
        $path = $device['path'];
        $sizeBytes = (int)$device['sizeBytes'];
        $label = sprintf('%s (%s %sGiB)', $path, $device['model'], $device['sizeGiB']);

        $plan = benchmarkStorageSequentialReadPlanRegion($sizeBytes, $runtimeTarget, $bsMiB);
        if ($plan === null) {
            if (!$scoreOnly) {
                fwrite(
                    STDERR,
                    sprintf(
                        "%s[benchmarkStorageSequentialRead] %s: skipped (device too small for planned read)%s\n",
                        $errorColor,
                        $label,
                        $resetColor
                    )
                );
            }
            continue;
        }

        [$bsBytes, $skipBlocks, $countBlocks] = $plan;

        $cmd = benchmarkStorageSequentialReadBuildCommand($path, $bsBytes, $skipBlocks, $countBlocks);
        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);

        $text = implode(PHP_EOL, $output) . PHP_EOL;
        file_put_contents($logFile, $text, FILE_APPEND);

        $mbps = benchmarkStorageSequentialReadParseMbps($output);

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
                        "%s[benchmarkStorageSequentialRead] %s: failed (exit=%d, seq_read_mib_s=%s)%s\n",
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
                        "%s[benchmarkStorageSequentialRead]%s %s: %s%.1f%s MiB/s sequential reads (bs=%dMiB)%s\n",
                        $titleColor,
                        $resetColor,
                        $label,
                        $scoreColor,
                        $mbps,
                        $resetColor,
                        $bsMiB,
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
                "%s[benchmarkStorageSequentialRead] Warning: could not parse sequential read MiB/s for any device (see %s)%s\n",
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
                "%s[benchmarkStorageSequentialRead]%s Log file: %s\n",
                $titleColor,
                $resetColor,
                $logFile
            )
        );
        fwrite(
            STDOUT,
            sprintf(
                "%s[benchmarkStorageSequentialRead]%s Best device sequential reads: %s%.1f%s MiB/s\n",
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
 * @return array{0:?string,1:int,2:int,3:bool,4:bool}
 */
function benchmarkStorageSequentialReadParseArguments(array $argv): array
{
    $device = null;
    $runtimeTarget = 60;
    $bsMiB = 1;
    $scoreOnly = false;
    $colorEnabled = true;

    if (getenv('NO_COLOR') !== false) {
        $colorEnabled = false;
    }

    $args = $argv;
    array_shift($args);

    foreach ($args as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            benchmarkStorageSequentialReadPrintHelp();
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

        if (str_starts_with($arg, '--runtime=')) {
            $value = substr($arg, strlen('--runtime='));
            $value = trim($value);
            if (!ctype_digit($value) || (int)$value <= 0) {
                fwrite(STDERR, "Error: invalid --runtime value '{$value}'\n");
                exit(EXIT_ERROR);
            }
            $runtimeTarget = (int)$value;
            continue;
        }

        if (str_starts_with($arg, '--bs-mib=')) {
            $value = substr($arg, strlen('--bs-mib='));
            $value = trim($value);
            if (!ctype_digit($value) || (int)$value <= 0) {
                fwrite(STDERR, "Error: invalid --bs-mib value '{$value}'\n");
                exit(EXIT_ERROR);
            }
            $bsMiB = (int)$value;
            continue;
        }

        fwrite(STDERR, "Error: unrecognized argument '{$arg}'. Use --help for usage.\n");
        exit(EXIT_ERROR);
    }

    return [$device, $runtimeTarget, $bsMiB, $scoreOnly, $colorEnabled];
}

function benchmarkStorageSequentialReadPrintHelp(): void
{
    $help = <<<TEXT
Usage: benchmarkStorageSequentialRead.php [--device=/dev/NAME] [--runtime=SECONDS] [--bs-mib=N] [--score-only] [--no-color]

Run a non-destructive sequential read benchmark using dd against block
devices. Each device is read around its logical midpoint to capture a
more representative bandwidth than reading only the beginning.

By default all detected disks are tested, and the best sequential read
throughput is emitted as a SCORE line:

  {{SCORE:<best_seq_read_mib_s>}}

Options:
  --device=/dev/NAME  Restrict benchmark to a single block device path.
  --runtime=SECONDS   Approximate target runtime per device (default: 60).
  --bs-mib=N          Block size to use for dd in MiB (default: 1).
  --score-only        Print only the SCORE line, nothing else.
  --no-color          Disable ANSI colors in human output.
  -h, --help          Show this help message.

Notes:
  - This wrapper reads from block devices with dd and discards data
    to /dev/null; it does not modify partition tables or user data.
  - The amount of data read per device is bounded to a fraction of the
    device size and a fixed ceiling to keep runtimes manageable.

TEXT;

    echo $help;
}

/**
 * @return array<int,array<string,mixed>>
 */
function benchmarkStorageSequentialReadDiscoverDevices(?string $deviceFilter): array
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

    return $devices;
}

function benchmarkStorageSequentialReadBuildLogFilePath(?\DateTimeImmutable $now = null): string
{
    $now = $now ?? new \DateTimeImmutable('now');
    $date = $now->format('Ymd');

    return sprintf('/tmp/benchmarkStorageSequentialRead-%s.log', $date);
}

/**
 * Plan a region to read near the midpoint of the device.
 *
 * @return array{0:int,1:int,2:int}|null [bsBytes, skipBlocks, countBlocks]
 */
function benchmarkStorageSequentialReadPlanRegion(int $sizeBytes, int $runtimeTarget, int $bsMiB): ?array
{
    if ($sizeBytes <= 0) {
        return null;
    }

    $bsBytes = max(1, $bsMiB) * 1024 * 1024;

    // Target read size: min(5% of device, 10 GiB).
    $fivePercent = (int) max(1, (int)($sizeBytes * 0.05));
    $tenGiB = 10 * 1024 * 1024 * 1024;
    $targetBytes = min($fivePercent, $tenGiB);

    // Ensure targetBytes is at least one block and not more than half the device.
    $targetBytes = max($bsBytes, $targetBytes);
    $targetBytes = min($targetBytes, (int)floor($sizeBytes / 2));

    if ($targetBytes < $bsBytes) {
        return null;
    }

    $center = (int)floor($sizeBytes / 2);
    $start = $center - (int)floor($targetBytes / 2);
    if ($start < 0) {
        $start = 0;
    }

    // Align start to block size.
    $start = (int)floor($start / $bsBytes) * $bsBytes;

    if ($start + $targetBytes > $sizeBytes) {
        $targetBytes = $sizeBytes - $start;
    }

    $countBlocks = (int)floor($targetBytes / $bsBytes);
    if ($countBlocks < 1) {
        return null;
    }

    $skipBlocks = (int)floor($start / $bsBytes);

    return [$bsBytes, $skipBlocks, $countBlocks];
}

function benchmarkStorageSequentialReadBuildCommand(string $devicePath, int $bsBytes, int $skipBlocks, int $countBlocks): string
{
    return sprintf(
        'dd if=%s of=/dev/null bs=%d skip=%d count=%d iflag=direct status=none 2>&1',
        escapeshellarg($devicePath),
        $bsBytes,
        $skipBlocks,
        $countBlocks
    );
}

/**
 * @param array<int,string> $lines
 */
function benchmarkStorageSequentialReadParseMbps(array $lines): ?float
{
    foreach (array_reverse($lines) as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            continue;
        }

        // Typical dd summary: "... bytes ... copied, X s, Y MB/s"
        if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*M(?:i)?B\/s/i', $trimmed, $matches) === 1) {
            return (float)$matches[1];
        }
    }

    return null;
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(benchmarkStorageSequentialReadMain($argv));
}

