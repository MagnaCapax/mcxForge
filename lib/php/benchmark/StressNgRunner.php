<?php

declare(strict_types=1);

namespace mcxForge\Benchmark;

final class StressNgRunner
{
    public function buildLogFilePath(?\DateTimeImmutable $now = null): string
    {
        $now = $now ?? new \DateTimeImmutable('now');
        $date = $now->format('Ymd');

        return sprintf('/tmp/benchmarkCPUStressNg-%s.log', $date);
    }

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
     * @param array<int,string> $lines
     */
    public function parseScore(array $lines): ?float
    {
        $best = null;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (stripos($trimmed, 'cpu ') === false && stripos($trimmed, 'cpu-') === false) {
                continue;
            }
            if (stripos($trimmed, 'bogo ops/s') === false) {
                continue;
            }

            if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*bogo\s*ops\/s/i', $trimmed, $matches) === 1) {
                $value = (float) $matches[1];
                if ($best === null || $value > $best) {
                    $best = $value;
                }
            }
        }

        return $best;
    }

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
