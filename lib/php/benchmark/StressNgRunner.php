<?php

declare(strict_types=1);

namespace mcxForge\Benchmark;

/**
 * StressNgRunner builds and executes CPU-focused stress-ng workloads and
 * extracts a representative operations-per-second score from their output.
 */
final class StressNgRunner
{
    /**
     * Build a deterministic log file path for CPU stress-ng runs in /tmp.
     *
     * @param \DateTimeImmutable|null $now Optional time source for testability.
     * @return string Absolute path to the log file.
     */
    public function buildLogFilePath(?\DateTimeImmutable $now = null): string
    {
        $now = $now ?? new \DateTimeImmutable('now');
        $date = $now->format('Ymd');

        return sprintf('/tmp/benchmarkCPUStressNg-%s.log', $date);
    }

    /**
     * Build a stress-ng command line that exercises CPU cores with metrics enabled.
     *
     * @param int $cpuCount        Number of logical CPU workers to start.
     * @param int $durationSeconds Duration of the run in seconds.
     * @return string Shell command suitable for execution.
     */
    public function buildCommand(int $cpuCount, int $durationSeconds): string
    {
        $cpuCount = max(1, $cpuCount);
        $durationSeconds = max(1, $durationSeconds);

        return sprintf(
            'stress-ng --cpu %d --cpu-method all --metrics-brief --timeout %ds 2>&1',
            $cpuCount,
            $durationSeconds
        );
    }

    /**
     * Parse stress-ng CPU metrics and return a representative bogo ops per second score.
     *
     * @param array<int,string> $lines Raw stress-ng output lines.
     * @return float|null Best detected real-time bogo ops per second, or null when missing.
     */
    public function parseScore(array $lines): ?float
    {
        $best = null;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            // Focus on metric lines for the CPU stressor. Newer stress-ng versions emit a header
            // row describing columns and then a data row with numeric fields only.
            if (stripos($trimmed, 'cpu') === false) {
                continue;
            }
            if (stripos($trimmed, 'metrc:') === false && stripos($trimmed, 'metric:') === false) {
                continue;
            }

            $tokens = preg_split('/\s+/', $trimmed);
            if (!is_array($tokens)) {
                continue;
            }

            $numbers = [];
            foreach ($tokens as $token) {
                if (preg_match('/^-?(?:[0-9]+(?:\.[0-9]+)?)$/', $token)) {
                    $numbers[] = (float) $token;
                }
            }

            // Expect at least two numeric columns at the end: real-time bogo ops/s and
            // CPU-time bogo ops/s. We prefer the real-time figure (second-to-last).
            $count = count($numbers);
            if ($count < 2) {
                continue;
            }

            $candidate = $numbers[$count - 2];
            if ($best === null || $candidate > $best) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * Run a stress-ng CPU workload and capture its output lines.
     *
     * @param int      $cpuCount        Number of logical CPU workers to start.
     * @param int      $durationSeconds Duration of the run in seconds.
     * @param int|null $exitCode        Populated with the stress-ng exit code.
     * @return array<int,string> Collected output lines from stress-ng.
     */
    public function run(int $cpuCount, int $durationSeconds, ?int &$exitCode = null): array
    {
        if (!$this->commandExists('stress-ng')) {
            $exitCode = 127;
            return ['stress-ng not found in PATH'];
        }

        $cmd = $this->buildCommand($cpuCount, $durationSeconds);
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
