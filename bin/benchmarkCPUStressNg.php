#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * @author Aleksi Ursin
 */

if (!defined('EXIT_OK')) {
    define('EXIT_OK', 0);
}
if (!defined('EXIT_ERROR')) {
    define('EXIT_ERROR', 1);
}

require_once __DIR__ . '/../lib/php/benchmark/CPUInfo.php';
require_once __DIR__ . '/../lib/php/Benchmark/StressNgRunner.php';

use mcxForge\Benchmark\CPUInfo;
use mcxForge\Benchmark\StressNgRunner;

function benchmarkCPUStressNgMain(array $argv): int
{
    [$duration, $cpuCount, $scoreOnly, $colorEnabled] = benchmarkCPUStressNgParseArguments($argv);

    $runner = new StressNgRunner();
    $logFile = $runner->buildLogFilePath();

    $titleColor = $colorEnabled ? "\033[1;34m" : '';
    $scoreColor = $colorEnabled ? "\033[1;32m" : '';
    $errorColor = $colorEnabled ? "\033[1;31m" : '';
    $resetColor = $colorEnabled ? "\033[0m" : '';

    if (!$scoreOnly) {
        fwrite(
            STDOUT,
            sprintf(
                "%s[benchmarkCPUStressNg]%s Running stress-ng for %ds on %d CPU worker(s)...%s\n",
                $titleColor,
                $resetColor,
                $duration,
                $cpuCount,
                $resetColor
            )
        );
    }

    $exitCode = null;
    $lines = $runner->run($cpuCount, $duration, $exitCode);
    $text = implode(PHP_EOL, $lines) . PHP_EOL;
    file_put_contents($logFile, $text, FILE_APPEND);

    if ($exitCode !== 0) {
        if (!$scoreOnly) {
            fwrite(
                STDERR,
                sprintf(
                    "%s[benchmarkCPUStressNg] stress-ng exited with code %d%s\n",
                    $errorColor,
                    $exitCode,
                    $resetColor
                )
            );
        }
        return EXIT_ERROR;
    }

    $score = $runner->parseScore($lines);
    if ($score === null) {
        fwrite(
            STDERR,
            sprintf(
                "%s[benchmarkCPUStressNg] Warning: could not parse stress-ng bogo ops/s (see %s)%s\n",
                $errorColor,
                $logFile,
                $resetColor
            )
        );
        $tail = array_slice($lines, -10);
        foreach ($tail as $line) {
            fwrite(
                STDERR,
                sprintf(
                    "[benchmarkCPUStressNg][tail] %s\n",
                    rtrim($line, "\r\n")
                )
            );
        }
        return EXIT_ERROR;
    }

    $threads = max(1, $cpuCount);
    $normalized = $score / $threads;

    if (!$scoreOnly) {
        fwrite(
            STDOUT,
            sprintf(
                "%s[benchmarkCPUStressNg]%s Log file: %s\n",
                $titleColor,
                $resetColor,
                $logFile
            )
        );
        fwrite(
            STDOUT,
            sprintf(
                "%s[benchmarkCPUStressNg]%s Parsed score: %s%.2f%s bogo ops/s (%.2f per thread)\n",
                $titleColor,
                $resetColor,
                $scoreColor,
                $score,
                $resetColor,
                $normalized
            )
        );
    }

    fwrite(STDOUT, sprintf("{{SCORE:%.2f}}\n", $normalized));

    return EXIT_OK;
}

/**
 * @return array{0:int,1:int,2:bool,3:bool}
 */
function benchmarkCPUStressNgParseArguments(array $argv): array
{
    $duration = 120;
    $cpuCount = CPUInfo::detectLogicalCores();
    $scoreOnly = false;
    $colorEnabled = true;

    if (getenv('NO_COLOR') !== false) {
        $colorEnabled = false;
    }

    $args = $argv;
    array_shift($args);

    foreach ($args as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            benchmarkCPUStressNgPrintHelp();
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
            $value = substr($arg, strlen('--duration='));
            $value = trim($value);
            if (!ctype_digit($value) || (int) $value <= 0) {
                fwrite(STDERR, "Error: invalid --duration value '{$value}'\n");
                exit(EXIT_ERROR);
            }
            $duration = (int) $value;
            continue;
        }

        if (str_starts_with($arg, '--cpu-count=')) {
            $value = substr($arg, strlen('--cpu-count='));
            $value = trim($value);
            if (!ctype_digit($value) || (int) $value <= 0) {
                fwrite(STDERR, "Error: invalid --cpu-count value '{$value}'\n");
                exit(EXIT_ERROR);
            }
            $cpuCount = (int) $value;
            continue;
        }

        fwrite(STDERR, "Error: unrecognized argument '{$arg}'. Use --help for usage.\n");
        exit(EXIT_ERROR);
    }

    return [$duration, $cpuCount, $scoreOnly, $colorEnabled];
}

function benchmarkCPUStressNgPrintHelp(): void
{
    $help = <<<TEXT
Usage: benchmarkCPUStressNg.php [--duration=SECONDS] [--cpu-count=N] [--score-only] [--no-color]

Run a CPU stress test using stress-ng, log output under /tmp, and emit a normalized score:

  {{SCORE:<bogo_ops_per_second_per_thread>}}

Options:
  --duration=SECONDS  Run time for stress-ng (default: 120).
  --cpu-count=N       Number of CPU workers to use (default: detected logical cores).
  --score-only        Print only the SCORE line, nothing else.
  --no-color          Disable ANSI colors in human output.
  -h, --help          Show this help message.

Notes:
  - Raw output is appended to /tmp/benchmarkCPUStressNg-YYYYMMDD.log.
  - The score is normalized per thread to improve comparability across CPUs.

TEXT;

    echo $help;
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(benchmarkCPUStressNgMain($argv));
}
