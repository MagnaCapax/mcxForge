<?php

declare(strict_types=1);

namespace mcxForge\Benchmark;

/**
 * SysbenchMemoryRunner builds and executes sysbench memory workloads and
 * parses their throughput metrics for use in mcxForge benchmarks.
 */
final class SysbenchMemoryRunner
{
    /**
     * Build a deterministic log file path for memory sysbench runs in /tmp.
     *
     * @param \DateTimeImmutable|null $now Optional time source for testability.
     * @return string Absolute path to the log file.
     */
    public function buildLogFilePath(?\DateTimeImmutable $now = null): string
    {
        $now = $now ?? new \DateTimeImmutable('now');
        $date = $now->format('Ymd');

        return sprintf('/tmp/benchmarkMemorySysbench-%s.log', $date);
    }

    /**
     * Build a sysbench memory command line with the requested parameters.
     *
     * @param int    $threads       Number of worker threads to start.
     * @param int    $totalSizeGiB  Total memory size to exercise in GiB.
     * @param int    $blockSizeKiB  Block size to use in KiB.
     * @param string $accessMode    Access mode (seq or rnd).
     * @param string $operation     Operation type (read, write, or rwr).
     * @return string Shell command suitable for execution.
     */
    public function buildCommand(
        int $threads,
        int $totalSizeGiB,
        int $blockSizeKiB,
        string $accessMode,
        string $operation
    ): string {
        $threads = max(1, $threads);
        $totalSizeGiB = max(1, $totalSizeGiB);
        $blockSizeKiB = max(1, $blockSizeKiB);

        $accessMode = $accessMode === 'rnd' ? 'rnd' : 'seq';
        $operation = in_array($operation, ['read', 'write', 'rwr'], true) ? $operation : 'read';

        return sprintf(
            'sysbench memory --memory-total-size=%dG --memory-block-size=%dK --memory-access-mode=%s --memory-oper=%s --threads=%d run 2>&1',
            $totalSizeGiB,
            $blockSizeKiB,
            $accessMode,
            $operation,
            $threads
        );
    }

    /**
     * Parse sysbench memory output and extract MiB/sec throughput.
     *
     * @param array<int,string> $lines Raw sysbench output lines.
     * @return float|null Parsed throughput in MiB/sec, or null when missing.
     */
    public function parseThroughput(array $lines): ?float
    {
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (stripos($trimmed, 'transferred') === false) {
                continue;
            }

            // Typical sysbench memory output:
            // transferred ( 12345.67 MiB/sec)
            if (preg_match('/transferred.*\(\s*([0-9]+(?:\.[0-9]+)?)\s*MiB\/sec\)/i', $trimmed, $matches) === 1) {
                return (float) $matches[1];
            }
        }

        return null;
    }

    /**
     * Run a sysbench memory workload and capture its output lines.
     *
     * @param int      $threads       Number of worker threads to start.
     * @param int      $totalSizeGiB  Total memory size to exercise in GiB.
     * @param int      $blockSizeKiB  Block size to use in KiB.
     * @param string   $accessMode    Access mode (seq or rnd).
     * @param string   $operation     Operation type (read, write, or rwr).
     * @param int|null $exitCode      Populated with the sysbench exit code.
     * @return array<int,string> Collected output lines from sysbench.
     */
    public function run(
        int $threads,
        int $totalSizeGiB,
        int $blockSizeKiB,
        string $accessMode,
        string $operation,
        ?int &$exitCode = null
    ): array {
        if (!$this->commandExists('sysbench')) {
            $exitCode = 127;
            return ['sysbench not found in PATH'];
        }

        $cmd = $this->buildCommand($threads, $totalSizeGiB, $blockSizeKiB, $accessMode, $operation);
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
