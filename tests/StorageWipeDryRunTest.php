<?php

declare(strict_types=1);

/**
 * Basic smoke test for storageWipe.php.
 *
 * This test MUST NEVER perform real destructive operations. It only exercises
 * the --help path, which does not touch any devices.
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

$cmd = 'php bin/storageWipe.php --help';
$output = [];
$exitCode = 0;
exec($cmd, $output, $exitCode);
$text = implode("\n", $output) . "\n";

assertTrue($exitCode === 0, 'storageWipe.php --help should exit 0');
assertContains('Usage: storageWipe.php', $text, 'Expected usage line in help output');
assertContains('--dry-run', $text, 'Expected --dry-run flag in help output');

echo "StorageWipeDryRunTest: OK\n";
