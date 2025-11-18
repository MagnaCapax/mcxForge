<?php

declare(strict_types=1);

namespace mcxForge\Benchmark;

/**
 * SysbenchCPURunner builds and executes CPU-bound sysbench workloads and
 * parses their events-per-second score for mcxForge benchmarks.
 */
final class SysbenchCPURunner
{
    /**
     * Build a deterministic log file path for CPU sysbench runs in /tmp.
     *
     * @param \DateTimeImmutable|null $now Optional time source for testability.
     * @return string Absolute path to the log file.
     */
    public function buildLogFilePath(?\DateTimeImmutable $now = null): string
    {
        $now = $now ?? new \DateTimeImmutable('now');
        $date = $now->format('Ymd');

        return sprintf('/tmp/benchmarkCPUSysbench-%s.log', $date);
    }

    /**
     * Build a sysbench cpu command line with the requested thread count and duration.
     *
     * @param int $threads         Number of worker threads to start.
     * @param int $durationSeconds Duration of the run in seconds.
     * @return string Shell command suitable for execution.
     */
    public function buildCommand(int $threads, int $durationSeconds): string
    {
        $threads = max(1, $threads);
        $durationSeconds = max(1, $durationSeconds);

        return sprintf(
            'sysbench cpu --threads=%d --time=%d run 2>&1',
            $threads,
            $durationSeconds
        );
    }

    /**
     * Parse sysbench CPU output and extract the events-per-second score.
     *
     * @param array<int,string> $lines Raw sysbench output lines.
     * @return float|null Parsed events-per-second score, or null when missing.
     */
    public function parseScore(array $lines): ?float
    {
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (stripos($trimmed, 'events per second') === false) {
                continue;
            }

            if (preg_match('/events per second:\s*([0-9]+(?:\.[0-9]+)?)/i', $trimmed, $matches) === 1) {
                return (float) $matches[1];
            }
        }

        return null;
    }

    /**
     * Run a sysbench CPU workload and capture its output lines.
     *
     * @param int      $threads         Number of worker threads to start.
     * @param int      $durationSeconds Duration of the run in seconds.
     * @param int|null $exitCode        Populated with the sysbench exit code.
     * @return array<int,string> Collected output lines from sysbench.
     */
    public function run(int $threads, int $durationSeconds, ?int &$exitCode = null): array
    {
        if (!$this->commandExists('sysbench')) {
            $exitCode = 127;
            return ['sysbench not found in PATH'];
        }

        $cmd = $this->buildCommand($threads, $durationSeconds);
        $output = [];
        $code = 0;
        exec($cmd, $output, $code);
        $exitCode = $code;

        return $output;
    }

    private function commandExists(string $name): bool
    {
        $result = shell_exec(sprintf('command -v %s 2>/dev/null', escapeshellarg($name)));
        return is_string($result) && trim($result) !== '';
    }
}
