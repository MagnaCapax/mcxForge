<?php

declare(strict_types=1);

namespace mcxForge;

require_once __DIR__ . '/Logger.php';

if (!defined('EXIT_OK')) {
    define('EXIT_OK', 0);
}
if (!defined('EXIT_ERROR')) {
    define('EXIT_ERROR', 1);
}

/**
 * StorageWipeRunner
 *
 * Core implementation for the storageWipe.php entrypoint.
 *
 * Responsibilities:
 *  - Discover block devices suitable for wiping.
 *  - Interactively confirm per-device wipes by default.
 *  - Build a wipe plan per device:
 *      - wipefs -a
 *      - blkdiscard
 *      - dd header zeroing
 *      - optional multi-pass full overwrites
 *      - optional hdparm secure erase
 *      - optional random write loops
 *  - Execute or dry-run the plan with clear, structured output.
 *
 * This tool is intentionally destructive when used without --dry-run
 * and with explicit confirmation flags. Tests MUST only exercise the
 * dry-run behaviour and must never perform real wipes.
 */
final class StorageWipeRunner
{
    /**
     * @param array<int,string> $argv
     */
    public static function run(array $argv): int
    {
        Logger::initStreamLogging();

        [$options, $error] = self::parseArguments($argv);
        if ($error !== null) {
            Logger::logStderr("Error: {$error}\n");
            return EXIT_ERROR;
        }

        if ($options['dryRun']) {
            echo "DRY-RUN: no destructive commands will be executed.\n";
            echo "DRY-RUN: printing planned wipe commands only.\n\n";
        }

        $devices = self::collectDevices($options);
        if (count($devices) === 0) {
            Logger::logStderr("Error: no block devices found to wipe.\n");
            return EXIT_ERROR;
        }

        return self::runForDevices($devices, $options);
    }

    /**
     * @param array<int,string> $argv
     * @return array{0:array<string,mixed>,1:?string}
     */
    private static function parseArguments(array $argv): array
    {
        $options = [
            'dryRun' => false,
            'confirmAll' => false,
            'randomDataWrite' => false,
            'randomDurationSeconds' => 300,
            'randomWorkersPerDevice' => 2,
            'secureErase' => false,
            'passes' => 0,
            'includeSystemDevice' => false,
            'devices' => [],
        ];

        $args = $argv;
        array_shift($args); // script name

        foreach ($args as $arg) {
            if ($arg === '--help' || $arg === '-h') {
                self::printHelp();
                exit(EXIT_OK);
            }

            if ($arg === '--dry-run') {
                $options['dryRun'] = true;
                continue;
            }

            if ($arg === '--confirm-all') {
                $options['confirmAll'] = true;
                continue;
            }

            if ($arg === '--random-data-write') {
                $options['randomDataWrite'] = true;
                continue;
            }

            if ($arg === '--secure-erase') {
                $options['secureErase'] = true;
                continue;
            }

            if ($arg === '--include-system-device') {
                $options['includeSystemDevice'] = true;
                continue;
            }

            if (str_starts_with($arg, '--device=')) {
                $value = substr($arg, strlen('--device='));
                if ($value === '') {
                    return [$options, "empty value for --device"];
                }
                $options['devices'][] = $value;
                continue;
            }

            if (str_starts_with($arg, '--passes=')) {
                $value = substr($arg, strlen('--passes='));
                if (!ctype_digit($value) || (int) $value < 1) {
                    return [$options, "invalid --passes value '{$value}', must be integer >= 1"];
                }
                $options['passes'] = (int) $value;
                continue;
            }

            if (str_starts_with($arg, '--random-duration-seconds=')) {
                $value = substr($arg, strlen('--random-duration-seconds='));
                if (!ctype_digit($value) || (int) $value < 1) {
                    return [$options, "invalid --random-duration-seconds value '{$value}', must be integer >= 1"];
                }
                $options['randomDurationSeconds'] = (int) $value;
                continue;
            }

            if (str_starts_with($arg, '--random-workers=')) {
                $value = substr($arg, strlen('--random-workers='));
                if (!ctype_digit($value) || (int) $value < 1) {
                    return [$options, "invalid --random-workers value '{$value}', must be integer >= 1"];
                }
                $options['randomWorkersPerDevice'] = (int) $value;
                continue;
            }

            return [$options, "unrecognized argument '{$arg}'. Use --help for usage."];
        }

        return [$options, null];
    }

    /**
     * @param array<string,mixed> $options
     * @return array<int,array<string,mixed>>
     */
    private static function collectDevices(array $options): array
    {
        $overrideJson = getenv('MCXFORGE_STORAGE_WIPE_LSBLK_JSON');
        if ($overrideJson !== false && trim($overrideJson) !== '') {
            $decoded = json_decode($overrideJson, true);
        } else {
            $cmd = 'lsblk -J -b -d -o NAME,TYPE,SIZE,MODEL,ROTA 2>/dev/null';
            $output = @shell_exec($cmd);
            $decoded = is_string($output) ? json_decode($output, true) : null;
        }

        if (!is_array($decoded) || !isset($decoded['blockdevices']) || !is_array($decoded['blockdevices'])) {
            Logger::logStderr("Error: failed to obtain lsblk block device information.\n");
            return [];
        }

        /** @var array<int,array<string,mixed>> $blockdevices */
        $blockdevices = $decoded['blockdevices'];

        $systemDiskName = self::detectSystemDiskName();
        $devices = [];

        foreach ($blockdevices as $dev) {
            $type = strtolower((string) ($dev['type'] ?? ''));
            if ($type !== 'disk') {
                continue;
            }

            $name = (string) ($dev['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $path = '/dev/' . $name;

            if (!empty($options['devices'])) {
                $explicitMatch = false;
                foreach ($options['devices'] as $wanted) {
                    if ($wanted === $path || $wanted === $name) {
                        $explicitMatch = true;
                        break;
                    }
                }
                if (!$explicitMatch) {
                    continue;
                }
            }

            $sizeRaw = $dev['size'] ?? 0;
            $sizeBytes = is_numeric($sizeRaw) ? (int) $sizeRaw : 0;
            $sizeGiB = $sizeBytes > 0 ? (int) round($sizeBytes / (1024 * 1024 * 1024)) : 0;

            $modelRaw = $dev['model'] ?? '';
            $model = is_string($modelRaw) && trim($modelRaw) !== '' ? trim($modelRaw) : 'UNKNOWN';

            $rotaRaw = $dev['rota'] ?? null;
            $rota = is_numeric($rotaRaw) ? (int) $rotaRaw : null;
            $isSsd = $rota === 0;

            $isSystem = ($systemDiskName !== null && $name === $systemDiskName);
            if ($isSystem && !$options['includeSystemDevice']) {
                Logger::logStderr("Info: skipping system disk {$path} (contains '/'). Use --include-system-device to include it.\n");
                continue;
            }

            $devices[] = [
                'name' => $name,
                'path' => $path,
                'sizeBytes' => $sizeBytes,
                'sizeGiB' => $sizeGiB,
                'model' => $model,
                'isSsd' => $isSsd,
                'isSystem' => $isSystem,
            ];
        }

        if (!empty($options['devices']) && count($devices) === 0) {
            Logger::logStderr("Error: no matching block devices found for requested --device arguments.\n");
        }

        return $devices;
    }

    /**
     * Attempt to detect the name (e.g. "sda") of the disk that backs '/'.
     *
     * @return string|null
     */
    private static function detectSystemDiskName(): ?string
    {
        $source = @shell_exec('findmnt -n -o SOURCE / 2>/dev/null');
        if (!is_string($source) || trim($source) === '') {
            $df = @shell_exec('df --output=source / 2>/dev/null | tail -n 1');
            if (!is_string($df) || trim($df) === '') {
                return null;
            }
            $source = $df;
        }

        $source = trim($source);
        if ($source === '') {
            return null;
        }

        $cmdPk = 'lsblk -n -o PKNAME ' . escapeshellarg($source) . ' 2>/dev/null';
        $pkname = @shell_exec($cmdPk);
        if (is_string($pkname) && trim($pkname) !== '') {
            return trim($pkname);
        }

        $cmdName = 'lsblk -n -o NAME ' . escapeshellarg($source) . ' 2>/dev/null';
        $name = @shell_exec($cmdName);
        if (is_string($name) && trim($name) !== '') {
            return trim($name);
        }

        return null;
    }

    /**
     * @param array<int,array<string,mixed>> $devices
     * @param array<string,mixed> $options
     */
    private static function runForDevices(array $devices, array $options): int
    {
        $anythingExecuted = false;
        $overallSuccess = true;

        foreach ($devices as $device) {
            $path = (string) $device['path'];
            $sizeGiB = (int) $device['sizeGiB'];
            $model = (string) $device['model'];
            $isSsd = (bool) $device['isSsd'];

            echo "=== Device {$path} ({$sizeGiB}GiB; {$model}) ===\n";

            if (!$options['confirmAll']) {
                $confirmed = self::confirmDevice($path);
                if (!$confirmed) {
                    echo "Skipping {$path} by user choice.\n\n";
                    continue;
                }
            } else {
                echo "--confirm-all supplied; wiping without interactive prompt.\n";
            }

            if ($isSsd && ($options['passes'] > 1 || $options['randomDataWrite'])) {
                Logger::logStderr(
                    "Warning: device {$path} appears to be SSD (non-rotational); multiple overwrite passes or random writes will increase wear. " .
                    "Prefer --secure-erase where supported.\n"
                );
            }

            $anythingExecuted = true;

            $plan = self::buildWipePlanForDevice($device, $options);
            $deviceCoverage = false;

            foreach ($plan as $step) {
                $description = (string) $step['description'];
                $command = (string) $step['command'];
                $coversWholeDevice = (bool) $step['coversWholeDevice'];

                echo "  - {$description}\n";
                $success = self::runCommand($command, $options['dryRun']);
                if (!$success) {
                    $overallSuccess = false;
                    Logger::logStderr("Error: command failed for {$path}: {$command}\n");
                } elseif ($coversWholeDevice) {
                    $deviceCoverage = true;
                }
            }

            if (!$deviceCoverage) {
                Logger::logStderr(
                    "WARNING: device {$path} was not fully overwritten by any single step; residual data may remain. " .
                    "Consider additional full-device passes or secure erase.\n"
                );
            }

            echo "\n";
        }

        if (!$anythingExecuted) {
            Logger::logStderr("Info: no devices were selected for wiping.\n");
            return EXIT_ERROR;
        }

        return $overallSuccess ? EXIT_OK : EXIT_ERROR;
    }

    private static function confirmDevice(string $path): bool
    {
        $stdin = fopen('php://stdin', 'r');
        if ($stdin === false) {
            Logger::logStderr("Error: unable to read from STDIN for confirmation.\n");
            return false;
        }

        echo "Wipe ALL DATA on {$path}? Type 'yes' to confirm: ";
        $line = fgets($stdin);
        fclose($stdin);

        if ($line === false) {
            return false;
        }

        return trim(strtolower($line)) === 'yes';
    }

    /**
     * @param array<string,mixed> $device
     * @param array<string,mixed> $options
     * @return array<int,array<string,mixed>>
     */
    private static function buildWipePlanForDevice(array $device, array $options): array
    {
        $path = (string) $device['path'];
        $sizeBytes = (int) $device['sizeBytes'];
        $sizeMiB = $sizeBytes > 0 ? (int) floor($sizeBytes / (1024 * 1024)) : 0;
        if ($sizeMiB < 1) {
            $sizeMiB = 1;
        }

        $plan = [];

        // Always run wipefs, blkdiscard, and header zeroing in that order.
        $plan[] = [
            'description' => "wipefs -a on {$path}",
            'command' => 'wipefs -a ' . escapeshellarg($path),
            'coversWholeDevice' => false,
        ];

        $plan[] = [
            'description' => "blkdiscard on {$path} (if supported)",
            'command' => 'blkdiscard ' . escapeshellarg($path),
            'coversWholeDevice' => false,
        ];

        $plan[] = [
            'description' => "dd zero header (20MiB) on {$path}",
            'command' => 'dd if=/dev/zero of=' . escapeshellarg($path) . ' bs=1M count=20 conv=fsync,notrunc status=none',
            'coversWholeDevice' => false,
        ];

        // Optional multi-pass full-device overwrites.
        if ($options['passes'] > 0) {
            for ($i = 1; $i <= $options['passes']; $i++) {
                $plan[] = [
                    'description' => "full-device zero overwrite pass {$i} on {$path}",
                    'command' => 'dd if=/dev/zero of=' . escapeshellarg($path) .
                        ' bs=1M count=' . $sizeMiB . ' conv=fsync,notrunc status=none',
                    'coversWholeDevice' => true,
                ];
            }
        }

        // Optional secure erase via hdparm. We still run the basic steps; this is additive.
        if ($options['secureErase']) {
            $plan[] = [
                'description' => "hdparm identify {$path}",
                'command' => 'hdparm -I ' . escapeshellarg($path),
                'coversWholeDevice' => false,
            ];
            $plan[] = [
                'description' => "hdparm security-set-pass on {$path}",
                'command' => 'hdparm --user-master u --security-set-pass mcxforge ' . escapeshellarg($path),
                'coversWholeDevice' => false,
            ];
            $plan[] = [
                'description' => "hdparm security-erase on {$path}",
                'command' => 'hdparm --user-master u --security-erase mcxforge ' . escapeshellarg($path),
                'coversWholeDevice' => true,
            ];
        }

        // Optional random write loops.
        if ($options['randomDataWrite']) {
            $script = self::buildRandomWriteScript();
            $duration = (int) $options['randomDurationSeconds'];
            $workers = (int) $options['randomWorkersPerDevice'];

            $plan[] = [
                'description' => "random data write with {$workers} workers for {$duration}s on {$path}",
                'command' => 'bash -c ' . escapeshellarg($script) . ' -- ' .
                    escapeshellarg($path) . ' ' . $duration . ' ' . $workers,
                'coversWholeDevice' => false,
            ];
        }

        return $plan;
    }

    private static function buildRandomWriteScript(): string
    {
        // Nowdoc to avoid PHP variable interpolation inside the shell script.
        $script = <<<'BASH'
dev="$1"
duration="$2"
workers="$3"
if [ -z "$dev" ] || [ -z "$duration" ]; then
  echo "random-write: missing arguments" >&2
  exit 1
fi

if [ -z "$workers" ]; then
  workers=2
fi

size_bytes=$(blockdev --getsize64 "$dev" 2>/dev/null)
if [ -z "$size_bytes" ] || [ "$size_bytes" -le 0 ] 2>/dev/null; then
  echo "random-write: could not determine size for $dev" >&2
  exit 1
fi

size_mib=$((size_bytes / 1024 / 1024))
if [ "$size_mib" -le 0 ]; then
  size_mib=1
fi

worker_loop() {
  local end
  end=$((SECONDS + duration))
  while [ "$SECONDS" -lt "$end" ]; do
    count=$(( (RANDOM % 64) + 1 ))
    if [ "$count" -gt "$size_mib" ]; then
      count="$size_mib"
    fi
    max_offset=$((size_mib - count))
    if [ "$max_offset" -le 0 ]; then
      offset=0
    else
      offset=$((RANDOM % max_offset))
    fi
    dd if=/dev/zero of="$dev" bs=1M count="$count" seek="$offset" conv=notrunc oflag=direct status=none
  done
}

i=1
while [ "$i" -le "$workers" ]; do
  worker_loop &
  i=$((i + 1))
done
wait
BASH;

        return $script;
    }

    private static function runCommand(string $command, bool $dryRun): bool
    {
        if ($dryRun) {
            echo "    [dry-run] {$command}\n";
            return true;
        }

        echo "    [exec] {$command}\n";
        $exitCode = null;
        system($command, $exitCode);

        return $exitCode === 0;
    }

    private static function printHelp(): void
    {
        $help = <<<TEXT
Usage: storageWipe.php [options]

Destroy data on block devices by running a sequence of wipe operations:
  - wipefs -a
  - blkdiscard
  - dd header zeroing
  - optional multi-pass full-device overwrites
  - optional hdparm secure erase
  - optional random write loops

By default, the tool discovers all non-loop "disk" devices via lsblk and
asks for explicit confirmation per device. The disk containing the current
root filesystem ("/") is skipped unless --include-system-device is given.

Options:
  --dry-run                  Print planned commands only; do NOT execute them.
  --confirm-all              Do not prompt per device; wipe everything selected.
  --device=PATH              Restrict wiping to the given device path or name.
                             May be specified multiple times.
  --include-system-device    Allow wiping the disk that backs '/'.
  --passes=N                 Number of full-device overwrite passes (N >= 1).
                             N=1 (default) only uses blkdiscard + header zeroing.
                             N>1 adds N full zero passes over the device.
  --secure-erase             Attempt hdparm-based ATA secure erase in addition
                             to the basic wipe steps (where supported).
  --random-data-write        After the basic wipe, run random-position zero
                             writes in time-limited loops.
  --random-duration-seconds=N
                             Duration for random write workers (default: 300).
  --random-workers=N         Number of random write workers per device (default: 2).
  -h, --help                 Show this help message.

WARNING:
  - This tool is intentionally destructive. Without --dry-run, any confirmed
    device will have its contents irreversibly destroyed.
  - Tests and CI MUST ONLY use --dry-run; no automated test may perform real
    wipes without explicit, isolated, opt-in environments.

TEXT;

        echo $help;
    }
}
