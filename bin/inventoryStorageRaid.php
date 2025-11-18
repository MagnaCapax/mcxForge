#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * inventoryStorageRaid.php
 *
 * List MD RAID arrays discovered on the system using /proc/mdstat and
 * optional mdadm metadata. Supports human-readable output (default),
 * JSON, and PHP serialize formats.
 *
 * This script is read-only with respect to user data: it does not assemble
 * or modify arrays. It only inspects kernel state and, when available,
 * mdadm metadata (including `mdadm --examine --scan` for potential arrays
 * that are not currently assembled).
 *
 * @author Aleksi Ursin
 */

require_once __DIR__ . '/../lib/php/Logger.php';

 \mcxForge\Logger::initStreamLogging();

if (!defined('EXIT_OK')) {
    define('EXIT_OK', 0);
}
if (!defined('EXIT_ERROR')) {
    define('EXIT_ERROR', 1);
}

/**
 * @param array<int,string> $argv
 */
function inventoryStorageRaidMain(array $argv): int
{
    [$format, $colorEnabled, $healthOnly] = parseRaidArguments($argv);

    $arrays = detectMdRaidArrays();
    if ($arrays === null) {
        \mcxForge\Logger::logStderr("Error: failed to detect MD RAID arrays.\n");
        return EXIT_ERROR;
    }

    $arrays = annotateRaidHealth($arrays);
    $summary = summarizeRaidHealth($arrays);

    switch ($format) {
        case 'json':
            renderRaidJson($arrays, $summary);
            break;
        case 'php':
            renderRaidPhp($arrays, $summary);
            break;
        case 'human':
        default:
            renderRaidHuman($arrays, $summary, $colorEnabled, $healthOnly);
            break;
    }

    return EXIT_OK;
}

/**
 * @param array<int,string> $argv
 * @return array{0:string,1:bool,2:bool}
 */
function parseRaidArguments(array $argv): array
{
    $format = 'human';
    $colorEnabled = true;
    $healthOnly = false;

    if (getenv('NO_COLOR') !== false) {
        $colorEnabled = false;
    }

    $args = $argv;
    array_shift($args); // script name

    foreach ($args as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            printRaidHelp();
            exit(EXIT_OK);
        }

        if (str_starts_with($arg, '--format=')) {
            $value = substr($arg, strlen('--format='));
            if (!in_array($value, ['human', 'json', 'php'], true)) {
                \mcxForge\Logger::logStderr("Error: unsupported format '$value'. Use human, json, or php.\n");
                exit(EXIT_ERROR);
            }
            $format = $value;
            continue;
        }

        if ($arg === '--no-color') {
            $colorEnabled = false;
            continue;
        }

        if ($arg === '--health') {
            $healthOnly = true;
            continue;
        }

        \mcxForge\Logger::logStderr("Error: unrecognized argument '$arg'. Use --help for usage.\n");
        exit(EXIT_ERROR);
    }

    return [$format, $colorEnabled, $healthOnly];
}

/**
 * Detect MD RAID arrays using mdadm when available and /proc/mdstat as a fallback.
 *
 * Each array entry may contain:
 *  - name: string
 *  - path: string
 *  - level: string|null
 *  - state: string|null
 *  - sizeBytes: int|null
 *  - sizeGiB: int|null
 *  - uuid: string|null
 *  - metadata: string|null
 *  - members: array<int,string>
 *  - source: string
 *
 * @return array<int,array<string,mixed>>|null
 */
function detectMdRaidArrays(): ?array
{
    $mdadmPath = findMdadm();
    $arrays = [];

    if ($mdadmPath !== null) {
        $arraysFromMdadm = getArraysWithMdadm($mdadmPath);
        if ($arraysFromMdadm !== null) {
            $arrays = $arraysFromMdadm;
        }
    }

    // If mdadm is missing or returned nothing, fall back to /proc/mdstat.
    if (count($arrays) === 0) {
        $arraysFromMdstat = getArraysFromProcMdstat();
        if ($arraysFromMdstat === null) {
            return null;
        }
        $arrays = $arraysFromMdstat;
    }

    return array_values($arrays);
}

/**
 * @param array<int,array<string,mixed>> $arrays
 * @return array<int,array<string,mixed>>
 */
function annotateRaidHealth(array $arrays): array
{
    foreach ($arrays as $index => $array) {
        $arrays[$index]['health'] = normalizeRaidHealth($array);
    }

    return $arrays;
}

/**
 * Normalize MD RAID health into a small set of states:
 * CLEAN, RESYNCING, DEGRADED, BROKEN, POTENTIAL, UNKNOWN.
 *
 * @param array<string,mixed> $array
 */
function normalizeRaidHealth(array $array): string
{
    $source = isset($array['source']) ? (string) $array['source'] : '';
    $stateRaw = isset($array['state']) ? (string) $array['state'] : '';
    $levelRaw = isset($array['level']) ? strtolower((string) $array['level']) : '';

    $state = strtolower($stateRaw);

    $raidDevices = isset($array['raidDevices']) ? (int) $array['raidDevices'] : 0;
    $activeDevices = isset($array['activeDevices']) ? (int) $array['activeDevices'] : 0;
    $failedDevices = isset($array['failedDevices']) ? (int) $array['failedDevices'] : 0;

    if ($source === 'mdadm-examine-scan' && $state === '') {
        return 'POTENTIAL';
    }

    if ($state !== '' && strpos($state, 'potential') !== false) {
        return 'POTENTIAL';
    }

    $missingFromActive = 0;
    if ($raidDevices > 0 && $activeDevices >= 0 && $raidDevices >= $activeDevices) {
        $missingFromActive = $raidDevices - $activeDevices;
    }
    $missingTotal = $missingFromActive + $failedDevices;
    $level = $levelRaw;

    if ($level === 'raid5' || $level === 'raid4') {
        if ($missingTotal >= 2) {
            return 'BROKEN';
        }
    } elseif ($level === 'raid6') {
        if ($missingTotal >= 3) {
            return 'BROKEN';
        }
    } elseif ($level === 'raid1') {
        if ($raidDevices > 0 && $activeDevices === 0) {
            return 'BROKEN';
        }
    } elseif ($raidDevices > 0 && $activeDevices === 0 && $state !== '') {
        return 'BROKEN';
    }

    if ($state === '') {
        if ($source === 'mdadm-examine-scan') {
            return 'POTENTIAL';
        }

        if ($source === 'proc-mdstat') {
            return 'UNKNOWN';
        }

        return 'UNKNOWN';
    }

    if (
        strpos($state, 'resync') !== false
        || strpos($state, 'recover') !== false
        || strpos($state, 'rebuild') !== false
        || strpos($state, 'check') !== false
    ) {
        return 'RESYNCING';
    }

    if (
        $failedDevices > 0
        || strpos($state, 'degraded') !== false
        || strpos($state, 'faulty') !== false
    ) {
        return 'DEGRADED';
    }

    if (
        strpos($state, 'inactive') !== false
        || strpos($state, 'stopped') !== false
        || strpos($state, 'failed') !== false
    ) {
        return 'BROKEN';
    }

    if (strpos($state, 'clean') !== false || strpos($state, 'active') !== false) {
        return 'CLEAN';
    }

    return 'UNKNOWN';
}

/**
 * @param array<int,array<string,mixed>> $arrays
 * @return array{overallHealth:string,totalArrays:int,healthCounts:array<string,int>}
 */
function summarizeRaidHealth(array $arrays): array
{
    $counts = [];

    foreach ($arrays as $array) {
        $health = strtoupper((string) ($array['health'] ?? 'UNKNOWN'));
        if ($health === '') {
            $health = 'UNKNOWN';
        }

        if (!isset($counts[$health])) {
            $counts[$health] = 0;
        }

        $counts[$health]++;
    }

    $total = 0;
    foreach ($counts as $value) {
        $total += $value;
    }

    if ($total === 0) {
        $overall = 'NONE';
    } elseif (!empty($counts['BROKEN'])) {
        $overall = 'BROKEN';
    } elseif (!empty($counts['DEGRADED'])) {
        $overall = 'DEGRADED';
    } elseif (!empty($counts['RESYNCING'])) {
        $overall = 'RESYNCING';
    } elseif (!empty($counts['CLEAN']) && count($counts) === 1) {
        $overall = 'CLEAN';
    } else {
        $overall = 'MIXED';
    }

    return [
        'overallHealth' => $overall,
        'totalArrays' => $total,
        'healthCounts' => $counts,
    ];
}

/**
 * Try to locate the mdadm binary.
 */
function findMdadm(): ?string
{
    $output = @shell_exec('command -v mdadm 2>/dev/null');
    if (is_string($output)) {
        $path = trim($output);
        if ($path !== '' && is_executable($path)) {
            return $path;
        }
    }

    $candidates = ['/sbin/mdadm', '/usr/sbin/mdadm'];
    foreach ($candidates as $candidate) {
        if (is_executable($candidate)) {
            return $candidate;
        }
    }

    return null;
}

/**
 * Discover arrays via mdadm --detail --scan and mdadm --examine --scan.
 *
 * @return array<string,array<string,mixed>>|null keyed by UUID or path
 */
function getArraysWithMdadm(string $mdadmPath): ?array
{
    $arrays = [];

    $mdadmSafe = escapeshellcmd($mdadmPath);

    $detailScanOutput = @shell_exec($mdadmSafe . ' --detail --scan 2>/dev/null');
    if (is_string($detailScanOutput) && trim($detailScanOutput) !== '') {
        $lines = preg_split('/\R/', trim($detailScanOutput));
        if (is_array($lines)) {
            foreach ($lines as $line) {
                $parsed = parseMdadmArrayLine($line);
                if ($parsed === null) {
                    continue;
                }

                $detail = getMdadmDetail($mdadmSafe, $parsed['path']);
                if ($detail !== null) {
                    $parsed = array_merge($parsed, $detail);
                }

                $key = $parsed['uuid'] ?? $parsed['path'];
                $parsed['source'] = 'mdadm-detail-scan';
                $arrays[$key] = $parsed;
            }
        }
    }

    // Add potential arrays discovered via metadata that are not currently assembled.
    $examineScanOutput = @shell_exec($mdadmSafe . ' --examine --scan 2>/dev/null');
    if (is_string($examineScanOutput) && trim($examineScanOutput) !== '') {
        $lines = preg_split('/\R/', trim($examineScanOutput));
        if (is_array($lines)) {
            foreach ($lines as $line) {
                $parsed = parseMdadmArrayLine($line);
                if ($parsed === null) {
                    continue;
                }

                $key = $parsed['uuid'] ?? $parsed['path'];
                if (isset($arrays[$key])) {
                    // Already present via --detail --scan / live array.
                    continue;
                }

                $parsed['state'] = $parsed['state'] ?? 'POTENTIAL';
                $parsed['sizeBytes'] = $parsed['sizeBytes'] ?? null;
                $parsed['sizeGiB'] = $parsed['sizeGiB'] ?? null;
                $parsed['members'] = $parsed['members'] ?? [];
                $parsed['source'] = 'mdadm-examine-scan';

                $arrays[$key] = $parsed;
            }
        }
    }

    return $arrays;
}

/**
 * Parse a single "ARRAY ..." line from mdadm --detail --scan or --examine --scan.
 *
 * @return array<string,mixed>|null
 */
function parseMdadmArrayLine(string $line): ?array
{
    $trimmed = trim($line);
    if ($trimmed === '' || !str_starts_with($trimmed, 'ARRAY ')) {
        return null;
    }

    $parts = preg_split('/\s+/', $trimmed);
    if (!is_array($parts) || count($parts) < 2) {
        return null;
    }

    // ARRAY <path> key=value ...
    $path = $parts[1];
    if ($path === '') {
        return null;
    }

    $result = [
        'path' => $path,
        'name' => null,
        'uuid' => null,
        'metadata' => null,
        'level' => null,
        'state' => null,
        'sizeBytes' => null,
        'sizeGiB' => null,
        'members' => [],
    ];

    for ($i = 2; $i < count($parts); $i++) {
        $token = $parts[$i];
        if ($token === '' || strpos($token, '=') === false) {
            continue;
        }

        [$key, $value] = explode('=', $token, 2);
        $key = strtolower(trim($key));
        $value = trim($value);

        if ($key === 'name') {
            $result['name'] = $value;
        } elseif ($key === 'uuid') {
            $result['uuid'] = $value;
        } elseif ($key === 'metadata') {
            $result['metadata'] = $value;
        } elseif ($key === 'level') {
            $result['level'] = $value;
        } elseif ($key === 'num-devices') {
            // num-devices is informative but does not affect inventory shape directly.
            $result['numDevices'] = (int) $value;
        }
    }

    if ($result['name'] === null) {
        // Fallback: use the basename of the device path as name when not provided.
        $result['name'] = basename($path);
    }

    return $result;
}

/**
 * Inspect a live MD array via mdadm --detail.
 *
 * @return array<string,mixed>|null
 */
function getMdadmDetail(string $mdadmSafePath, string $path): ?array
{
    $cmd = $mdadmSafePath . ' --detail ' . escapeshellarg($path) . ' 2>/dev/null';
    $output = @shell_exec($cmd);

    if (!is_string($output) || trim($output) === '') {
        return null;
    }

    $lines = preg_split('/\R/', $output);
    if (!is_array($lines)) {
        return null;
    }

    $level = null;
    $state = null;
    $uuid = null;
    $sizeBytes = null;
    $members = [];
    $raidDevices = null;
    $activeDevices = null;
    $failedDevices = null;
    $spareDevices = null;

    foreach ($lines as $line) {
        $trimmed = trim((string) $line);
        if ($trimmed === '') {
            continue;
        }

        if (stripos($trimmed, 'Raid Level :') === 0) {
            $value = trim(substr($trimmed, strlen('Raid Level :')));
            if ($value !== '') {
                $level = $value;
            }
            continue;
        }

        if (stripos($trimmed, 'State :') === 0) {
            $value = trim(substr($trimmed, strlen('State :')));
            if ($value !== '') {
                $state = $value;
            }
            continue;
        }

        if (stripos($trimmed, 'UUID :') === 0) {
            $value = trim(substr($trimmed, strlen('UUID :')));
            if ($value !== '') {
                $uuid = $value;
            }
            continue;
        }

        if (stripos($trimmed, 'Array Size :') === 0) {
            // Prefer the explicit byte count when present.
            if (preg_match('/\((\d+)\s+bytes\)/', $trimmed, $matches) === 1) {
                $sizeBytes = (int) $matches[1];
            }
            continue;
        }

        if (stripos($trimmed, 'Raid Devices :') === 0) {
            $value = trim(substr($trimmed, strlen('Raid Devices :')));
            if ($value !== '' && ctype_digit(str_replace([' ', "\t"], '', $value))) {
                $raidDevices = (int) $value;
            }
            continue;
        }

        if (stripos($trimmed, 'Active Devices :') === 0) {
            $value = trim(substr($trimmed, strlen('Active Devices :')));
            if ($value !== '' && ctype_digit(str_replace([' ', "\t"], '', $value))) {
                $activeDevices = (int) $value;
            }
            continue;
        }

        if (stripos($trimmed, 'Failed Devices :') === 0) {
            $value = trim(substr($trimmed, strlen('Failed Devices :')));
            if ($value !== '' && ctype_digit(str_replace([' ', "\t"], '', $value))) {
                $failedDevices = (int) $value;
            }
            continue;
        }

        if (stripos($trimmed, 'Spare Devices :') === 0) {
            $value = trim(substr($trimmed, strlen('Spare Devices :')));
            if ($value !== '' && ctype_digit(str_replace([' ', "\t"], '', $value))) {
                $spareDevices = (int) $value;
            }
            continue;
        }

        if (preg_match('#/dev/[A-Za-z0-9/_-]+#', $trimmed, $matches) === 1) {
            $members[] = $matches[0];
        }
    }

    $sizeGiB = null;
    if ($sizeBytes !== null && $sizeBytes > 0) {
        $sizeGiB = (int) round($sizeBytes / (1024 * 1024 * 1024));
    }

    $members = array_values(array_unique($members));

    $raidDevices = $raidDevices !== null ? (int) $raidDevices : null;
    $activeDevices = $activeDevices !== null ? (int) $activeDevices : null;
    $failedDevices = $failedDevices !== null ? (int) $failedDevices : null;
    $spareDevices = $spareDevices !== null ? (int) $spareDevices : null;

    return [
        'level' => $level,
        'state' => $state,
        'uuid' => $uuid,
        'sizeBytes' => $sizeBytes,
        'sizeGiB' => $sizeGiB,
        'members' => $members,
        'raidDevices' => $raidDevices,
        'activeDevices' => $activeDevices,
        'failedDevices' => $failedDevices,
        'spareDevices' => $spareDevices,
    ];
}

/**
 * Fallback detection: parse /proc/mdstat when mdadm is unavailable or returns no arrays.
 *
 * @return array<int,array<string,mixed>>|null
 */
function getArraysFromProcMdstat(): ?array
{
    if (!is_readable('/proc/mdstat')) {
        return null;
    }

    $lines = @file('/proc/mdstat', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return null;
    }

    $arrays = [];
    $lineCount = count($lines);

    for ($i = 0; $i < $lineCount; $i++) {
        $line = trim((string) $lines[$i]);
        if ($line === '' || $line[0] === '#') {
            continue;
        }

        if (!preg_match('/^(md[0-9]+)\s*:\s*(.+)$/', $line, $matches)) {
            continue;
        }

        $name = $matches[1];
        $rest = trim($matches[2]);
        $parts = preg_split('/\s+/', $rest);
        if (!is_array($parts) || count($parts) < 2) {
            continue;
        }

        $stateWord = $parts[0];
        $level = $parts[1];

        $members = [];
        for ($j = 2; $j < count($parts); $j++) {
            $token = $parts[$j];
            if ($token === '') {
                continue;
            }

            // Strip array index and role annotations like sda1[0](S)
            $device = $token;
            $bracketPos = strpos($device, '[');
            if ($bracketPos !== false) {
                $device = substr($device, 0, $bracketPos);
            }
            $parenPos = strpos($device, '(');
            if ($parenPos !== false) {
                $device = substr($device, 0, $parenPos);
            }

            if ($device === '' || $device[0] === '[' || $device[0] === '(') {
                continue;
            }

            if (strpos($device, '/') === false) {
                $device = '/dev/' . $device;
            }

            $members[] = $device;
        }

        $sizeBytes = null;
        $sizeGiB = null;

        if ($i + 1 < $lineCount) {
            $nextLine = trim((string) $lines[$i + 1]);
            if (preg_match('/(\d+)\s+blocks/', $nextLine, $sizeMatches) === 1) {
                $blocks = (int) $sizeMatches[1];
                if ($blocks > 0) {
                    // mdstat "blocks" are typically 1 KiB units; use that as an approximation.
                    $sizeBytes = $blocks * 1024;
                    $sizeGiB = (int) round($sizeBytes / (1024 * 1024 * 1024));
                }
            }
        }

        $arrays[] = [
            'name' => $name,
            'path' => '/dev/' . $name,
            'level' => $level,
            'state' => $stateWord,
            'sizeBytes' => $sizeBytes,
            'sizeGiB' => $sizeGiB,
            'uuid' => null,
            'metadata' => null,
            'members' => array_values(array_unique($members)),
            'source' => 'proc-mdstat',
        ];
    }

    return $arrays;
}

/**
 * @param array<int,array<string,mixed>> $arrays
 */
function renderRaidHuman(array $arrays, array $summary, bool $colorEnabled, bool $healthOnly): void
{
    if (count($arrays) === 0) {
        echo "No MD RAID arrays detected.\n";
        echo "Summary: overall=NONE; total=0\n";
        return;
    }

    $headerColor = $colorEnabled ? "\033[1;34m" : '';
    $deviceColor = $colorEnabled ? "\033[0;36m" : '';
    $resetColor = $colorEnabled ? "\033[0m" : '';

    if ($healthOnly) {
        echo $headerColor . "MD RAID health summary" . $resetColor . PHP_EOL;

        $overall = strtoupper($summary['overallHealth']);
        $overallColor = '';
        if ($colorEnabled) {
            if ($overall === 'CLEAN') {
                $overallColor = "\033[0;32m";
            } elseif ($overall === 'RESYNCING') {
                $overallColor = "\033[0;33m";
            } elseif ($overall === 'DEGRADED' || $overall === 'BROKEN' || $overall === 'MIXED') {
                $overallColor = "\033[0;31m";
            } else {
                $overallColor = "\033[0;33m";
            }
        }

        echo sprintf(
            "Overall: %s%s%s\n",
            $overallColor,
            $overall,
            $resetColor
        );

        $counts = $summary['healthCounts'];

        $cleanCount = (int) ($counts['CLEAN'] ?? 0);
        $degradedCount = (int) ($counts['DEGRADED'] ?? 0);
        $resyncingCount = (int) ($counts['RESYNCING'] ?? 0);
        $brokenCount = (int) ($counts['BROKEN'] ?? 0);
        $potentialCount = (int) ($counts['POTENTIAL'] ?? 0);
        $unknownCount = (int) ($counts['UNKNOWN'] ?? 0);

        echo sprintf(
            "Totals: total=%d; CLEAN=%d; DEGRADED=%d; RESYNCING=%d; BROKEN=%d; POTENTIAL=%d; UNKNOWN=%d\n",
            (int) $summary['totalArrays'],
            $cleanCount,
            $degradedCount,
            $resyncingCount,
            $brokenCount,
            $potentialCount,
            $unknownCount
        );

        $problemHealths = ['DEGRADED', 'RESYNCING', 'BROKEN'];
        $problemLines = [];

        foreach ($arrays as $array) {
            $health = strtoupper((string) ($array['health'] ?? 'UNKNOWN'));
            if (!in_array($health, $problemHealths, true)) {
                continue;
            }

            $name = isset($array['name']) ? (string) $array['name'] : '';
            $path = isset($array['path']) ? (string) $array['path'] : '';
            $identifier = $path !== '' ? $path : ($name !== '' ? '/dev/' . $name : 'UNKNOWN');

            $problemLines[] = sprintf(
                "  * %s; %s",
                $identifier,
                $health
            );
        }

        if (count($problemLines) > 0) {
            echo "Problem arrays:\n";
            foreach ($problemLines as $line) {
                echo $line . "\n";
            }
        } else {
            echo "Problem arrays: none\n";
        }

        return;
    }

    echo $headerColor . "MD RAID arrays" . $resetColor . PHP_EOL;

    foreach ($arrays as $array) {
        $path = isset($array['path']) ? (string) $array['path'] : '';
        if ($path === '') {
            $path = isset($array['name']) ? '/dev/' . (string) $array['name'] : 'UNKNOWN';
        }

        $level = isset($array['level']) && $array['level'] !== null ? (string) $array['level'] : 'unknown';
        $health = strtoupper((string) ($array['health'] ?? 'UNKNOWN'));

        $sizeGiB = null;
        if (isset($array['sizeGiB']) && $array['sizeGiB'] !== null) {
            $sizeGiB = (int) $array['sizeGiB'];
        }

        $sizeText = $sizeGiB !== null ? $sizeGiB . 'GiB' : 'size: unknown';

        $members = [];
        if (isset($array['members']) && is_array($array['members'])) {
            foreach ($array['members'] as $member) {
                $members[] = (string) $member;
            }
        }

        $membersText = count($members) > 0 ? implode(', ', $members) : 'no members detected';

        $healthColor = '';
        if ($colorEnabled) {
            if ($health === 'CLEAN') {
                $healthColor = "\033[0;32m";
            } elseif ($health === 'RESYNCING') {
                $healthColor = "\033[0;33m";
            } elseif ($health === 'DEGRADED' || $health === 'BROKEN') {
                $healthColor = "\033[0;31m";
            } else {
                $healthColor = "\033[0;33m";
            }
        }

        $warningSuffix = '';
        if (in_array($health, ['DEGRADED', 'RESYNCING', 'BROKEN'], true)) {
            $warningSuffix = ' WARNING: ' . $health;
        }

        $line = sprintf(
            "  * %s; %s; %s%s%s; %s; members: %s%s",
            $deviceColor . $path . $resetColor,
            strtoupper($level),
            $healthColor,
            $health,
            $resetColor,
            $sizeText,
            $membersText,
            $warningSuffix
        );

        echo $line . PHP_EOL;
    }

    $counts = $summary['healthCounts'];

    $cleanCount = (int) ($counts['CLEAN'] ?? 0);
    $degradedCount = (int) ($counts['DEGRADED'] ?? 0);
    $resyncingCount = (int) ($counts['RESYNCING'] ?? 0);
    $brokenCount = (int) ($counts['BROKEN'] ?? 0);
    $potentialCount = (int) ($counts['POTENTIAL'] ?? 0);
    $unknownCount = (int) ($counts['UNKNOWN'] ?? 0);

    $overall = strtoupper($summary['overallHealth']);
    $overallColor = '';
    if ($colorEnabled) {
        if ($overall === 'CLEAN') {
            $overallColor = "\033[0;32m";
        } elseif ($overall === 'RESYNCING') {
            $overallColor = "\033[0;33m";
        } elseif ($overall === 'DEGRADED' || $overall === 'BROKEN' || $overall === 'MIXED') {
            $overallColor = "\033[0;31m";
        } else {
            $overallColor = "\033[0;33m";
        }
    }

    echo sprintf(
        "Summary: overall=%s%s%s; total=%d; CLEAN=%d; DEGRADED=%d; RESYNCING=%d; BROKEN=%d; POTENTIAL=%d; UNKNOWN=%d\n",
        $overallColor,
        $overall,
        $resetColor,
        (int) $summary['totalArrays'],
        $cleanCount,
        $degradedCount,
        $resyncingCount,
        $brokenCount,
        $potentialCount,
        $unknownCount
    );
}

/**
 * @param array<int,array<string,mixed>> $arrays
 */
function renderRaidJson(array $arrays, array $summary): void
{
    $payload = [
        'arrays' => $arrays,
        'summary' => $summary,
    ];

    $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        \mcxForge\Logger::logStderr("Error: failed to encode JSON output.\n");
        exit(EXIT_ERROR);
    }

    echo $encoded . PHP_EOL;
}

/**
 * @param array<int,array<string,mixed>> $arrays
 */
function renderRaidPhp(array $arrays, array $summary): void
{
    $payload = [
        'arrays' => $arrays,
        'summary' => $summary,
    ];

    echo serialize($payload) . PHP_EOL;
}

function printRaidHelp(): void
{
    $help = <<<TEXT
Usage: inventoryStorageRaid.php [--format=human|json|php] [--health] [--no-color]

List MD RAID arrays discovered on the system using /proc/mdstat and mdadm metadata
when available. The script is read-only: it does not assemble or modify arrays.

Options:
  --format=human    Human-readable output (default).
  --format=json     JSON array of MD RAID arrays.
  --format=php      PHP serialize() output of the same structure.
  --health          Print only a summarized health view (overall state and
                    problematic arrays); intended for quick checks.
  --no-color        Disable ANSI colors in human output.
  -h, --help        Show this help message.

Notes:
  - When mdadm is present, arrays are discovered via 'mdadm --detail --scan'
    and 'mdadm --examine --scan' to include both active and potential arrays.
  - When mdadm is not available or reports nothing, /proc/mdstat is used as
    a best-effort fallback for active arrays only.
  - This script intentionally does not perform 'mdadm --assemble --scan' or
    any other state-changing operation; that work belongs in separate tools
    with explicit safety flags.

TEXT;

    echo $help;
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(inventoryStorageRaidMain($argv));
}
