#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * inventoryCPU.php
 *
 * Collect CPU inventory for the current host (vendor, model, stepping,
 * topology, cache sizes, and selected feature flags). Supports human‑readable
 * output (default), JSON, and PHP serialize formats.
 *
 * This script is read‑only and intended for use on live systems to feed
 * qualification and diagnostics flows.
 */

if (!defined('EXIT_OK')) {
    define('EXIT_OK', 0);
}
if (!defined('EXIT_ERROR')) {
    define('EXIT_ERROR', 1);
}

function inventoryCPUMain(array $argv): int
{
    [$format, $colorEnabled] = inventoryCPUParseArguments($argv);

    $info = collectCpuInventory();

    switch ($format) {
        case 'json':
            inventoryCPURenderJson($info);
            break;
        case 'php':
            inventoryCPURenderPhp($info);
            break;
        case 'human':
        default:
            inventoryCPURenderHuman($info, $colorEnabled);
            break;
    }

    return EXIT_OK;
}

/**
 * @return array{0:string,1:bool}
 */
function inventoryCPUParseArguments(array $argv): array
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
            inventoryCPUPrintHelp();
            exit(EXIT_OK);
        }

        if (str_starts_with($arg, '--format=')) {
            $value = substr($arg, strlen('--format='));
            if (!in_array($value, ['human', 'json', 'php'], true)) {
                fwrite(STDERR, "Error: unsupported format '$value'. Use human, json, or php.\n");
                exit(EXIT_ERROR);
            }
            $format = $value;
            continue;
        }

        if ($arg === '--no-color') {
            $colorEnabled = false;
            continue;
        }

        fwrite(STDERR, "Error: unrecognized argument '$arg'. Use --help for usage.\n");
        exit(EXIT_ERROR);
    }

    return [$format, $colorEnabled];
}

/**
 * @return array<string,mixed>
 */
function collectCpuInventory(): array
{
    $cpuinfo = inventoryCPUReadProcCpuinfo();

    $topology = inventoryCPUReadTopology();
    $cache = inventoryCPUReadCacheInfo();
    $flags = inventoryCPUParseFlags($cpuinfo['flags'] ?? '');
    $env = inventoryCPUDetectEnvironment($cpuinfo['flags'] ?? '');
    $iommu = inventoryCPUDetectIommu();
    $sriov = inventoryCPUDetectSriov();

    return [
        'vendor' => $cpuinfo['vendor_id'] ?? null,
        'modelName' => $cpuinfo['model_name'] ?? null,
        'family' => isset($cpuinfo['cpu_family']) ? (int) $cpuinfo['cpu_family'] : null,
        'model' => isset($cpuinfo['model']) ? (int) $cpuinfo['model'] : null,
        'stepping' => isset($cpuinfo['stepping']) ? (int) $cpuinfo['stepping'] : null,
        'microcode' => $cpuinfo['microcode'] ?? null,
        'mhz' => isset($cpuinfo['cpu_MHz']) ? (float) $cpuinfo['cpu_MHz'] : null,
        'bogoMips' => isset($cpuinfo['bogomips']) ? (float) $cpuinfo['bogomips'] : null,
        'sockets' => $topology['sockets'],
        'physicalCores' => $topology['physicalCores'],
        'logicalCores' => $topology['logicalCores'],
        'coresPerSocket' => $topology['coresPerSocket'],
        'threadsPerCore' => $topology['threadsPerCore'],
        'cache' => $cache,
        'virtualization' => $flags['virtualization'],
        'features' => [
            'aes' => $flags['aes'],
            'avx' => $flags['avx'],
            'avx2' => $flags['avx2'],
            'sse4_2' => $flags['sse4_2'],
            'smep' => $flags['smep'],
            'smap' => $flags['smap'],
        ],
        'environment' => $env,
        'iommu' => $iommu,
        'sriov' => $sriov,
    ];
}

/**
 * @return array<string,string>
 */
function inventoryCPUReadProcCpuinfo(): array
{
    if (!is_readable('/proc/cpuinfo')) {
        return [];
    }

    $contents = file_get_contents('/proc/cpuinfo');
    if ($contents === false || $contents === '') {
        return [];
    }

    $sections = preg_split('/\n\s*\n/', $contents);
    if (!is_array($sections) || count($sections) === 0) {
        return [];
    }

    $first = $sections[0];
    $lines = explode("\n", $first);

    $data = [];
    foreach ($lines as $line) {
        if (strpos($line, ':') === false) {
            continue;
        }
        [$key, $value] = array_map('trim', explode(':', $line, 2));
        if ($key === '') {
            continue;
        }
        $data[$key] = $value;
    }

    $normalized = [];
    foreach ($data as $key => $value) {
        $normalizedKey = str_replace(' ', '_', strtolower($key));
        $normalized[$normalizedKey] = $value;
    }

    return [
        'vendor_id' => $data['vendor_id'] ?? null,
        'model_name' => $data['model name'] ?? null,
        'cpu_family' => $data['cpu family'] ?? null,
        'model' => $data['model'] ?? null,
        'stepping' => $data['stepping'] ?? null,
        'microcode' => $data['microcode'] ?? null,
        'cpu_MHz' => $data['cpu MHz'] ?? null,
        'bogomips' => $data['bogomips'] ?? null,
        'flags' => $data['flags'] ?? '',
    ];
}

/**
 * @return array{logicalCores:int,physicalCores:int,sockets:int,coresPerSocket:int,threadsPerCore:int}
 */
function inventoryCPUReadTopology(): array
{
    $cpuDirs = glob('/sys/devices/system/cpu/cpu[0-9]*', GLOB_NOSORT) ?: [];

    $logicalCores = 0;
    $packages = [];
    $coreKeys = [];

    foreach ($cpuDirs as $dir) {
        if (!is_dir($dir)) {
            continue;
        }

        $cpuId = basename($dir);
        if (!preg_match('/^cpu([0-9]+)$/', $cpuId)) {
            continue;
        }

        $logicalCores++;

        $pkgPath = $dir . '/topology/physical_package_id';
        $corePath = $dir . '/topology/core_id';

        $pkgId = is_readable($pkgPath) ? trim((string) file_get_contents($pkgPath)) : '0';
        $coreId = is_readable($corePath) ? trim((string) file_get_contents($corePath)) : (string) $logicalCores;

        $packages[$pkgId] = true;
        $coreKeys[$pkgId . ':' . $coreId] = true;
    }

    if ($logicalCores === 0) {
        $logicalCores = 1;
    }

    $physicalCores = count($coreKeys) > 0 ? count($coreKeys) : $logicalCores;
    $sockets = count($packages) > 0 ? count($packages) : 1;

    $coresPerSocket = $sockets > 0 ? (int) max(1, intdiv($physicalCores, $sockets)) : $physicalCores;
    $threadsPerCore = $physicalCores > 0 ? (int) max(1, intdiv($logicalCores, $physicalCores)) : 1;

    return [
        'logicalCores' => $logicalCores,
        'physicalCores' => $physicalCores,
        'sockets' => $sockets,
        'coresPerSocket' => $coresPerSocket,
        'threadsPerCore' => $threadsPerCore,
    ];
}

/**
 * @return array<string,string|null>
 */
function inventoryCPUReadCacheInfo(): array
{
    $base = '/sys/devices/system/cpu/cpu0/cache';
    if (!is_dir($base)) {
        return [
            'L1d' => null,
            'L1i' => null,
            'L2' => null,
            'L3' => null,
        ];
    }

    $result = [
        'L1d' => null,
        'L1i' => null,
        'L2' => null,
        'L3' => null,
    ];

    $indexes = glob($base . '/index*', GLOB_NOSORT) ?: [];
    foreach ($indexes as $idx) {
        if (!is_dir($idx)) {
            continue;
        }

        $levelPath = $idx . '/level';
        $typePath = $idx . '/type';
        $sizePath = $idx . '/size';

        if (!is_readable($levelPath) || !is_readable($typePath) || !is_readable($sizePath)) {
            continue;
        }

        $level = trim((string) file_get_contents($levelPath));
        $type = strtolower(trim((string) file_get_contents($typePath)));
        $size = trim((string) file_get_contents($sizePath));

        if ($level === '1' && $type === 'data') {
            $result['L1d'] = $size;
        } elseif ($level === '1' && $type === 'instruction') {
            $result['L1i'] = $size;
        } elseif ($level === '2') {
            $result['L2'] = $size;
        } elseif ($level === '3') {
            $result['L3'] = $size;
        }
    }

    return $result;
}

/**
 * @return array{virtualization:?string,aes:bool,avx:bool,avx2:bool,sse4_2:bool,smep:bool,smap:bool}
 */
function inventoryCPUParseFlags(string $flags): array
{
    $parts = preg_split('/\s+/', trim($flags)) ?: [];
    $set = [];
    foreach ($parts as $flag) {
        if ($flag !== '') {
            $set[$flag] = true;
        }
    }

    $virtualization = null;
    if (isset($set['vmx'])) {
        $virtualization = 'vmx';
    } elseif (isset($set['svm'])) {
        $virtualization = 'svm';
    }

    return [
        'virtualization' => $virtualization,
        'aes' => isset($set['aes']),
        'avx' => isset($set['avx']),
        'avx2' => isset($set['avx2']),
        'sse4_2' => isset($set['sse4_2']),
        'smep' => isset($set['smep']),
        'smap' => isset($set['smap']),
    ];
}

/**
 * @return array{isVirtualMachine:bool,hypervisorVendor:?string,role:string}
 */
function inventoryCPUDetectEnvironment(string $flags): array
{
    $parts = preg_split('/\s+/', trim($flags)) ?: [];
    $flagSet = [];
    foreach ($parts as $flag) {
        if ($flag !== '') {
            $flagSet[$flag] = true;
        }
    }

    $isVm = isset($flagSet['hypervisor']);
    $virtExt = null;
    if (isset($flagSet['vmx'])) {
        $virtExt = 'vmx';
    } elseif (isset($flagSet['svm'])) {
        $virtExt = 'svm';
    }

    $vendor = inventoryCPUDetectHypervisorVendor();

    if ($isVm) {
        $role = 'guest';
    } elseif ($virtExt !== null) {
        $role = 'host';
    } else {
        $role = 'baremetal';
    }

    return [
        'isVirtualMachine' => $isVm,
        'hypervisorVendor' => $vendor,
        'role' => $role,
    ];
}

function inventoryCPUDetectHypervisorVendor(): ?string
{
    $candidates = [
        '/sys/class/dmi/id/product_name',
        '/sys/class/dmi/id/sys_vendor',
        '/sys/devices/virtual/dmi/id/product_name',
        '/sys/devices/virtual/dmi/id/sys_vendor',
    ];

    $patterns = [
        'KVM' => 'kvm',
        'QEMU' => 'qemu',
        'VMware' => 'vmware',
        'VirtualBox' => 'virtualbox',
        'Microsoft' => 'hyper-v',
        'Hyper-V' => 'hyper-v',
        'Xen' => 'xen',
    ];

    foreach ($candidates as $path) {
        if (!is_readable($path)) {
            continue;
        }
        $value = trim((string) file_get_contents($path));
        if ($value === '') {
            continue;
        }
        foreach ($patterns as $needle => $vendor) {
            if (stripos($value, $needle) !== false) {
                return $vendor;
            }
        }
    }

    return null;
}

/**
 * @return array{enabled:bool,groupCount:int}
 */
function inventoryCPUDetectIommu(): array
{
    $base = '/sys/kernel/iommu_groups';
    if (!is_dir($base)) {
        return ['enabled' => false, 'groupCount' => 0];
    }

    $groups = glob($base . '/group*', GLOB_NOSORT) ?: [];
    $count = 0;
    foreach ($groups as $groupDir) {
        if (!is_dir($groupDir)) {
            continue;
        }
        $devicesDir = $groupDir . '/devices';
        if (is_dir($devicesDir)) {
            $entries = glob($devicesDir . '/*', GLOB_NOSORT) ?: [];
            if (count($entries) > 0) {
                $count++;
            }
        }
    }

    $enabled = $count > 0;

    return [
        'enabled' => $enabled,
        'groupCount' => $count,
    ];
}

/**
 * @return array{hasSriovCapableDevices:bool}
 */
function inventoryCPUDetectSriov(): array
{
    $paths = glob('/sys/bus/pci/devices/*/sriov_totalvfs', GLOB_NOSORT) ?: [];

    foreach ($paths as $path) {
        if (!is_readable($path)) {
            continue;
        }
        $raw = trim((string) file_get_contents($path));
        if ($raw === '' || !ctype_digit($raw)) {
            continue;
        }
        if ((int) $raw > 0) {
            return ['hasSriovCapableDevices' => true];
        }
    }

    return ['hasSriovCapableDevices' => false];
}

/**
 * @param array<string,mixed> $info
 */
function inventoryCPURenderHuman(array $info, bool $colorEnabled): void
{
    $sectionColor = $colorEnabled ? "\033[1;34m" : '';
    $labelColor = $colorEnabled ? "\033[0;36m" : '';
    $valueColor = $colorEnabled ? "\033[0;37m" : '';
    $resetColor = $colorEnabled ? "\033[0m" : '';

    echo $sectionColor . "CPU Inventory" . $resetColor . PHP_EOL;

    $vendor = (string) ($info['vendor'] ?? '');
    $model = (string) ($info['modelName'] ?? '');
    $family = $info['family'] ?? null;
    $modelId = $info['model'] ?? null;
    $stepping = $info['stepping'] ?? null;

    echo sprintf(
        "%sVendor:%s %s%s%s\n",
        $labelColor,
        $resetColor,
        $valueColor,
        $vendor,
        $resetColor
    );
    echo sprintf(
        "%sModel:%s %s%s%s\n",
        $labelColor,
        $resetColor,
        $valueColor,
        $model,
        $resetColor
    );
    echo sprintf(
        "%sFamily/Model/Stepping:%s %s%s/%s/%s%s\n",
        $labelColor,
        $resetColor,
        $valueColor,
        $family ?? '?',
        $modelId ?? '?',
        $stepping ?? '?',
        $resetColor
    );

    $mhz = $info['mhz'] ?? null;
    $bogo = $info['bogoMips'] ?? null;
    echo sprintf(
        "%sFrequency (reported):%s %s%s MHz%s\n",
        $labelColor,
        $resetColor,
        $valueColor,
        $mhz !== null ? (string) $mhz : 'N/A',
        $resetColor
    );
    echo sprintf(
        "%sBogoMIPS:%s %s%s%s\n",
        $labelColor,
        $resetColor,
        $valueColor,
        $bogo !== null ? (string) $bogo : 'N/A',
        $resetColor
    );

    echo PHP_EOL;
    echo $sectionColor . "Topology" . $resetColor . PHP_EOL;
    echo sprintf(
        "  %sSockets:%s %s%d%s\n",
        $labelColor,
        $resetColor,
        $valueColor,
        (int) $info['sockets'],
        $resetColor
    );
    echo sprintf(
        "  %sPhysical cores:%s %s%d%s\n",
        $labelColor,
        $resetColor,
        $valueColor,
        (int) $info['physicalCores'],
        $resetColor
    );
    echo sprintf(
        "  %sLogical cores:%s %s%d%s\n",
        $labelColor,
        $resetColor,
        $valueColor,
        (int) $info['logicalCores'],
        $resetColor
    );
    echo sprintf(
        "  %sCores per socket:%s %s%d%s\n",
        $labelColor,
        $resetColor,
        $valueColor,
        (int) $info['coresPerSocket'],
        $resetColor
    );
    echo sprintf(
        "  %sThreads per core:%s %s%d%s\n",
        $labelColor,
        $resetColor,
        $valueColor,
        (int) $info['threadsPerCore'],
        $resetColor
    );

    echo PHP_EOL;
    echo $sectionColor . "Cache" . $resetColor . PHP_EOL;
    /** @var array<string,string|null> $cache */
    $cache = $info['cache'] ?? [];
    echo sprintf(
        "  %sL1d:%s %s%s%s\n",
        $labelColor,
        $resetColor,
        $valueColor,
        $cache['L1d'] ?? 'N/A',
        $resetColor
    );
    echo sprintf(
        "  %sL1i:%s %s%s%s\n",
        $labelColor,
        $resetColor,
        $valueColor,
        $cache['L1i'] ?? 'N/A',
        $resetColor
    );
    echo sprintf(
        "  %sL2:%s %s%s%s\n",
        $labelColor,
        $resetColor,
        $valueColor,
        $cache['L2'] ?? 'N/A',
        $resetColor
    );
    echo sprintf(
        "  %sL3:%s %s%s%s\n",
        $labelColor,
        $resetColor,
        $valueColor,
        $cache['L3'] ?? 'N/A',
        $resetColor
    );

    echo PHP_EOL;
    echo $sectionColor . "Features" . $resetColor . PHP_EOL;
    /** @var array<string,mixed> $features */
    $features = $info['features'] ?? [];
    $virtualization = $info['virtualization'] ?? null;
    echo sprintf(
        "  %sVirtualization:%s %s%s%s\n",
        $labelColor,
        $resetColor,
        $valueColor,
        $virtualization !== null ? (string) $virtualization : 'none',
        $resetColor
    );

    foreach (['aes', 'avx', 'avx2', 'sse4_2', 'smep', 'smap'] as $name) {
        $value = !empty($features[$name]) ? 'yes' : 'no';
        echo sprintf(
            "  %s%s:%s %s%s%s\n",
            $labelColor,
            $name,
            $resetColor,
            $valueColor,
            $value,
            $resetColor
        );
    }
}

/**
 * @param array<string,mixed> $info
 */
function inventoryCPURenderJson(array $info): void
{
    $encoded = json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        fwrite(STDERR, "Error: failed to encode JSON output.\n");
        exit(EXIT_ERROR);
    }

    echo $encoded . PHP_EOL;
}

/**
 * @param array<string,mixed> $info
 */
function inventoryCPURenderPhp(array $info): void
{
    echo serialize($info) . PHP_EOL;
}

function inventoryCPUPrintHelp(): void
{
    $help = <<<TEXT
Usage: inventoryCPU.php [--format=human|json|php] [--no-color]

Show CPU inventory including vendor, model, family/model/stepping, topology,
cache sizes, and selected feature flags.

Options:
  --format=human    Human-readable output (default).
  --format=json     JSON output.
  --format=php      PHP serialize() output of the same structure.
  --no-color        Disable ANSI colors in human output.
  -h, --help        Show this help message.

TEXT;

    echo $help;
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(inventoryCPUMain($argv));
}
