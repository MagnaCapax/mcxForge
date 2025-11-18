#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * benchmarkMemorySysbench.php
 *
 * Benchmark memory throughput using sysbench memory mode and emit a JSONL score line.
 *
 * @author Aleksi Ursin
 */

if (!defined('EXIT_OK')) {
    define('EXIT_OK', 0);
}
if (!defined('EXIT_ERROR')) {
    define('EXIT_ERROR', 1);
}

require_once __DIR__ . '/../lib/php/benchmark/CPUInfo.php';
require_once __DIR__ . '/../lib/php/benchmark/SysbenchMemoryRunner.php';

use mcxForge\Benchmark\CPUInfo;
use mcxForge\Benchmark\SysbenchMemoryRunner;

function benchmarkMemorySysbenchMain(array $argv): int
{
    [$threads, $totalSizeGiB, $blockSizeKiB, $accessMode, $operation, $scoreOnly, $colorEnabled] =
        benchmarkMemorySysbenchParseArguments($argv);

    $runner = new SysbenchMemoryRunner();
    $logFile = $runner->buildLogFilePath();

    $titleColor = $colorEnabled ? "\033[1;34m" : '';
    $scoreColor = $colorEnabled ? "\033[1;32m" : '';
    $errorColor = $colorEnabled ? "\033[1;31m" : '';
    $resetColor = $colorEnabled ? "\033[0m" : '';

    if (!$scoreOnly) {
        fwrite(
            STDOUT,
            sprintf(
                "%s[benchmarkMemorySysbench]%s Running sysbench memory (%s/%s) with %d thread(s), total %d GiB, block %d KiB... Log file: %s%s\n",
                $titleColor,
                $resetColor,
                $accessMode,
                $operation,
                $threads,
                $totalSizeGiB,
                $blockSizeKiB,
                $logFile,
                $resetColor
            )
        );
    }

    $exitCode = null;
    $lines = $runner->run($threads, $totalSizeGiB, $blockSizeKiB, $accessMode, $operation, $exitCode);
    $text = implode(PHP_EOL, $lines) . PHP_EOL;
    file_put_contents($logFile, $text, FILE_APPEND);

    if ($exitCode !== 0) {
        fwrite(
            STDERR,
            sprintf(
                "%s[benchmarkMemorySysbench] sysbench exited with code %d (see %s)%s\n",
                $errorColor,
                $exitCode,
                $logFile,
                $resetColor
            )
        );
        return EXIT_ERROR;
    }

    $throughput = $runner->parseThroughput($lines);
    if ($throughput === null) {
        fwrite(
            STDERR,
            sprintf(
                "%s[benchmarkMemorySysbench] Warning: could not parse sysbench memory throughput (see %s)%s\n",
                $errorColor,
                $logFile,
                $resetColor
            )
        );
        return EXIT_ERROR;
    }

    $perThread = $throughput / max(1, $threads);

    if (!$scoreOnly) {
        fwrite(
            STDOUT,
            sprintf(
                "%s[benchmarkMemorySysbench]%s Parsed throughput: %s%.2f%s MiB/s total (%.2f MiB/s per thread, %d thread(s))\n",
                $titleColor,
                $resetColor,
                $scoreColor,
                $throughput,
                $resetColor,
                $perThread,
                $threads
            )
        );
    }

    $payload = benchmarkMemorySysbenchBuildScorePayload(
        $throughput,
        $perThread,
        $threads,
        $totalSizeGiB,
        $blockSizeKiB,
        $accessMode,
        $operation,
        $logFile
    );
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        fwrite(STDERR, "[benchmarkMemorySysbench] Failed to encode JSON score payload\n");
        return EXIT_ERROR;
    }

    fwrite(STDOUT, sprintf("[benchmarkMemorySysbench] %s\n", $json));

    return EXIT_OK;
}

/**
 * @return array{0:int,1:int,2:int,3:string,4:string,5:bool,6:bool}
 */
function benchmarkMemorySysbenchParseArguments(array $argv): array
{
    $threads = CPUInfo::detectLogicalCores();
    $totalSizeGiB = 4;
    $blockSizeKiB = 4;
    $accessMode = 'seq';
    $operation = 'read';
    $scoreOnly = false;
    $colorEnabled = true;

    if (getenv('NO_COLOR') !== false) {
        $colorEnabled = false;
    }

    $args = $argv;
    array_shift($args);

    foreach ($args as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            benchmarkMemorySysbenchPrintHelp();
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

        if (str_starts_with($arg, '--threads=')) {
            $value = trim(substr($arg, strlen('--threads=')));
            if (!ctype_digit($value) || (int) $value <= 0) {
                fwrite(STDERR, "Error: invalid --threads value '{$value}'\n");
                exit(EXIT_ERROR);
            }
            $threads = (int) $value;
            continue;
        }

        if (str_starts_with($arg, '--total-size=')) {
            $value = trim(substr($arg, strlen('--total-size=')));
            if (!ctype_digit($value) || (int) $value <= 0) {
                fwrite(STDERR, "Error: invalid --total-size value '{$value}'\n");
                exit(EXIT_ERROR);
            }
            $totalSizeGiB = (int) $value;
            continue;
        }

        if (str_starts_with($arg, '--block-size=')) {
            $value = trim(substr($arg, strlen('--block-size=')));
            if (!ctype_digit($value) || (int) $value <= 0) {
                fwrite(STDERR, "Error: invalid --block-size value '{$value}'\n");
                exit(EXIT_ERROR);
            }
            $blockSizeKiB = (int) $value;
            continue;
        }

        if (str_starts_with($arg, '--access-mode=')) {
            $value = trim(substr($arg, strlen('--access-mode=')));
            if (!in_array($value, ['seq', 'rnd'], true)) {
                fwrite(STDERR, "Error: invalid --access-mode value '{$value}', use seq or rnd\n");
                exit(EXIT_ERROR);
            }
            $accessMode = $value;
            continue;
        }

        if (str_starts_with($arg, '--operation=')) {
            $value = trim(substr($arg, strlen('--operation=')));
            if (!in_array($value, ['read', 'write', 'rwr'], true)) {
                fwrite(STDERR, "Error: invalid --operation value '{$value}', use read, write, or rwr\n");
                exit(EXIT_ERROR);
            }
            $operation = $value;
            continue;
        }

        fwrite(STDERR, "Error: unrecognized argument '{$arg}'. Use --help for usage.\n");
        exit(EXIT_ERROR);
    }

    return [$threads, $totalSizeGiB, $blockSizeKiB, $accessMode, $operation, $scoreOnly, $colorEnabled];
}

function benchmarkMemorySysbenchPrintHelp(): void
{
    $help = <<<TEXT
Usage: benchmarkMemorySysbench.php [--threads=N] [--total-size=GiB] [--block-size=KiB] [--access-mode=seq|rnd] [--operation=read|write|rwr] [--score-only] [--no-color]

Benchmark memory throughput using sysbench memory mode and emit a JSONL score line.

Options:
  --threads=N         Number of worker threads (default: detected logical cores).
  --total-size=GiB    Total memory per thread to exercise (default: 4).
  --block-size=KiB    Block size (default: 4).
  --access-mode=MODE  Access mode: seq or rnd (default: seq).
  --operation=OP      Operation: read, write, or rwr (default: read).
  --score-only        Print only the JSONL score line, nothing else.
  --no-color          Disable ANSI colors in human output.
  -h, --help          Show this help message.

Notes:
  - Raw output is appended to /tmp/benchmarkMemorySysbench-YYYYMMDD.log.
  - The JSONL score line uses schema mcxForge.cpu-benchmark.v1 semantics with memory-specific metric/unit.

TEXT;

    echo $help;
}

/**
 * @return array<string,mixed>
 */
function benchmarkMemorySysbenchBuildScorePayload(
    float $scoreTotal,
    float $scorePerThread,
    int $threads,
    int $totalSizeGiB,
    int $blockSizeKiB,
    string $accessMode,
    string $operation,
    string $logFile
): array {
    return [
        'schema' => 'mcxForge.memory-benchmark.v1',
        'benchmark' => 'memsysbench',
        'status' => 'ok',
        'metric' => 'throughput',
        'unit' => 'MiB/s',
        'score' => $scoreTotal,
        'scorePerThread' => $scorePerThread,
        'threads' => $threads,
        'totalSizeGiB' => $totalSizeGiB,
        'blockSizeKiB' => $blockSizeKiB,
        'accessMode' => $accessMode,
        'operation' => $operation,
        'durationSeconds' => null,
        'logFile' => $logFile,
    ];
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(benchmarkMemorySysbenchMain($argv));
}

