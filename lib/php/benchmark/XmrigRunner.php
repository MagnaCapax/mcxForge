<?php

declare(strict_types=1);

namespace mcxForge\Benchmark;

final class XmrigRunner
{
    private const DEFAULT_DURATION_SECONDS = 1800;
    private const DEFAULT_PRINT_INTERVAL_SECONDS = 10;

    private const POOL_MONEROOCEAN_HOST = 'gulf.moneroocean.stream';
    private const POOL_MONEROOCEAN_PORT = 20128;

    private const POOL_P2POOL_HOST = 'p2pool.io';
    private const POOL_P2POOL_PORT = 3333;

    private const POOL_P2POOL_MINI_HOST = 'mini.p2pool.io';
    private const POOL_P2POOL_MINI_PORT = 3333;

    private const BENEFICIARY_MONERO = 'monero';
    private const BENEFICIARY_TOR = 'tor';

    // Monero Project donation address (as provided).
    private const ADDRESS_MONERO_PROJECT = '888tNkZrPN6JsEgekjMnABU4TBzc2Dt29EPAvkRxbANsAnjyPbb3iQ1YBRk1UXcdRsiKc9dhwMVgN5S9cQUiyoogDavup3H';

    // Tor Project donation address (as provided).
    private const ADDRESS_TOR_PROJECT = '44AFFq5kSiGBoZ4NMDwYtN18obc8AemS33DBLWs3H7otXft3XjrpDtQGv7SqSsaBYBb98uNbr2VBBEt7f2wfn3RVGQBEP3A';

    public function buildLogFilePath(?\DateTimeImmutable $now = null): string
    {
        $now = $now ?? new \DateTimeImmutable('now');
        $date = $now->format('Ymd');

        return sprintf('/tmp/benchmarkCPUXmrig-%s.log', $date);
    }

    public function getDefaultDurationSeconds(): int
    {
        return self::DEFAULT_DURATION_SECONDS;
    }

    /**
     * @return array{host:string,port:int}
     */
    public function resolvePool(string $pool): array
    {
        $pool = trim(strtolower($pool));
        if ($pool === '' || $pool === 'moneroocean') {
            return [
                'host' => self::POOL_MONEROOCEAN_HOST,
                'port' => self::POOL_MONEROOCEAN_PORT,
            ];
        }

        if ($pool === 'p2pool') {
            return [
                'host' => self::POOL_P2POOL_HOST,
                'port' => self::POOL_P2POOL_PORT,
            ];
        }

        if ($pool === 'p2pool-mini' || $pool === 'p2pool_mini') {
            return [
                'host' => self::POOL_P2POOL_MINI_HOST,
                'port' => self::POOL_P2POOL_MINI_PORT,
            ];
        }

        throw new \InvalidArgumentException("Unsupported pool '{$pool}'. Use moneroocean, p2pool, or p2pool-mini.");
    }

    public function resolveBeneficiaryAddress(?string $explicitAddress, string $beneficiary): string
    {
        $candidate = $explicitAddress !== null ? trim($explicitAddress) : '';
        if ($candidate !== '') {
            return $candidate;
        }

        $normalized = trim(strtolower($beneficiary));
        if ($normalized === '' || $normalized === self::BENEFICIARY_MONERO) {
            return self::ADDRESS_MONERO_PROJECT;
        }

        if ($normalized === self::BENEFICIARY_TOR) {
            return self::ADDRESS_TOR_PROJECT;
        }

        // Fail forward: treat unknown beneficiary keywords as Monero Project donations.
        return self::ADDRESS_MONERO_PROJECT;
    }

    public function resolveBinaryPath(): string
    {
        $override = getenv('XMRIG_BIN');
        if ($override !== false && $override !== '') {
            $path = $override;
            if (is_file($path) && is_executable($path)) {
                return $path;
            }
        }

        $result = shell_exec('command -v xmrig 2>/dev/null');
        if (is_string($result)) {
            $path = trim($result);
            if ($path !== '' && is_file($path) && is_executable($path)) {
                return $path;
            }
        }

        throw new \RuntimeException('xmrig executable not found. Install xmrig or set XMRIG_BIN.');
    }

    public function buildCommand(
        string $binaryPath,
        int $durationSeconds,
        string $pool,
        string $beneficiary,
        ?string $explicitAddress,
        string $rigId = 'mcxForge',
        int $printIntervalSeconds = self::DEFAULT_PRINT_INTERVAL_SECONDS
    ): string {
        $durationSeconds = max(0, $durationSeconds);

        $poolConfig = $this->resolvePool($pool);
        $address = $this->resolveBeneficiaryAddress($explicitAddress, $beneficiary);

        $url = sprintf('%s:%d', $poolConfig['host'], $poolConfig['port']);

        $baseParts = [
            escapeshellarg($binaryPath),
            '-o',
            escapeshellarg($url),
            '-u',
            escapeshellarg($address),
        ];

        $rigIdTrimmed = trim($rigId);
        if ($rigIdTrimmed !== '') {
            $baseParts[] = '--rig-id=' . escapeshellarg($rigIdTrimmed);
        }

        if ($printIntervalSeconds > 0) {
            $baseParts[] = '--print-time=' . (int) $printIntervalSeconds;
        }

        $command = implode(' ', $baseParts) . ' 2>&1';

        if ($durationSeconds > 0) {
            if ($this->commandExists('timeout')) {
                $command = sprintf(
                    'timeout %d %s',
                    $durationSeconds,
                    $command
                );
            }
        }

        return $command;
    }

    /**
     * @param array<int,string> $lines
     * @return array<int,float>
     */
    public function parseHashrateSamples(array $lines): array
    {
        $samples = [];

        foreach ($lines as $line) {
            $speed = $this->parseSpeedLine($line);
            if ($speed !== null && $speed > 0) {
                $samples[] = $speed;
            }
        }

        return $samples;
    }

    /**
     * @param array<int,float> $samples
     */
    public function computeAverageHashrate(array $samples): ?float
    {
        $filtered = [];
        foreach ($samples as $value) {
            if (!is_finite($value) || $value <= 0) {
                continue;
            }
            $filtered[] = $value;
        }

        if (count($filtered) === 0) {
            return null;
        }

        $sum = array_sum($filtered);
        $count = count($filtered);

        return $count > 0 ? $sum / $count : null;
    }

    private function parseSpeedLine(string $line): ?float
    {
        $stripped = $this->stripAnsi($line);
        $trimmed = trim($stripped);

        if ($trimmed === '') {
            return null;
        }

        if (stripos($trimmed, 'speed') === false || stripos($trimmed, 'H/s') === false) {
            return null;
        }

        // Typical xmrig format:
        // [timestamp] speed 10s/60s/15m  1234.0  1100.0  900.0  H/s
        if (preg_match(
            '/speed\s+[0-9a-zA-Z\/\-_]+\s+([0-9]+(?:\.[0-9]+)?)\s+([0-9]+(?:\.[0-9]+)?)(?:\s+([0-9]+(?:\.[0-9]+)?))?\s+H\/s/i',
            $trimmed,
            $matches
        ) === 1) {
            $tenSeconds = isset($matches[1]) ? (float) $matches[1] : null;
            $sixtySeconds = isset($matches[2]) ? (float) $matches[2] : null;
            $fifteenMinutes = isset($matches[3]) ? (float) $matches[3] : null;

            if ($fifteenMinutes !== null && $fifteenMinutes > 0) {
                return $fifteenMinutes;
            }
            if ($sixtySeconds !== null && $sixtySeconds > 0) {
                return $sixtySeconds;
            }
            if ($tenSeconds !== null && $tenSeconds > 0) {
                return $tenSeconds;
            }

            return null;
        }

        return null;
    }

    private function stripAnsi(string $input): string
    {
        return (string) preg_replace('/\x1B\[[0-9;]*[A-Za-z]/', '', $input);
    }

    private function commandExists(string $name): bool
    {
        $result = shell_exec(sprintf('command -v %s 2>/dev/null', escapeshellarg($name)));
        return is_string($result) && trim($result) !== '';
    }
}
