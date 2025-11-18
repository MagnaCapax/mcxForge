#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * benchmarkCPUXmrig.php
 *
 * Run an xmrig-based Monero mining workload as a CPU benchmark and
 * qualification tool. By default this:
 *
 *  - Connects to a public Monero mining pool using a donation address.
 *  - Runs for 30 minutes, relying on xmrig's periodic speed output.
 *  - Parses speed lines to compute an average hash rate in H/s.
 *  - Appends raw output to /tmp/benchmarkCPUXmrig-YYYYMMDD.log.
 *  - Emits a final programmatic score line:
 *
 *      {{SCORE:<average_hashrate_hs>}}
 *
 * When --duration=0 is used, xmrig runs until interrupted and the score
 * is computed from the available log lines on exit.
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
require_once __DIR__ . '/../lib/php/benchmark/XmrigRunner.php';

use mcxForge\Benchmark\CPUInfo;
use mcxForge\Benchmark\XmrigRunner;

function benchmarkCPUXmrigMain(array $argv): int
{
    [$duration, $pool, $address, $scoreOnly, $colorEnabled] = benchmarkCPUXmrigParseArguments($argv);

    $runner = new XmrigRunner();
    $logFile = $runner->buildLogFilePath();

    $titleColor = $colorEnabled ? "\033[1;34m" : '';
    $scoreColor = $colorEnabled ? "\033[1;32m" : '';
    $errorColor = $colorEnabled ? "\033[1;31m" : '';
    $resetColor = $colorEnabled ? "\033[0m" : '';

    if (!$scoreOnly) {
        $modeLabel = $duration > 0
            ? sprintf('~%d minute benchmark', (int) round($duration / 60))
            : 'indefinite burn-in (until interrupted)';

        $addressLabel = $address === null ? 'donation address pool' : 'explicit address';

        fwrite(
            STDOUT,
            sprintf(
                "%s[benchmarkCPUXmrig]%s Starting xmrig %s on pool '%s' using %s%s\n",
                $titleColor,
                $resetColor,
                $modeLabel,
                $pool,
                $addressLabel,
                $resetColor
            )
        );
        if ($duration === 0) {
            fwrite(
                STDOUT,
                sprintf(
                    "%s[benchmarkCPUXmrig]%s Hint: run under nohup/tmux/systemd for unattended QA loops%s\n",
                    $titleColor,
                    $resetColor,
                    $resetColor
                )
            );
        }
    }

    try {
        $binaryPath = $runner->resolveBinaryPath();
    } catch (\Throwable $e) {
        fwrite(
            STDERR,
            sprintf(
                "%s[benchmarkCPUXmrig] Error: %s%s\n",
                $errorColor,
                $e->getMessage(),
                $resetColor
            )
        );
        return EXIT_ERROR;
    }

    $command = $runner->buildCommand($binaryPath, $duration, $pool, $address);

    $output = [];
    $exitCode = 0;
    exec($command, $output, $exitCode);

    $text = implode(PHP_EOL, $output) . PHP_EOL;
    file_put_contents($logFile, $text, FILE_APPEND);

    $samples = $runner->parseHashrateSamples($output);
    $average = $runner->computeAverageHashrate($samples);

    if ($average === null) {
        if (!$scoreOnly) {
            fwrite(
                STDERR,
                sprintf(
                    "%s[benchmarkCPUXmrig] Warning: could not parse xmrig hashrate from output%s\n",
                    $errorColor,
                    $resetColor
                )
            );
        }

        return EXIT_ERROR;
    }

    $threads = CPUInfo::detectLogicalCores();
    $perThread = $threads > 0 ? $average / $threads : $average;

    if (!$scoreOnly) {
        fwrite(
            STDOUT,
            sprintf(
                "%s[benchmarkCPUXmrig]%s Log file: %s\n",
                $titleColor,
                $resetColor,
                $logFile
            )
        );
        fwrite(
            STDOUT,
            sprintf(
                "%s[benchmarkCPUXmrig]%s Parsed average hash rate: %s%.2f%s H/s (%.2f H/s per thread, %d thread(s))\n",
                $titleColor,
                $resetColor,
                $scoreColor,
                $average,
                $resetColor,
                $perThread,
                $threads
            )
        );
        if ($exitCode !== 0) {
            fwrite(
                STDERR,
                sprintf(
                    "%s[benchmarkCPUXmrig] xmrig exited with code %d (timeout or error)%s\n",
                    $errorColor,
                    $exitCode,
                    $resetColor
                )
            );
        }
    }

    fwrite(STDOUT, sprintf("[benchmarkCPUXmrig] {{SCORE:%.2f}}\n", $average));

    return $exitCode === 0 ? EXIT_OK : EXIT_ERROR;
}

/**
 * @return array{0:int,1:string,2:?(string),3:bool,4:bool}
 */
function benchmarkCPUXmrigParseArguments(array $argv): array
{
    $runner = new XmrigRunner();
    $duration = $runner->getDefaultDurationSeconds();
    $pool = 'moneroocean';
    $address = null;
    $scoreOnly = false;
    $colorEnabled = true;

    if (getenv('NO_COLOR') !== false) {
        $colorEnabled = false;
    }

    $args = $argv;
    array_shift($args);

    foreach ($args as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            benchmarkCPUXmrigPrintHelp();
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
            if (!ctype_digit($value)) {
                fwrite(STDERR, "Error: invalid --duration value '{$value}'\n");
                exit(EXIT_ERROR);
            }
            $duration = (int) $value;
            if ($duration < 0) {
                fwrite(STDERR, "Error: --duration must be >= 0\n");
                exit(EXIT_ERROR);
            }
            continue;
        }

        if (str_starts_with($arg, '--pool=')) {
            $value = substr($arg, strlen('--pool='));
            $value = trim($value);
            if ($value === '') {
                fwrite(STDERR, "Error: --pool must not be empty\n");
                exit(EXIT_ERROR);
            }
            $pool = $value;
            continue;
        }

        if (str_starts_with($arg, '--address=')) {
            $value = substr($arg, strlen('--address='));
            $value = trim($value);
            if ($value === '') {
                fwrite(STDERR, "Error: --address must not be empty when provided\n");
                exit(EXIT_ERROR);
            }
            $address = $value;
            continue;
        }

        fwrite(STDERR, "Error: unrecognized argument '{$arg}'. Use --help for usage.\n");
        exit(EXIT_ERROR);
    }

    return [$duration, $pool, $address, $scoreOnly, $colorEnabled];
}

function benchmarkCPUXmrigPrintHelp(): void
{
    $help = <<<TEXT
Usage: benchmarkCPUXmrig.php [--duration=SECONDS] [--pool=NAME] [--address=XMR] [--score-only] [--no-color]

Run an xmrig-based Monero mining workload as a CPU benchmark and QA tool.

By default, this command:
  - Connects to a Monero mining pool using a donation address selected at random from a small pool.
  - Runs for 30 minutes, then stops.
  - Logs xmrig output under /tmp/benchmarkCPUXmrig-YYYYMMDD.log.
  - Parses speed lines to compute an average hash rate in H/s.
  - Emits a final programmatic score line:

    {{SCORE:<average_hashrate_hs>}}

Options:
  --duration=SECONDS   How long to run xmrig (default: 1800). Use 0 for
                       indefinite burn-in until interrupted.
  --pool=NAME          Mining pool profile to use:
                         - moneroocean   (default; gulf.moneroocean.stream:20128)
                         - p2pool        (p2pool.io:3333)
                         - p2pool-mini   (mini.p2pool.io:3333)
  --address=XMR        Explicit Monero address to mine to. When provided,
                       this overrides the built-in donation address pool.
  --score-only         Print only the SCORE line, nothing else.
  --no-color           Disable ANSI colors in human output.
  -h, --help           Show this help message.

Notes:
  - For indefinite QA loops, use --duration=0 and run this tool under
    nohup/tmux/screen/systemd so it can be supervised externally.
  - xmrig must be installed in PATH or XMRIG_BIN must point to it.

TEXT;

    echo $help;
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(benchmarkCPUXmrigMain($argv));
}
