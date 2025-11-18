<?php

declare(strict_types=1);

namespace mcxForge\Benchmark;

final class SysbenchMemoryRunner
{
    public function buildLogFilePath(?\DateTimeImmutable $now = null): string
    {
        $now = $now ?? new \DateTimeImmutable('now');
        $date = $now->format('Ymd');

        return sprintf('/tmp/benchmarkMemorySysbench-%s.log', $date);
    }

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
     * @param array<int,string> $lines
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

