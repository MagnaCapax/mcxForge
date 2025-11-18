#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * memoryTestMemtester.php
 *
 * Run multiple memtester instances in parallel to exercise a large fraction of RAM.
 * This is a QC / burn-in tool, not a benchmark.
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

use mcxForge\Benchmark\CPUInfo;

function memoryTestMemtesterMain(array $argv): int
{
    [$threads, $percent, $passes, $force, $colorEnabled] = memoryTestMemtesterParseArguments($argv);

    $titleColor = $colorEnabled ? "\033[1;34m" : '';
    $errorColor = $colorEnabled ? "\033[1;31m" : '';
    $resetColor = $colorEnabled ? "\033[0m" : '';

    $memTotalMiB = memoryTestMemtesterDetectTotalMemMiB();
    if ($memTotalMiB === null || $memTotalMiB <= 0) {
        fwrite(STDERR, sprintf("%s[memoryTestMemtester] Error: could not detect total memory from /proc/meminfo%s\n", $errorColor, $resetColor));
        return EXIT_ERROR;
    }

    if ($percent >= 100 && !$force) {
        fwrite(
            STDERR,
            sprintf(
                "%s[memoryTestMemtester] Refusing to target 100%% of RAM without --force (detected %d MiB).%s\n",
                $errorColor,
                $memTotalMiB,
                $resetColor
            )
        );
        return EXIT_ERROR;
    }

    $targetMiB = (int) floor($memTotalMiB * ($percent / 100.0));
    $threads = max(2, $threads);
    $perThreadMiB = (int) floor($targetMiB / $threads);

    if ($perThreadMiB < 1) {
        fwrite(
            STDERR,
            sprintf(
                "%s[memoryTestMemtester] Error: requested configuration yields <1 MiB per thread (threads=%d, percent=%d, totalMiB=%d).%s\n",
                $errorColor,
                $threads,
                $percent,
                $memTotalMiB,
                $resetColor
            )
        );
        return EXIT_ERROR;
    }

    $logFile = memoryTestMemtesterBuildLogFilePath();

    fwrite(
        STDOUT,
        sprintf(
            "%s[memoryTestMemtester]%s Starting memtester: %d thread(s), %d MiB per thread (~%d%% of %d MiB), %d pass(es). Log file: %s%s\n",
            $titleColor,
            $resetColor,
            $threads,
            $perThreadMiB,
            $percent,
            $memTotalMiB,
            $passes,
            $logFile,
            $resetColor
        )
    );

    $procs = [];
    for ($i = 0; $i < $threads; $i++) {
        $cmd = sprintf('memtester %dM %d 2>&1', $perThreadMiB, $passes);
        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes);
        if (!\is_resource($proc)) {
            fwrite(
                STDERR,
                sprintf(
                    "%s[memoryTestMemtester] Failed to start memtester process %d%s\n",
                    $errorColor,
                    $i,
                    $resetColor
                )
            );
            continue;
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $procs[] = [
            'proc' => $proc,
            'pipes' => $pipes,
            'index' => $i,
        ];
    }

    $active = count($procs);
    if ($active === 0) {
        fwrite(STDERR, sprintf("%s[memoryTestMemtester] Error: no memtester processes started%s\n", $errorColor, $resetColor));
        return EXIT_ERROR;
    }

    $failed = 0;
    $logHandle = fopen($logFile, 'ab');
    if ($logHandle === false) {
        fwrite(STDERR, sprintf("%s[memoryTestMemtester] Error: could not open log file %s%s\n", $errorColor, $logFile, $resetColor));
        return EXIT_ERROR;
    }

    while ($active > 0) {
        foreach ($procs as $idx => &$entry) {
            $proc = $entry['proc'];
            $pipes = $entry['pipes'];
            $index = $entry['index'];

            $status = proc_get_status($proc);
            foreach ([1, 2] as $fd) {
                $chunk = stream_get_contents($pipes[$fd]);
                if ($chunk !== false && $chunk !== '') {
                    fwrite($logHandle, sprintf("[thread-%d][fd-%d] %s", $index, $fd, $chunk));
                }
            }

            if ($status['running'] === false) {
                $code = $status['exitcode'];
                proc_close($proc);
                fclose($pipes[1]);
                fclose($pipes[2]);
                unset($procs[$idx]);
                $active--;

                if ($code !== 0) {
                    $failed++;
                    fwrite(
                        STDERR,
                        sprintf(
                            "%s[memoryTestMemtester] memtester thread %d exited with code %d%s\n",
                            $errorColor,
                            $index,
                            $code,
                            $resetColor
                        )
                    );
                }
            }
        }
        unset($entry);

        if ($active > 0) {
            usleep(200_000);
        }
    }

    fclose($logHandle);

    $status = $failed === 0 ? 'ok' : 'failed';

    fwrite(
        STDOUT,
        sprintf(
            "%s[memoryTestMemtester]%s Completed memtester run: %d thread(s), %d failure(s).%s\n",
            $titleColor,
            $resetColor,
            $threads,
            $failed,
            $resetColor
        )
    );

    $payload = memoryTestMemtesterBuildResultPayload($threads, $percent, $passes, $status, $logFile);
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json !== false) {
        fwrite(STDOUT, sprintf("[memoryTestMemtester] %s\n", $json));
    } else {
        fwrite(STDERR, sprintf("%s[memoryTestMemtester] Failed to encode JSON result payload%s\n", $errorColor, $resetColor));
    }

    return $failed === 0 ? EXIT_OK : EXIT_ERROR;
}

/**
 * @return array{0:int,1:int,2:int,3:bool,4:bool}
 */
function memoryTestMemtesterParseArguments(array $argv): array
{
    $threads = CPUInfo::detectLogicalCores();
    $percent = 90;
    $passes = 1;
    $force = false;
    $colorEnabled = true;

    if (getenv('NO_COLOR') !== false) {
        $colorEnabled = false;
    }

    $args = $argv;
    array_shift($args);

    foreach ($args as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            memoryTestMemtesterPrintHelp();
            exit(EXIT_OK);
        }

        if ($arg === '--force') {
            $force = true;
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

        if (str_starts_with($arg, '--passes=')) {
            $value = trim(substr($arg, strlen('--passes=')));
            if (!ctype_digit($value) || (int) $value <= 0) {
                fwrite(STDERR, "Error: invalid --passes value '{$value}'\n");
                exit(EXIT_ERROR);
            }
            $passes = (int) $value;
            continue;
        }

        fwrite(STDERR, "Error: unrecognized argument '{$arg}'. Use --help for usage.\n");
        exit(EXIT_ERROR);
    }

    return [$threads, $percent, $passes, $force, $colorEnabled];
}

function memoryTestMemtesterPrintHelp(): void
{
    $help = <<<TEXT
Usage: memoryTestMemtester.php [--threads=N] [--percent=NN] [--passes=N] [--force] [--no-color]

Run multiple memtester instances in parallel to exercise a large fraction of RAM.

Options:
  --threads=N   Number of memtester processes (default: detected logical cores, minimum 2).
  --percent=NN  Percentage of total RAM to target across all threads (default: 90).
  --passes=N    Number of memtester passes per process (default: 1).
  --force       Required when --percent >= 100 to allow full-RAM tests.
  --no-color    Disable ANSI colors in human output.
  -h, --help    Show this help message.

Notes:
  - This is a QC tool, not a benchmark. Any memtester failure is treated as a RAM failure.
  - Raw output is appended to /tmp/memoryTestMemtester-YYYYMMDD.log.

TEXT;

    echo $help;
}

function memoryTestMemtesterDetectTotalMemMiB(): ?int
{
    $meminfo = @file('/proc/meminfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($meminfo === false) {
        return null;
    }

    foreach ($meminfo as $line) {
        if (strpos($line, 'MemTotal:') === 0) {
            $parts = preg_split('/\s+/', trim($line));
            foreach ($parts as $part) {
                if (ctype_digit($part)) {
                    $kib = (int) $part;
                    return (int) floor($kib / 1024);
                }
            }
        }
    }

    return null;
}

function memoryTestMemtesterBuildLogFilePath(?\DateTimeImmutable $now = null): string
{
    $now = $now ?? new \DateTimeImmutable('now');
    $date = $now->format('Ymd');

    return sprintf('/tmp/memoryTestMemtester-%s.log', $date);
}

/**
 * @return array<string,mixed>
 */
function memoryTestMemtesterBuildResultPayload(
    int $threads,
    int $percent,
    int $passes,
    string $status,
    string $logFile
): array {
    return [
        'schema' => 'mcxForge.memory-test.v1',
        'benchmark' => 'memtester',
        'status' => $status,
        'threads' => $threads,
        'percentOfRam' => $percent,
        'passes' => $passes,
        'logFile' => $logFile,
    ];
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(memoryTestMemtesterMain($argv));
}

