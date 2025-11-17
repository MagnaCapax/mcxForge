#!/usr/bin/env php
<?php

declare(strict_types=1);

if (!defined('EXIT_OK')) {
    define('EXIT_OK', 0);
}
if (!defined('EXIT_ERROR')) {
    define('EXIT_ERROR', 1);
}

require_once __DIR__ . '/../lib/php/Benchmark/CpuInfo.php';
require_once __DIR__ . '/../lib/php/Benchmark/SysbenchCpuRunner.php';

use mcxForge\Benchmark\CpuInfo;
use mcxForge\Benchmark\SysbenchCpuRunner;

function benchmarkCPUSysbenchMain(array $argv): int
{
    [$duration, $threads, $scoreOnly, $colorEnabled] = benchmarkCPUSysbenchParseArguments($argv);

    $runner = new SysbenchCpuRunner();
    $logFile = $runner->buildLogFilePath();

    $titleColor = $colorEnabled ? "\033[1;34m" : '';
    $scoreColor = $colorEnabled ? "\033[1;32m" : '';
    $errorColor = $colorEnabled ? "\033[1;31m" : '';
    $resetColor = $colorEnabled ? "\033[0m" : '';

    if (!$scoreOnly) {
        fwrite(
            STDOUT,
            sprintf(
                "%s[benchmarkCPUSysbench]%s Running sysbench cpu for %ds on %d thread(s)...%s\n",
                $titleColor,
                $resetColor,
                $duration,
                $threads,
                $resetColor
            )
        );
    }

    $exitCode = null;
    $lines = $runner->run($threads, $duration, $exitCode);
    $text = implode(PHP_EOL, $lines) . PHP_EOL;
    file_put_contents($logFile, $text, FILE_APPEND);

    if ($exitCode !== 0) {
        if (!$scoreOnly) {
            fwrite(
                STDERR,
                sprintf(
                    "%s[benchmarkCPUSysbench] sysbench exited with code %d%s\n",
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
        if (!$scoreOnly) {
            fwrite(
                STDERR,
                sprintf(
                    "%s[benchmarkCPUSysbench] Warning: could not parse sysbench events per second%s\n",
                    $errorColor,
                    $resetColor
                )
            );
        }
        return EXIT_ERROR;
    }

    $threadsCount = max(1, $threads);
    $normalized = $score / $threadsCount;

    if (!$scoreOnly) {
        fwrite(
            STDOUT,
            sprintf(
                "%s[benchmarkCPUSysbench]%s Log file: %s\n",
                $titleColor,
                $resetColor,
                $logFile
            )
        );
        fwrite(
            STDOUT,
            sprintf(
                "%s[benchmarkCPUSysbench]%s Parsed score: %s%.2f%s events/s (%.2f per thread)\n",
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
function benchmarkCPUSysbenchParseArguments(array $argv): array
{
    $duration = 60;
    $threads = CpuInfo::detectLogicalCores();
    $scoreOnly = false;
    $colorEnabled = true;

    if (getenv('NO_COLOR') !== false) {
        $colorEnabled = false;
    }

    $args = $argv;
    array_shift($args);

    foreach ($args as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            benchmarkCPUSysbenchPrintHelp();
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

        if (str_starts_with($arg, '--threads=')) {
            $value = substr($arg, strlen('--threads='));
            $value = trim($value);
            if (!ctype_digit($value) || (int) $value <= 0) {
                fwrite(STDERR, "Error: invalid --threads value '{$value}'\n");
                exit(EXIT_ERROR);
            }
            $threads = (int) $value;
            continue;
        }

        fwrite(STDERR, "Error: unrecognized argument '{$arg}'. Use --help for usage.\n");
        exit(EXIT_ERROR);
    }

    return [$duration, $threads, $scoreOnly, $colorEnabled];
}

function benchmarkCPUSysbenchPrintHelp(): void
{
    $help = <<<TEXT
Usage: benchmarkCPUSysbench.php [--duration=SECONDS] [--threads=N] [--score-only] [--no-color]

Run a CPU benchmark using sysbench cpu, log output under /tmp, and emit a normalized score:

  {{SCORE:<events_per_second_per_thread>}}

Options:
  --duration=SECONDS  Run time for sysbench (default: 60).
  --threads=N         Number of worker threads to use (default: detected logical cores).
  --score-only        Print only the SCORE line, nothing else.
  --no-color          Disable ANSI colors in human output.
  -h, --help          Show this help message.

Notes:
  - Raw output is appended to /tmp/benchmarkCPUSysbench-YYYYMMDD.log.
  - The score is normalized per thread to improve comparability across CPUs.

TEXT;

    echo $help;
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(benchmarkCPUSysbenchMain($argv));
}
