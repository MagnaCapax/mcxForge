<?php

declare(strict_types=1);

namespace mcxForge\Benchmark;

final class SysbenchCpuRunner
{
    public function buildLogFilePath(?\DateTimeImmutable $now = null): string
    {
        $now = $now ?? new \DateTimeImmutable('now');
        $date = $now->format('Ymd');

        return sprintf('/tmp/benchmarkCpuSysbench-%s.log', $date);
    }

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
     * @param array<int,string> $lines
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

