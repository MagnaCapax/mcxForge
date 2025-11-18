<?php

declare(strict_types=1);

/**
 * Basic dry-run tests for storageWipe.php.
 *
 * These tests MUST NEVER perform real destructive operations. All invocations
 * of the storage wipe tool are made with --dry-run and with a synthetic lsblk
 * JSON payload to avoid depending on the host system's real devices.
 */

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

function assertContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

/**
 * @param array<string,string> $env
 * @param array<int,string> $args
 */
function runStorageWipeDryRun(array $env, array $args): string
{
    $cmdParts = [];
    foreach ($env as $key => $value) {
        $cmdParts[] = sprintf('%s=%s', $key, escapeshellarg($value));
    }

    $cmdParts[] = 'php';
    $cmdParts[] = 'bin/storageWipe.php';
    foreach ($args as $arg) {
        $cmdParts[] = escapeshellarg($arg);
    }

    $cmd = implode(' ', $cmdParts);
    $output = [];
    $exitCode = 0;

    exec($cmd, $output, $exitCode);

    assertTrue($exitCode === 0, 'storageWipe.php --dry-run should exit 0');

    return implode("\n", $output) . "\n";
}

// Synthetic lsblk JSON with a single disk "sda".
$lsblk = json_encode([
    'blockdevices' => [
        [
            'name' => 'sda',
            'type' => 'disk',
            'size' => 100 * 1024 * 1024 * 1024,
            'model' => 'FAKE-DISK',
            'rota' => 1,
        ],
    ],
], JSON_THROW_ON_ERROR);

// Basic dry-run wipe with confirm-all.
$output = runStorageWipeDryRun(
    ['MCXFORGE_STORAGE_WIPE_LSBLK_JSON' => $lsblk],
    ['--dry-run', '--confirm-all']
);

assertContains("wipefs -a '/dev/sda'", $output, 'Expected wipefs dry-run command for /dev/sda');
assertContains("blkdiscard '/dev/sda'", $output, 'Expected blkdiscard dry-run command for /dev/sda');
assertContains("dd if=/dev/zero of='/dev/sda' bs=1M count=20", $output, 'Expected header dd command for /dev/sda');

// Dry-run with random-data-write should include the bash random write worker script.
$outputRandom = runStorageWipeDryRun(
    ['MCXFORGE_STORAGE_WIPE_LSBLK_JSON' => $lsblk],
    ['--dry-run', '--confirm-all', '--random-data-write']
);

assertContains('random data write with 2 workers', $outputRandom, 'Expected random write workers description');
assertContains('bash -c', $outputRandom, 'Expected bash -c random write command');

echo "StorageWipeDryRunTest: OK\n";
