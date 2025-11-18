<?php

declare(strict_types=1);

namespace mcxForge\Benchmark;

final class CPUInfo
{
    public static function detectLogicalCores(): int
    {
        $count = self::detectWithNproc();
        if ($count !== null && $count > 0) {
            return $count;
        }

        $count = self::detectFromProcCpuinfo();
        if ($count !== null && $count > 0) {
            return $count;
        }

        return 1;
    }

    private static function detectWithNproc(): ?int
    {
        $output = @shell_exec('nproc 2>/dev/null');
        if (!is_string($output) || trim($output) === '') {
            return null;
        }

        $value = trim($output);
        if (!ctype_digit($value)) {
            return null;
        }

        $count = (int) $value;
        return $count > 0 ? $count : null;
    }

    private static function detectFromProcCpuinfo(): ?int
    {
        if (!is_readable('/proc/cpuinfo')) {
            return null;
        }

        $contents = @file('/proc/cpuinfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($contents === false) {
            return null;
        }

        $count = 0;
        foreach ($contents as $line) {
            if (strpos($line, 'processor') === 0) {
                $count++;
            }
        }

        return $count > 0 ? $count : null;
    }
}

