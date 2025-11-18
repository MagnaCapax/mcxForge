<?php

declare(strict_types=1);

namespace mcxForge\Benchmark;

final class MemoryStressNgRunner
{
    public function buildLogFilePath(?\DateTimeImmutable $now = null): string
    {
        $now = $now ?? new \DateTimeImmutable('now');
        $date = $now->format('Ymd');

        return sprintf('/tmp/benchmarkMemoryStressNg-%s.log', $date);
    }

    public function buildCommand(int $workers, int $durationSeconds, int $percentOfRam): string
    {
        $workers = max(1, $workers);
        $durationSeconds = max(1, $durationSeconds);
        $percentOfRam = max(1, min(100, $percentOfRam));

        return sprintf(
            'stress-ng --vm %d --vm-bytes %d%% --vm-method all --vm-populate --vm-keep --metrics-brief --timeout %ds 2>&1',
            $workers,
            $percentOfRam,
            $durationSeconds
        );
    }

    /**
     * Heuristic throughput parser for stress-ng vm metrics.
     *
     * @param array<int,string> $lines
     */
    public function parseScore(array $lines): ?float
    {
        $best = null;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            // Newer stress-ng versions emit a header row and a data row for vm metrics, e.g.:
            // stress-ng: metrc: [1477] stressor       bogo ops real time  usr time  sys time   bogo ops/s     bogo ops/s
            // stress-ng: metrc: [1477] vm              697476    120.00    479.71      0.10      5812.26        1453.65
            if (stripos($trimmed, 'metrc:') === false) {
                continue;
            }
            if (stripos($trimmed, 'vm') === false) {
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

            // Expect at least two numeric columns at the end: real-time and CPU-time bogo ops/s.
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

    public function run(int $workers, int $durationSeconds, int $percentOfRam, ?int &$exitCode = null): array
    {
        if (!$this->commandExists('stress-ng')) {
            $exitCode = 127;
            return ['stress-ng not found in PATH'];
        }

        $cmd = $this->buildCommand($workers, $durationSeconds, $percentOfRam);
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

