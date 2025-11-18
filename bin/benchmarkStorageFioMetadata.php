#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * benchmarkStorageFioMetadata.php
 *
 * Run a metadata-heavy fio workload against a single target directory to
 * exercise filesystem metadata and kernel paths (create/open/close/stat)
 * with many concurrent workers.
 *
 * This tool always operates inside the explicitly provided target directory
 * and never walks arbitrary filesystem trees. It creates and deletes its own
 * test files under that directory.
 *
 * Default profile:
 *  - numjobs=16
 *  - rw=randrw
 *  - rwmixread=100 (read-heavy; writes limited to file creation)
 *  - filesize=4k
 *  - nrfiles=1024
 *  - time_based, runtime=60s
 *
 * The final SCORE is the total read IOPS across all workers:
 *
 *   {{SCORE:<total_read_iops>}}
 *
 * @author Aleksi Ursin
 */

if (!defined('EXIT_OK')) {
    define('EXIT_OK', 0);
}
if (!defined('EXIT_ERROR')) {
    define('EXIT_ERROR', 1);
}

function benchmarkStorageFioMetadataMain(array $argv): int
{
    try {
        [$targetDir, $runtime, $numJobs, $nrFiles, $fileSize, $scoreOnly, $colorEnabled] = benchmarkStorageFioMetadataParseArguments($argv);
    } catch (\InvalidArgumentException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        return EXIT_ERROR;
    }

    $fioBin = benchmarkStorageFioMetadataResolveFioBinary();
    if ($fioBin === null) {
        fwrite(STDERR, "Error: fio not found. Install fio or set MCXFORGE_FIO_BIN.\n");
        return EXIT_ERROR;
    }

    if (!is_dir($targetDir) || !is_writable($targetDir)) {
        fwrite(STDERR, "Error: target directory '{$targetDir}' does not exist or is not writable.\n");
        return EXIT_ERROR;
    }

    $logFile = benchmarkStorageFioMetadataBuildLogFilePath();

    $titleColor = $colorEnabled ? "\033[1;34m" : '';
    $scoreColor = $colorEnabled ? "\033[1;32m" : '';
    $errorColor = $colorEnabled ? "\033[1;31m" : '';
    $resetColor = $colorEnabled ? "\033[0m" : '';

    if (!$scoreOnly) {
        fwrite(
            STDOUT,
            sprintf(
                "%s[benchmarkStorageFioMetadata]%s Running fio metadata workload in '%s' (runtime=%ds, jobs=%d, files=%d, filesize=%s)...%s\n",
                $titleColor,
                $resetColor,
                $targetDir,
                $runtime,
                $numJobs,
                $nrFiles,
                $fileSize,
                $resetColor
            )
        );
    }

    [$iops, $exitCode] = benchmarkStorageFioMetadataRunProfile(
        $fioBin,
        $targetDir,
        $runtime,
        $numJobs,
        $nrFiles,
        $fileSize,
        $logFile
    );

    if ($iops === null || $exitCode !== 0) {
        if (!$scoreOnly) {
            fwrite(
                STDERR,
                sprintf(
                    "%s[benchmarkStorageFioMetadata] Metadata profile failed (exit=%d, iops=%s). See %s%s\n",
                    $errorColor,
                    $exitCode,
                    $iops === null ? 'null' : (string)$iops,
                    $logFile,
                    $resetColor
                )
            );
        }
        return EXIT_ERROR;
    }

    if (!$scoreOnly) {
        fwrite(
            STDOUT,
            sprintf(
                "%s[benchmarkStorageFioMetadata]%s Total read IOPS (metadata workload): %s%.1f%s\n",
                $titleColor,
                $resetColor,
                $scoreColor,
                $iops,
                $resetColor
            )
        );
        fwrite(
            STDOUT,
            sprintf(
                "%s[benchmarkStorageFioMetadata]%s Log file: %s\n",
                $titleColor,
                $resetColor,
                $logFile
            )
        );
    }

    fwrite(STDOUT, sprintf("{{SCORE:%.1f}}\n", $iops));

    return EXIT_OK;
}

/**
 * @return array{0:string,1:int,2:int,3:int,4:string,5:bool,6:bool}
 */
function benchmarkStorageFioMetadataParseArguments(array $argv): array
{
    $targetDir = '';
    $runtime = 60;
    $numJobs = 16;
    $nrFiles = 1024;
    $fileSize = '4k';
    $scoreOnly = false;
    $colorEnabled = true;

    if (getenv('NO_COLOR') !== false) {
        $colorEnabled = false;
    }

    $args = $argv;
    array_shift($args);

    foreach ($args as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            benchmarkStorageFioMetadataPrintHelp();
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

        if (str_starts_with($arg, '--target-dir=')) {
            $value = substr($arg, strlen('--target-dir='));
            $value = trim($value);
            if ($value === '') {
                fwrite(STDERR, "Error: --target-dir must not be empty\n");
                exit(EXIT_ERROR);
            }
            $targetDir = $value;
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

        if (str_starts_with($arg, '--jobs=')) {
            $value = substr($arg, strlen('--jobs='));
            $value = trim($value);
            if (!ctype_digit($value) || (int)$value <= 0) {
                fwrite(STDERR, "Error: invalid --jobs value '{$value}'\n");
                exit(EXIT_ERROR);
            }
            $numJobs = (int)$value;
            continue;
        }

        if (str_starts_with($arg, '--nrfiles=')) {
            $value = substr($arg, strlen('--nrfiles='));
            $value = trim($value);
            if (!ctype_digit($value) || (int)$value <= 0) {
                fwrite(STDERR, "Error: invalid --nrfiles value '{$value}'\n");
                exit(EXIT_ERROR);
            }
            $nrFiles = (int)$value;
            continue;
        }

        if (str_starts_with($arg, '--filesize=')) {
            $value = substr($arg, strlen('--filesize='));
            $value = trim($value);
            if ($value === '') {
                fwrite(STDERR, "Error: --filesize must not be empty\n");
                exit(EXIT_ERROR);
            }
            $fileSize = $value;
            continue;
        }

        fwrite(STDERR, "Error: unrecognized argument '{$arg}'. Use --help for usage.\n");
        exit(EXIT_ERROR);
    }

    if ($targetDir === '') {
        throw new \InvalidArgumentException(
            'Error: --target-dir is required and must point to a writable directory.'
        );
    }

    return [$targetDir, $runtime, $numJobs, $nrFiles, $fileSize, $scoreOnly, $colorEnabled];
}

function benchmarkStorageFioMetadataPrintHelp(): void
{
    $help = <<<TEXT
Usage: benchmarkStorageFioMetadata.php --target-dir=/path/to/dir [--runtime=SECONDS] [--jobs=N] [--nrfiles=N] [--filesize=SIZE] [--score-only] [--no-color]

Run a metadata-heavy fio workload against a single target directory to
exercise filesystem metadata and kernel latencies with multiple workers.

Defaults:
  - jobs:       16
  - runtime:    60 seconds
  - nrfiles:    1024 per job
  - filesize:   4k

The workload uses randrw with a read-heavy mix (rwmixread=100); writes
are limited to creating and updating small test files inside the target
directory.

The final SCORE is the total read IOPS across all workers:

  {{SCORE:<total_read_iops>}}

Options:
  --target-dir=DIR   Writable directory used exclusively for test files.
  --runtime=SECONDS  Runtime per fio run (default: 60).
  --jobs=N           Number of parallel worker jobs (default: 16).
  --nrfiles=N        Number of files per job (default: 1024).
  --filesize=SIZE    Filesize for each test file (fio syntax, default: 4k).
  --score-only       Print only the SCORE line, nothing else.
  --no-color         Disable ANSI colors in human output.
  -h, --help         Show this help message.

Safety notes:
  - This tool only operates inside the explicit --target-dir and creates
    its own test files there. It does not walk other directories or touch
    existing data outside that directory.

TEXT;

    echo $help;
}

function benchmarkStorageFioMetadataResolveFioBinary(): ?string
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
 * @return array{0:?float,1:int}
 */
function benchmarkStorageFioMetadataRunProfile(
    string $fioBin,
    string $targetDir,
    int $runtime,
    int $numJobs,
    int $nrFiles,
    string $fileSize,
    string $logFile
): array {
    $cmd = sprintf(
        '%s --name=mcxForgeMetadata --directory=%s --rw=randrw --rwmixread=100 --ioengine=libaio --direct=1 --numjobs=%d --iodepth=4 --filesize=%s --nrfiles=%d --time_based=1 --runtime=%d --group_reporting=1 --random_distribution=zipf:1.2 --create_on_open=1 --output-format=json 2>&1',
        escapeshellarg($fioBin),
        escapeshellarg($targetDir),
        $numJobs,
        escapeshellarg($fileSize),
        $nrFiles,
        $runtime
    );

    $output = [];
    $exitCode = 0;
    exec($cmd, $output, $exitCode);

    $text = implode(PHP_EOL, $output) . PHP_EOL;
    file_put_contents($logFile, $text . "\n", FILE_APPEND);

    $joined = implode("\n", $output);
    $iops = benchmarkStorageFioMetadataParseIopsFromJson($joined);

    return [$iops, $exitCode];
}

function benchmarkStorageFioMetadataParseIopsFromJson(string $jsonText): ?float
{
    $pos = strpos($jsonText, '{');
    if ($pos === false) {
        return null;
    }

    $json = substr($jsonText, $pos);
    $decoded = json_decode($json, true);
    if (!is_array($decoded) || !isset($decoded['jobs']) || !is_array($decoded['jobs'])) {
        return null;
    }

    $totalIops = 0.0;
    $seen = 0;

    foreach ($decoded['jobs'] as $job) {
        if (!is_array($job) || !isset($job['read']) || !is_array($job['read'])) {
            continue;
        }
        $read = $job['read'];
        if (!isset($read['iops']) || !is_numeric($read['iops'])) {
            continue;
        }
        $totalIops += (float)$read['iops'];
        $seen++;
    }

    if ($seen === 0) {
        return null;
    }

    return $totalIops;
}

function benchmarkStorageFioMetadataBuildLogFilePath(?\DateTimeImmutable $now = null): string
{
    $now = $now ?? new \DateTimeImmutable('now');
    $date = $now->format('Ymd');

    return sprintf('/tmp/benchmarkStorageFioMetadata-%s.log', $date);
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(benchmarkStorageFioMetadataMain($argv));
}
