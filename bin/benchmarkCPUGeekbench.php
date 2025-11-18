#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * benchmarkCPUGeekbench.php
 *
 * Download and run Geekbench 5 or 6, write results to a log file under /tmp,
 * and print a summary plus a final programmatic score line:
 *
 *   {{SCORE:12345}}
 *
 * When --score-only is used, only the SCORE line is printed.
 *
 * @author Aleksi Ursin
 */

if (!defined('EXIT_OK')) {
    define('EXIT_OK', 0);
}
if (!defined('EXIT_ERROR')) {
    define('EXIT_ERROR', 1);
}

require_once __DIR__ . '/../lib/php/benchmark/GeekbenchRunner.php';
require_once __DIR__ . '/../lib/php/Logger.php';

\mcxForge\Logger::initStreamLogging();

use mcxForge\Benchmark\GeekbenchRunner;

function benchmarkGeekbenchMain(array $argv): int
{
    [$major, $scoreOnly, $colorEnabled] = benchmarkGeekbenchParseArguments($argv);

    $runner = new GeekbenchRunner();

    try {
        $versionString = $runner->resolveVersionString($major);
    } catch (\InvalidArgumentException $e) {
        \mcxForge\Logger::logStderr("Error: " . $e->getMessage() . PHP_EOL);
        return EXIT_ERROR;
    }

    $logFile = $runner->buildLogFilePath($major);

    $titleColor = $colorEnabled ? "\033[1;34m" : '';
    $scoreColor = $colorEnabled ? "\033[1;32m" : '';
    $errorColor = $colorEnabled ? "\033[1;31m" : '';
    $resetColor = $colorEnabled ? "\033[0m" : '';

    if (!$scoreOnly) {
        $label = $major === '5' ? 'Geekbench 5' : 'Geekbench 6';
        fwrite(
            STDOUT,
            sprintf(
                "%s[benchmarkGeekbench]%s Running %s (version %s)... Log file: %s%s\n",
                $titleColor,
                $resetColor,
                $label,
                $versionString,
                $logFile,
                $resetColor
            )
        );
    }

    try {
        $binaryPath = $runner->ensureBinary($versionString);
    } catch (\Throwable $e) {
        fwrite(
            STDERR,
            sprintf(
                "%s[benchmarkGeekbench] Error preparing Geekbench: %s%s\n",
                $errorColor,
                $e->getMessage(),
                $resetColor
            )
        );
        return EXIT_ERROR;
    }

    $exitCode = null;
    $outputLines = $runner->runBinary($binaryPath, $exitCode);
    $outputText = implode(PHP_EOL, $outputLines) . PHP_EOL;

    if ($exitCode !== 0) {
        fwrite(
            STDERR,
            sprintf(
                "%s[benchmarkGeekbench] Geekbench exited with code %d%s\n",
                $errorColor,
                $exitCode,
                $resetColor
            )
        );
    }

    file_put_contents($logFile, $outputText, FILE_APPEND);

    $score = $runner->parseScore($outputLines, $major);
    if ($score === null) {
        fwrite(
            STDERR,
            sprintf(
                "%s[benchmarkGeekbench] Warning: could not parse Geekbench score (see %s)%s\n",
                $errorColor,
                $logFile,
                $resetColor
            )
        );
        $tail = array_slice($outputLines, -10);
        foreach ($tail as $line) {
            fwrite(
                STDERR,
                sprintf(
                    "[benchmarkGeekbench][tail] %s\n",
                    rtrim($line, "\r\n")
                )
            );
        }
        return EXIT_ERROR;
    }

    if (!$scoreOnly) {
        fwrite(
            STDOUT,
            sprintf(
                "%s[benchmarkGeekbench]%s Parsed score (multi-core preferred): %s%d%s\n",
                $titleColor,
                $resetColor,
                $scoreColor,
                $score,
                $resetColor
            )
        );
    }

    $payload = benchmarkGeekbenchBuildScorePayload($major, $score, $logFile);
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        \mcxForge\Logger::logStderr("[benchmarkGeekbench] Failed to encode JSON score payload\n");
        return EXIT_ERROR;
    }

    fwrite(STDOUT, sprintf("[benchmarkCPUGeekbench] %s\n", $json));

    return $exitCode === 0 ? EXIT_OK : EXIT_ERROR;
}

/**
 * @return array<string,mixed>
 */
function benchmarkGeekbenchBuildScorePayload(string $major, int $score, string $logFile): array
{
    $major = trim($major) === '5' ? '5' : '6';

    return [
        'schema' => 'mcxForge.cpu-benchmark.v1',
        'benchmark' => 'cpugeekbench',
        'status' => 'ok',
        'metric' => 'geekbench_score',
        'unit' => 'score',
        'score' => $score,
        // Geekbench scores are aggregate; per-thread / single-thread may be added in a future schema bump.
        'threads' => null,
        'durationSeconds' => null,
        'majorVersion' => (int) $major,
        'logFile' => $logFile,
    ];
}

/**
 * @return array{0:string,1:bool,2:bool}
 */
function benchmarkGeekbenchParseArguments(array $argv): array
{
    $major = '6';
    $scoreOnly = false;
    $colorEnabled = true;

    if (getenv('NO_COLOR') !== false) {
        $colorEnabled = false;
    }

    $args = $argv;
    array_shift($args);

    foreach ($args as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            benchmarkGeekbenchPrintHelp();
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

        if (str_starts_with($arg, '--version=')) {
            $value = substr($arg, strlen('--version='));
            $value = trim($value);
            if (!in_array($value, ['5', '6'], true)) {
                \mcxForge\Logger::logStderr("Error: unsupported --version value '{$value}', use 5 or 6.\n");
                exit(EXIT_ERROR);
            }
            $major = $value;
            continue;
        }

        \mcxForge\Logger::logStderr("Error: unrecognized argument '{$arg}'. Use --help for usage.\n");
        exit(EXIT_ERROR);
    }

    return [$major, $scoreOnly, $colorEnabled];
}

function benchmarkGeekbenchPrintHelp(): void
{
    $help = <<<TEXT
Usage: benchmarkCPUGeekbench.php [--version=5|6] [--score-only] [--no-color]

Download and run Geekbench 5 or 6, write results to a dated log file under /tmp,
and print a final programmatic score line:

  {{SCORE:12345}}

Options:
  --version=5       Use Geekbench 5 (default versions are configurable via
                    GEEKBENCH5_VER / GEEKBENCH_VER).
  --version=6       Use Geekbench 6 (default if omitted; configurable via
                    GEEKBENCH6_VER / GEEKBENCH_VER).
  --score-only      Print only the SCORE line, nothing else.
  --no-color        Disable ANSI colors in human output.
  -h, --help        Show this help message.

Notes:
  - Binaries are extracted under /opt/Geekbench-<ver>-Linux.
  - Logs are appended under /tmp/benchmarkGeekbench[5|6]-YYYYMMDD.log.

TEXT;

    echo $help;
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(benchmarkGeekbenchMain($argv));
}
