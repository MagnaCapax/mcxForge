<?php

declare(strict_types=1);

namespace mcxForge\Benchmark;

/**
 * GeekbenchRunner
 *
 * Helper for downloading, running, and parsing Geekbench results.
 * This class focuses on orchestration and parsing; callers are responsible
 * for presenting output and handling errors according to mcxForge rails.
 */
final class GeekbenchRunner
{
    /**
     * @var array<string,string>
     */
    private const DEFAULT_VERSION_BY_MAJOR = [
        '5' => '5.5.1',
        '6' => '6.5.0',
    ];

    public function resolveMajorVersion(string $arg): string
    {
        $value = trim($arg);
        if ($value === '' || $value === '6') {
            return '6';
        }
        if ($value === '5') {
            return '5';
        }

        throw new \InvalidArgumentException("Unsupported Geekbench major version '{$arg}', use 5 or 6.");
    }

    public function resolveVersionString(string $major): string
    {
        $major = $this->resolveMajorVersion($major);

        $envKey = $major === '5' ? 'GEEKBENCH5_VER' : 'GEEKBENCH6_VER';
        $specific = getenv($envKey);
        if ($specific !== false && $specific !== '') {
            return $specific;
        }

        $common = getenv('GEEKBENCH_VER');
        if ($common !== false && $common !== '') {
            return $common;
        }

        return self::DEFAULT_VERSION_BY_MAJOR[$major];
    }

    public function buildDownloadUrl(string $versionString): string
    {
        $major = $this->determineMajorFromVersionString($versionString);

        $specific = $major === '5' ? getenv('GEEKBENCH5_URL') : getenv('GEEKBENCH6_URL');
        if ($specific !== false && $specific !== '') {
            return $specific;
        }

        $common = getenv('GEEKBENCH_URL');
        if ($common !== false && $common !== '') {
            return $common;
        }

        return sprintf(
            'https://cdn.geekbench.com/Geekbench-%s-Linux.tar.gz',
            $versionString
        );
    }

    public function buildBaseDirectory(string $versionString): string
    {
        return sprintf('/opt/Geekbench-%s-Linux', $versionString);
    }

    public function buildTarballPath(string $versionString): string
    {
        return sprintf('/tmp/Geekbench-%s-Linux.tar.gz', $versionString);
    }

    public function determineMajorFromVersionString(string $versionString): string
    {
        $trimmed = trim($versionString);
        if ($trimmed === '') {
            return '6';
        }

        if (preg_match('/^([0-9])(\.|$)/', $trimmed, $matches) === 1) {
            $major = $matches[1];
            if ($major === '5' || $major === '6') {
                return $major;
            }
        }

        return '6';
    }

    /**
     * Ensure the Geekbench binary exists on disk, downloading and extracting it if needed.
     *
     * @return string Absolute path to the executable.
     */
    public function ensureBinary(string $versionString): string
    {
        $baseDir = $this->buildBaseDirectory($versionString);
        $tarball = $this->buildTarballPath($versionString);
        $url = $this->buildDownloadUrl($versionString);

        if (!is_dir('/opt')) {
            @mkdir('/opt', 0755, true);
        }

        $candidateBinaries = [
            $baseDir . '/geekbench6',
            $baseDir . '/geekbench_x86_64',
        ];

        foreach ($candidateBinaries as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        $this->downloadAndExtract($url, $tarball, $baseDir);

        foreach ($candidateBinaries as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        $fallback = $this->discoverFallbackBinary($baseDir);
        if ($fallback !== null) {
            return $fallback;
        }

        throw new \RuntimeException(sprintf('Geekbench executable not found in %s', $baseDir));
    }

    /**
     * @param string   $url
     * @param string   $tarball
     * @param string   $baseDir
     */
    private function downloadAndExtract(string $url, string $tarball, string $baseDir): void
    {
        $downloader = $this->selectDownloader();
        if ($downloader === null) {
            throw new \RuntimeException('Neither wget nor curl is available to download Geekbench.');
        }

        if ($downloader === 'wget') {
            $cmd = sprintf(
                'wget -q -O %s %s',
                escapeshellarg($tarball),
                escapeshellarg($url)
            );
        } else {
            $cmd = sprintf(
                'curl -fsSL -o %s %s',
                escapeshellarg($tarball),
                escapeshellarg($url)
            );
        }

        $exitCode = 0;
        system($cmd, $exitCode);
        if ($exitCode !== 0 || !is_file($tarball)) {
            throw new \RuntimeException('Geekbench download failed.');
        }

        if (is_dir($baseDir)) {
            $this->recursiveRemove($baseDir);
        }

        $extractCmd = sprintf(
            'tar -C %s -xzf %s',
            escapeshellarg('/opt'),
            escapeshellarg($tarball)
        );

        $exitCode = 0;
        system($extractCmd, $exitCode);
        if ($exitCode !== 0) {
            throw new \RuntimeException('Geekbench extract failed.');
        }
    }

    private function selectDownloader(): ?string
    {
        if ($this->commandExists('wget')) {
            return 'wget';
        }
        if ($this->commandExists('curl')) {
            return 'curl';
        }

        return null;
    }

    private function commandExists(string $name): bool
    {
        $result = shell_exec(sprintf('command -v %s 2>/dev/null', escapeshellarg($name)));
        return is_string($result) && trim($result) !== '';
    }

    private function recursiveRemove(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($full)) {
                $this->recursiveRemove($full);
            } else {
                @unlink($full);
            }
        }

        @rmdir($path);
    }

    /**
     * @return string|null
     */
    private function discoverFallbackBinary(string $baseDir): ?string
    {
        if (!is_dir($baseDir)) {
            return null;
        }

        $iterator = new \FilesystemIterator(
            $baseDir,
            \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }
            $name = $fileInfo->getFilename();
            if (strpos($name, 'geekbench') === 0 && $fileInfo->isExecutable()) {
                return $fileInfo->getPathname();
            }
        }

        return null;
    }

    /**
     * @param string   $binaryPath
     * @param int|null $exitCode
     * @return array<int,string>
     */
    public function runBinary(string $binaryPath, ?int &$exitCode = null): array
    {
        $cmd = escapeshellarg($binaryPath);

        $output = [];
        $resultCode = 0;
        exec($cmd, $output, $resultCode);
        $exitCode = $resultCode;

        return $output;
    }

    /**
     * Parse Geekbench output and extract a single representative score.
     *
     * @param array<int,string> $lines
     */
    public function parseScore(array $lines, string $major): ?int
    {
        $major = $this->resolveMajorVersion($major);

        $multiCoreScore = null;
        $singleCoreScore = null;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($multiCoreScore === null && stripos($trimmed, 'Multi-Core Score') !== false) {
                $value = $this->extractTrailingInteger($trimmed);
                if ($value !== null) {
                    $multiCoreScore = $value;
                    continue;
                }
            }

            if ($singleCoreScore === null && stripos($trimmed, 'Single-Core Score') !== false) {
                $value = $this->extractTrailingInteger($trimmed);
                if ($value !== null) {
                    $singleCoreScore = $value;
                }
            }
        }

        if ($multiCoreScore !== null) {
            return $multiCoreScore;
        }

        if ($singleCoreScore !== null) {
            return $singleCoreScore;
        }

        return null;
    }

    private function extractTrailingInteger(string $line): ?int
    {
        if (preg_match('/([0-9][0-9., ]*)\s*$/', $line, $matches) !== 1) {
            return null;
        }

        $digitsOnly = preg_replace('/\D/', '', $matches[1]);
        if (!is_string($digitsOnly) || $digitsOnly === '' || strlen($digitsOnly) < 2) {
            return null;
        }

        return (int)$digitsOnly;
    }

    public function buildLogFilePath(string $major, ?\DateTimeImmutable $now = null): string
    {
        $major = $this->resolveMajorVersion($major);
        $now = $now ?? new \DateTimeImmutable('now');
        $date = $now->format('Ymd');

        return sprintf(
            '/tmp/benchmarkGeekbench%s-%s.log',
            $major,
            $date
        );
    }
}
