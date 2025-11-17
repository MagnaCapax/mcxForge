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

function benchmarkCpuSysbenchMain(array $argv): int
{
    [$duration, $threads, $scoreOnly, $colorEnabled] = benchmarkCpuSysbenchParseArguments($argv);

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
                "%s[benchmarkCpuSysbench]%s Running sysbench cpu for %ds on %d thread(s)...%s\n",
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
                    "%s[benchmarkCpuSysbench] sysbench exited with code %d%s\n",
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
                    "%s[benchmarkCpuSysbench] Warning: could not parse sysbench events per second%s\n",
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
                "%s[benchmarkCpuSysbench]%s Log file: %s\n",
                $titleColor,
                $resetColor,
                $logFile
            )
        );
        fwrite(
            STDOUT,
            sprintf(
                "%s[benchmarkCpuSysbench]%s Parsed score: %s%.2f%s events/s (%.2f per thread)\n",
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
function benchmarkCpuSysbenchParseArguments(array $argv): array
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
            benchmarkCpuSysbenchPrintHelp();
            exit(EXIT_OK);
