#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * benchmarkStorageFioRandRead.php
 *
 * Run a non-destructive fio randread benchmark against block devices.
 *
 * Default mode:
 *  - For each device (physical first, then MD RAID), run a single profile:
 *      * rw=randread
 *      * bs=512k
 *      * iodepth=16
 *      * time_based, runtime=120s
 *  - Parse read IOPS from fio JSON output.
 *  - Emit the best device IOPS as a SCORE line:
 *
 *      {{SCORE:<best_iops>}}
 *
 * Matrix mode:
 *  - In addition to the main profile above, run a matrix of profiles:
 *      * bs in {4k,8k,16k,32k,64k,128k,256k,512k,1M}
 *      * iodepth in {1,2,4,8,16,32,64}
 *  - Matrix results are logged but the SCORE line is still derived from
 *    the main profile (bs=512k, iodepth=16) to keep comparisons stable.
 *
 * This wrapper uses fio in read-only mode (randread) and does not modify
 * partition tables or user data.
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

function benchmarkStorageFioRandReadMain(array $argv): int
{
    [$deviceFilter, $mode, $runtime, $bs, $iodepth, $scoreOnly, $colorEnabled] = benchmarkStorageFioRandReadParseArguments($argv);

    $fioBin = benchmarkStorageFioRandReadResolveFioBinary();
    if ($fioBin === null) {
        fwrite(STDERR, "Error: fio not found. Install fio or set MCXFORGE_FIO_BIN.\n");
        return EXIT_ERROR;
    }

    $devices = benchmarkStorageFioRandReadDiscoverDevices($deviceFilter);
    if (count($devices) === 0) {
        fwrite(STDERR, "Error: no suitable block devices found for fio randread benchmark.\n");
        return EXIT_ERROR;
    }

    $logFile = benchmarkStorageFioRandReadBuildLogFilePath();

    $titleColor = $colorEnabled ? "\033[1;34m" : '';
    $scoreColor = $colorEnabled ? "\033[1;32m" : '';
    $errorColor = $colorEnabled ? "\033[1;31m" : '';
    $resetColor = $colorEnabled ? "\033[0m" : '';

    if (!$scoreOnly) {
        fwrite(
            STDOUT,
            sprintf(
                "%s[benchmarkStorageFioRandRead]%s Running fio randread (bs=%s, iodepth=%d, mode=%s, runtime=%ds) on %d device(s)...%s\n",
                $titleColor,
                $resetColor,
                $bs,
                $iodepth,
                $mode,
                $runtime,
                count($devices),
                $resetColor
            )
        );
    }

    $bestScore = null;

    foreach ($devices as $device) {
        $path = $device['path'];
        $label = sprintf('%s (%s %sGiB)', $path, $device['model'], $device['sizeGiB']);

        // Main canonical profile.
        [$mainIops, $exitCode] = benchmarkStorageFioRandReadRunProfile(
            $fioBin,
            $path,
            $bs,
            $iodepth,
            $runtime,
            $logFile,
            'main'
        );

        if ($mainIops !== null && $exitCode === 0) {
            if ($bestScore === null || $mainIops > $bestScore) {
                $bestScore = $mainIops;
            }
        }

        if (!$scoreOnly) {
            if ($exitCode !== 0 || $mainIops === null) {
                fwrite(
                    STDERR,
                    sprintf(
                        "%s[benchmarkStorageFioRandRead] %s: main profile failed (exit=%d, iops=%s)%s\n",
                        $errorColor,
                        $label,
                        $exitCode,
                        $mainIops === null ? 'null' : (string)$mainIops,
                        $resetColor
                    )
                );
            } else {
                fwrite(
                    STDOUT,
                    sprintf(
                        "%s[benchmarkStorageFioRandRead]%s %s: %s%.1f%s IOPS (randread, bs=%s, iodepth=%d)\n",
                        $titleColor,
                        $resetColor,
                        $label,
                        $scoreColor,
                        $mainIops,
                        $resetColor,
                        $bs,
                        $iodepth
                    )
                );
            }
        }

        if ($mode === 'matrix') {
            $bsList = ['4k', '8k', '16k', '32k', '64k', '128k', '256k', '512k', '1M'];
            $iodepthList = [1, 2, 4, 8, 16, 32, 64];

            foreach ($bsList as $bsMatrix) {
                foreach ($iodepthList as $qdMatrix) {
                    if ($bsMatrix === $bs && $qdMatrix === $iodepth) {
                        continue;
                    }

                    benchmarkStorageFioRandReadRunProfile(
                        $fioBin,
                        $path,
                        $bsMatrix,
                        $qdMatrix,
                        $runtime,
                        $logFile,
                        'matrix'
                    );
                }
            }
        }
    }

    if ($bestScore === null) {
        fwrite(
            STDERR,
            sprintf(
                "%s[benchmarkStorageFioRandRead] Warning: could not parse IOPS for any device (see %s)%s\n",
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
                "%s[benchmarkStorageFioRandRead]%s Log file: %s\n",
                $titleColor,
                $resetColor,
                $logFile
            )
        );
        fwrite(
            STDOUT,
            sprintf(
                "%s[benchmarkStorageFioRandRead]%s Best device IOPS (randread, bs=%s, iodepth=%d): %s%.1f%s\n",
                $titleColor,
                $resetColor,
                $bs,
                $iodepth,
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
 * @return array{0:?string,1:string,2:int,3:string,4:int,5:bool,6:bool}
 */
function benchmarkStorageFioRandReadParseArguments(array $argv): array
{
    $device = null;
    $mode = 'main';
    $runtime = 120;
    $bs = '512k';
    $iodepth = 16;
    $scoreOnly = false;
    $colorEnabled = true;

    if (getenv('NO_COLOR') !== false) {
        $colorEnabled = false;
    }

    $args = $argv;
    array_shift($args);

    foreach ($args as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            benchmarkStorageFioRandReadPrintHelp();
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

        if (str_starts_with($arg, '--mode=')) {
            $value = substr($arg, strlen('--mode='));
            $value = trim($value);
            if (!in_array($value, ['main', 'matrix'], true)) {
                fwrite(STDERR, "Error: invalid --mode value '{$value}', use main or matrix\n");
                exit(EXIT_ERROR);
            }
            $mode = $value;
            continue;
        }

        if (str_starts_with($arg, '--runtime=')) {
            $value = substr($arg, strlen('--runtime='));
            $value = trim($value);
            if (!ctype_digit($value) || (int)$value <= 0) {
                fwrite(STDERR, "Error: invalid --runtime value '{$value}'\n");
                exit(EXIT_ERROR);
            }
            $runtime = (int)$value;
            continue;
        }

        if (str_starts_with($arg, '--bs=')) {
            $value = substr($arg, strlen('--bs='));
            $value = trim($value);
            if ($value === '') {
                fwrite(STDERR, "Error: --bs must not be empty\n");
                exit(EXIT_ERROR);
            }
            $bs = $value;
            continue;
        }

        if (str_starts_with($arg, '--iodepth=')) {
            $value = substr($arg, strlen('--iodepth='));
            $value = trim($value);
            if (!ctype_digit($value) || (int)$value <= 0) {
                fwrite(STDERR, "Error: invalid --iodepth value '{$value}'\n");
                exit(EXIT_ERROR);
            }
            $iodepth = (int)$value;
            continue;
        }

        fwrite(STDERR, "Error: unrecognized argument '{$arg}'. Use --help for usage.\n");
        exit(EXIT_ERROR);
    }

    return [$device, $mode, $runtime, $bs, $iodepth, $scoreOnly, $colorEnabled];
}

function benchmarkStorageFioRandReadPrintHelp(): void
{
    $help = <<<TEXT
Usage: benchmarkStorageFioRandRead.php [--device=/dev/NAME] [--mode=main|matrix] [--runtime=SECONDS] [--bs=SIZE] [--iodepth=N] [--score-only] [--no-color]

Run a non-destructive fio randread benchmark against block devices.

Default (mode=main):
  - Runs a single profile per device:
      rw=randread, bs=512k, iodepth=16, time_based, runtime=120s.
  - Emits the best device IOPS as a SCORE line:

    {{SCORE:<best_iops>}}

Matrix mode (mode=matrix):
  - Runs the main profile above plus a matrix of:
      bs in {4k,8k,16k,32k,64k,128k,256k,512k,1M}
      iodepth in {1,2,4,8,16,32,64}
  - Matrix results are logged; the SCORE line still reflects the main
    profile (bs=512k, iodepth=16) for comparability.

Options:
  --device=/dev/NAME  Restrict benchmark to a single block device path.
  --mode=main|matrix  Select main-only or matrix mode (default: main).
  --runtime=SECONDS   Runtime per fio profile (default: 120).
  --bs=SIZE           Block size (fio syntax, default: 512k).
  --iodepth=N         Queue depth (default: 16).
  --score-only        Print only the SCORE line, nothing else.
  --no-color          Disable ANSI colors in human output.
  -h, --help          Show this help message.

Notes:
  - This wrapper uses fio in randread mode with direct I/O; it does not
    perform writes or verification and therefore does not modify user data.
  - fio must be installed in PATH or MCXFORGE_FIO_BIN must point to it.

TEXT;

    echo $help;
}

function benchmarkStorageFioRandReadResolveFioBinary(): ?string
{
    $override = getenv('MCXFORGE_FIO_BIN');
    if ($override !== false && $override !== '') {
        $path = $override;
        if (is_file($path) && is_executable($path)) {
            return $path;
        }
    }

    $result = shell_exec('command -v fio 2>/dev/null');
    if (is_string($result)) {
        $path = trim($result);
        if ($path !== '' && is_file($path) && is_executable($path)) {
            return $path;
        }
    }

    return null;
}

/**
 * @return array<int,array<string,mixed>>
 */
function benchmarkStorageFioRandReadDiscoverDevices(?string $deviceFilter): array
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

    // Append MD RAID devices after physical disks.
    $mdDevices = benchmarkStorageFioRandReadDiscoverMdRaidDevices();
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
function benchmarkStorageFioRandReadDiscoverMdRaidDevices(): array
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

/**
 * @return array{0:?float,1:int}
 */
function benchmarkStorageFioRandReadRunProfile(
    string $fioBin,
    string $devicePath,
    string $bs,
    int $iodepth,
    int $runtime,
    string $logFile,
    string $profileKind
): array {
    $cmd = sprintf(
        '%s --name=mcxForgeRandRead --filename=%s --rw=randread --ioengine=libaio --direct=1 --iodepth=%d --bs=%s --time_based=1 --runtime=%d --numjobs=1 --group_reporting=1 --size=100%% --output-format=json 2>&1',
        escapeshellarg($fioBin),
        escapeshellarg($devicePath),
        $iodepth,
        escapeshellarg($bs),
        $runtime
    );

    $output = [];
    $exitCode = 0;
    exec($cmd, $output, $exitCode);

    $text = implode(PHP_EOL, $output) . PHP_EOL;
    $header = sprintf("# device=%s kind=%s bs=%s iodepth=%d\n", $devicePath, $profileKind, $bs, $iodepth);
    file_put_contents($logFile, $header . $text . "\n", FILE_APPEND);

    $joined = implode("\n", $output);
    $iops = benchmarkStorageFioRandReadParseIopsFromJson($joined);

    return [$iops, $exitCode];
}

function benchmarkStorageFioRandReadParseIopsFromJson(string $jsonText): ?float
{
    $pos = strpos($jsonText, '{');
    if ($pos === false) {
        return null;
    }

    $json = substr($jsonText, $pos);
    $decoded = json_decode($json, true);
    if (!is_array($decoded) || !isset($decoded['jobs']) || !is_array($decoded['jobs']) || count($decoded['jobs']) === 0) {
        return null;
    }

    $job = $decoded['jobs'][0];
    if (!is_array($job) || !isset($job['read']) || !is_array($job['read'])) {
        return null;
    }

    $read = $job['read'];
    if (!isset($read['iops']) || !is_numeric($read['iops'])) {
        return null;
    }

    return (float)$read['iops'];
}

function benchmarkStorageFioRandReadBuildLogFilePath(?\DateTimeImmutable $now = null): string
{
    $now = $now ?? new \DateTimeImmutable('now');
    $date = $now->format('Ymd');

    return sprintf('/tmp/benchmarkStorageFioRandRead-%s.log', $date);
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(benchmarkStorageFioRandReadMain($argv));
}

