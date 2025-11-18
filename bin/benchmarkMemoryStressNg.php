#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * benchmarkMemoryStressNg.php
 *
 * Exercise memory using stress-ng vm stressors and emit a JSONL score line.
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
require_once __DIR__ . '/../lib/php/benchmark/MemoryStressNgRunner.php';

use mcxForge\Benchmark\CPUInfo;
use mcxForge\Benchmark\MemoryStressNgRunner;

function benchmarkMemoryStressNgMain(array $argv): int
{
    [$duration, $workers, $percent, $scoreOnly, $colorEnabled] = benchmarkMemoryStressNgParseArguments($argv);

    $runner = new MemoryStressNgRunner();
    $logFile = $runner->buildLogFilePath();

    $titleColor = $colorEnabled ? "\033[1;34m" : '';
    $scoreColor = $colorEnabled ? "\033[1;32m" : '';
    $errorColor = $colorEnabled ? "\033[1;31m" : '';
    $resetColor = $colorEnabled ? "\033[0m" : '';

    if (!$scoreOnly) {
        fwrite(
            STDOUT,
            sprintf(
                "%s[benchmarkMemoryStressNg]%s Running stress-ng vm for %ds on %d worker(s) at %d%% of RAM... Log file: %s%s\n",
                $titleColor,
                $resetColor,
                $duration,
                $workers,
                $percent,
                $logFile,
                $resetColor
            )
        );
    }

    $exitCode = null;
    $lines = $runner->run($workers, $duration, $percent, $exitCode);
    $text = implode(PHP_EOL, $lines) . PHP_EOL;
    file_put_contents($logFile, $text, FILE_APPEND);

    if ($exitCode !== 0) {
        fwrite(
            STDERR,
            sprintf(
                "%s[benchmarkMemoryStressNg] stress-ng exited with code %d (see %s)%s\n",
                $errorColor,
                $exitCode,
                $logFile,
                $resetColor
            )
        );
        return EXIT_ERROR;
    }

    $score = $runner->parseScore($lines);
    if ($score === null) {
        fwrite(
            STDERR,
            sprintf(
                "%s[benchmarkMemoryStressNg] Warning: could not parse stress-ng vm bogo ops/s (see %s)%s\n",
                $errorColor,
                $logFile,
                $resetColor
            )
        );
        return EXIT_ERROR;
    }

    $perThread = $score / max(1, $workers);

    if (!$scoreOnly) {
        fwrite(
            STDOUT,
            sprintf(
                "%s[benchmarkMemoryStressNg]%s Parsed score: %s%.2f%s bogo ops/s total (%.2f per worker, %d worker(s))\n",
                $titleColor,
                $resetColor,
                $scoreColor,
                $score,
                $resetColor,
                $perThread,
                $workers
            )
        );
    }

    $payload = benchmarkMemoryStressNgBuildScorePayload($score, $perThread, $workers, $duration, $percent, $logFile);
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        fwrite(STDERR, "[benchmarkMemoryStressNg] Failed to encode JSON score payload\n");
        return EXIT_ERROR;
    }

    fwrite(STDOUT, sprintf("[benchmarkMemoryStressNg] %s\n", $json));

    return EXIT_OK;
}

/**
 * @return array{0:int,1:int,2:int,3:bool,4:bool}
 */
function benchmarkMemoryStressNgParseArguments(array $argv): array
{
    $duration = 120;
    $workers = CPUInfo::detectLogicalCores();
    $percent = 80;
    $scoreOnly = false;
    $colorEnabled = true;

    if (getenv('NO_COLOR') !== false) {
        $colorEnabled = false;
    }

    $args = $argv;
    array_shift($args);

    foreach ($args as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            benchmarkMemoryStressNgPrintHelp();
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

        if (str_starts_with($arg, '--duration=')) {
            $value = trim(substr($arg, strlen('--duration=')));
            if (!ctype_digit($value) || (int) $value <= 0) {
                fwrite(STDERR, "Error: invalid --duration value '{$value}'\n");
                exit(EXIT_ERROR);
            }
            $duration = (int) $value;
            continue;
        }

        if (str_starts_with($arg, '--workers=')) {
            $value = trim(substr($arg, strlen('--workers=')));
            if (!ctype_digit($value) || (int) $value <= 0) {
                fwrite(STDERR, "Error: invalid --workers value '{$value}'\n");
                exit(EXIT_ERROR);
            }
            $workers = (int) $value;
            continue;
        }

        if (str_starts_with($arg, '--percent=')) {
            $value = trim(substr($arg, strlen('--percent=')));
            if (!ctype_digit($value)) {
                fwrite(STDERR, "Error: invalid --percent value '{$value}'\n");
                exit(EXIT_ERROR);
            }
            $percent = (int) $value;
            if ($percent <= 0 || $percent > 100) {
                fwrite(STDERR, "Error: --percent must be between 1 and 100\n");
                exit(EXIT_ERROR);
            }
            continue;
        }

        fwrite(STDERR, "Error: unrecognized argument '{$arg}'. Use --help for usage.\n");
        exit(EXIT_ERROR);
    }

    return [$duration, $workers, $percent, $scoreOnly, $colorEnabled];
}

function benchmarkMemoryStressNgPrintHelp(): void
{
    $help = <<<TEXT
Usage: benchmarkMemoryStressNg.php [--duration=SECONDS] [--workers=N] [--percent=NN] [--score-only] [--no-color]

Exercise memory using stress-ng vm stressors and emit a JSONL score line.

Options:
  --duration=SECONDS  Run time for stress-ng (default: 120).
  --workers=N         Number of vm workers (default: detected logical cores).
  --percent=NN        Percentage of total RAM to exercise per vm instance via --vm-bytes (default: 80).
  --score-only        Print only the JSONL score line, nothing else.
  --no-color          Disable ANSI colors in human output.
  -h, --help          Show this help message.

Notes:
  - Raw output is appended to /tmp/benchmarkMemoryStressNg-YYYYMMDD.log.
  - The JSONL score line reports bogo ops/s as the primary metric.

TEXT;

    echo $help;
}

/**
 * @return array<string,mixed>
 */
function benchmarkMemoryStressNgBuildScorePayload(
    float $scoreTotal,
    float $scorePerWorker,
    int $workers,
    int $durationSeconds,
    int $percentOfRam,
    string $logFile
): array {
    return [
        'schema' => 'mcxForge.memory-benchmark.v1',
        'benchmark' => 'memstressng',
        'status' => 'ok',
        'metric' => 'bogo_ops_per_second',
        'unit' => 'bogo ops/s',
        'score' => $scoreTotal,
        'scorePerThread' => $scorePerWorker,
        'threads' => $workers,
        'durationSeconds' => $durationSeconds,
        'percentOfRam' => $percentOfRam,
        'logFile' => $logFile,
    ];
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(benchmarkMemoryStressNgMain($argv));
}

