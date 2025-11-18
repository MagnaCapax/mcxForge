#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * inventoryBootEnv.php
 *
 * Collect boot-environment information for the current host. Reports:
 *  - Boot mode (UEFI vs BIOS).
 *  - Kernel release and full /proc/cmdline.
 *  - Root filesystem device and type.
 *  - EFI system partition (ESP) detection when present.
 *
 * Supports human‑readable output (default), JSON, and PHP serialize formats.
 * This script is read‑only and intended for use on live systems to feed
 * qualification and diagnostics flows.
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

function inventoryBootEnvMain(array $argv): int
{
    [$format, $colorEnabled] = inventoryBootEnvParseArguments($argv);

    $info = inventoryBootEnvCollect();

    switch ($format) {
        case 'json':
            inventoryBootEnvRenderJson($info);
            break;
        case 'php':
            inventoryBootEnvRenderPhp($info);
            break;
        case 'human':
        default:
            inventoryBootEnvRenderHuman($info, $colorEnabled);
            break;
    }

    return EXIT_OK;
}

/**
 * @return array{0:string,1:bool}
 */
function inventoryBootEnvParseArguments(array $argv): array
{
    $format = 'human';
    $colorEnabled = true;

    if (getenv('NO_COLOR') !== false) {
        $colorEnabled = false;
    }

    $args = $argv;
    array_shift($args);

    foreach ($args as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            inventoryBootEnvPrintHelp();
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

        \mcxForge\Logger::logStderr("Error: unrecognized argument '$arg'. Use --help for usage.\n");
        exit(EXIT_ERROR);
    }

    return [$format, $colorEnabled];
}

/**
 * @return array<string,mixed>
 */
function inventoryBootEnvCollect(): array
{
    $bootMode = inventoryBootEnvDetectMode();
    $kernelRelease = php_uname('r');
    $cmdline = inventoryBootEnvReadCmdline();

    $root = inventoryBootEnvDetectRoot();
    $esp = inventoryBootEnvDetectEsp();

    return [
        'bootMode' => $bootMode,
        'kernelRelease' => $kernelRelease,
        'cmdline' => $cmdline,
        'root' => $root,
        'esp' => $esp,
    ];
}

function inventoryBootEnvDetectMode(): string
{
    if (is_dir('/sys/firmware/efi')) {
        return 'UEFI';
    }

    return 'BIOS';
}

/**
 * @return string|null
 */
function inventoryBootEnvReadCmdline(): ?string
{
    $path = '/proc/cmdline';
    if (!is_readable($path)) {
        return null;
    }

    $contents = @file_get_contents($path);
    if ($contents === false) {
        return null;
    }

    return trim($contents);
}

/**
 * @return array<string,mixed>|null
 */
function inventoryBootEnvDetectRoot(): ?array
{
    $source = null;
    $fstype = null;

    $findmntCmd = 'findmnt -no SOURCE,FSTYPE / 2>/dev/null';
    $output = @shell_exec($findmntCmd);
    if (is_string($output) && trim($output) !== '') {
        $parts = preg_split('/\s+/', trim($output));
        if (is_array($parts) && count($parts) >= 1) {
            $source = $parts[0];
            if (count($parts) >= 2) {
                $fstype = $parts[1];
            }
        }
    }

    if ($source === null && is_readable('/etc/fstab')) {
        $lines = @file('/etc/fstab', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($lines)) {
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if ($trimmed === '' || $trimmed[0] === '#') {
                    continue;
                }
                $cols = preg_split('/\s+/', $trimmed);
                if (!is_array($cols) || count($cols) < 2) {
                    continue;
                }
                if ($cols[1] === '/') {
                    $source = $cols[0];
                    $fstype = $cols[2] ?? null;
                    break;
                }
            }
        }
    }

    if ($source === null) {
        return null;
    }

    return [
        'source' => $source,
        'fstype' => $fstype,
    ];
}

/**
 * @return array<string,mixed>|null
 */
function inventoryBootEnvDetectEsp(): ?array
{
    $cmd = 'lsblk -J -o NAME,TYPE,MOUNTPOINT,FSTYPE,PARTTYPE,PARTLABEL 2>/dev/null';
    $output = @shell_exec($cmd);
    if (!is_string($output) || trim($output) === '') {
        return null;
    }

    $decoded = json_decode($output, true);
    if (!is_array($decoded) || !isset($decoded['blockdevices']) || !is_array($decoded['blockdevices'])) {
        return null;
    }

    /** @var array<int,array<string,mixed>> $devices */
    $devices = $decoded['blockdevices'];

    $espCandidate = null;

    foreach ($devices as $dev) {
        inventoryBootEnvScanEspInDevice($dev, $espCandidate);
    }

    return $espCandidate;
}

/**
 * @param array<string,mixed> $node
 * @param array<string,mixed>|null $espCandidate
 */
function inventoryBootEnvScanEspInDevice(array $node, ?array &$espCandidate): void
{
    $type = strtolower((string) ($node['type'] ?? ''));
    $name = (string) ($node['name'] ?? '');
    $mountpoint = $node['mountpoint'] ?? null;
    $fstype = strtolower((string) ($node['fstype'] ?? ''));
    $parttype = strtolower((string) ($node['parttype'] ?? ''));
    $partlabel = strtolower((string) ($node['partlabel'] ?? ''));

    $isEsp = false;

    if ($type === 'part') {
        if ($mountpoint === '/boot/efi') {
            $isEsp = true;
        } elseif ($fstype === 'vfat' || $fstype === 'fat32') {
            if ($parttype === 'c12a7328-f81f-11d2-ba4b-00a0c93ec93b') {
                $isEsp = true;
            } elseif (strpos($partlabel, 'efi') !== false) {
                $isEsp = true;
            }
        }
    }

    if ($isEsp) {
        $espCandidate = [
            'name' => $name,
            'path' => '/dev/' . $name,
            'mountpoint' => $mountpoint,
            'fstype' => $fstype !== '' ? $fstype : null,
            'parttype' => $parttype !== '' ? $parttype : null,
            'partlabel' => $partlabel !== '' ? $partlabel : null,
        ];
        return;
    }

    if (isset($node['children']) && is_array($node['children'])) {
        /** @var array<int,array<string,mixed>> $children */
        $children = $node['children'];
        foreach ($children as $child) {
            inventoryBootEnvScanEspInDevice($child, $espCandidate);
            if ($espCandidate !== null) {
                return;
            }
        }
    }
}

/**
 * @param array<string,mixed> $info
 */
function inventoryBootEnvRenderHuman(array $info, bool $colorEnabled): void
{
    $sectionColor = $colorEnabled ? "\033[1;34m" : '';
    $labelColor = $colorEnabled ? "\033[0;36m" : '';
    $valueColor = $colorEnabled ? "\033[0;37m" : '';
    $resetColor = $colorEnabled ? "\033[0m" : '';

    echo $sectionColor . "Boot environment" . $resetColor . PHP_EOL;

    echo sprintf(
        "  %sBoot mode:%s %s%s%s\n",
        $labelColor,
        $resetColor,
        $valueColor,
        $info['bootMode'] ?? 'unknown',
        $resetColor
    );

    echo sprintf(
        "  %sKernel:%s %s%s%s\n",
        $labelColor,
        $resetColor,
        $valueColor,
        $info['kernelRelease'] ?? 'unknown',
        $resetColor
    );

    /** @var array<string,mixed>|null $root */
    $root = $info['root'] ?? null;
    if ($root !== null) {
        echo sprintf(
            "  %sRoot device:%s %s%s%s",
            $labelColor,
            $resetColor,
            $valueColor,
            $root['source'] ?? 'unknown',
            $resetColor
        );
        if (!empty($root['fstype'])) {
            echo sprintf(" (fstype=%s)", $root['fstype']);
        }
        echo PHP_EOL;
    }

    /** @var array<string,mixed>|null $esp */
    $esp = $info['esp'] ?? null;
    if ($esp !== null) {
        echo sprintf(
            "  %sESP:%s %s%s%s",
            $labelColor,
            $resetColor,
            $valueColor,
            $esp['path'] ?? 'unknown',
            $resetColor
        );
        if (!empty($esp['mountpoint'])) {
            echo sprintf(" (mount=%s", $esp['mountpoint']);
            if (!empty($esp['fstype'])) {
                echo sprintf(", fstype=%s", $esp['fstype']);
            }
            echo ")";
        }
        echo PHP_EOL;
    }

    $cmdline = $info['cmdline'] ?? null;
    if ($cmdline !== null && $cmdline !== '') {
        echo PHP_EOL;
        echo $sectionColor . "Kernel command line" . $resetColor . PHP_EOL;
        echo '  ' . $cmdline . PHP_EOL;
    }
}

/**
 * @param array<string,mixed> $info
 */
function inventoryBootEnvRenderJson(array $info): void
{
    $encoded = json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        \mcxForge\Logger::logStderr("Error: failed to encode JSON output.\n");
        exit(EXIT_ERROR);
    }

    echo $encoded . PHP_EOL;
}

/**
 * @param array<string,mixed> $info
 */
function inventoryBootEnvRenderPhp(array $info): void
{
    echo serialize($info) . PHP_EOL;
}

function inventoryBootEnvPrintHelp(): void
{
    $help = <<<TEXT
Usage: inventoryBootEnv.php [--format=human|json|php] [--no-color]

Collect boot-environment information for the current host (boot mode, kernel,
root device, and EFI system partition when available).

Options:
  --format=human    Human-readable output (default).
  --format=json     JSON output with bootMode, kernelRelease, root, esp, and cmdline.
  --format=php      PHP serialize() output of the same structure.
  --no-color        Disable ANSI colors in human output.
  -h, --help        Show this help message.

Notes:
  - Boot mode is detected via /sys/firmware/efi.
  - Root and ESP detection use findmnt, lsblk, and /etc/fstab heuristics only;
    results are best-effort and may not capture every edge case.

TEXT;

    echo $help;
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(inventoryBootEnvMain($argv));
}

