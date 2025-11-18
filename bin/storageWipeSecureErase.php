#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * storageWipeSecureErase.php
 *
 * Convenience wrapper around storageWipe.php that prefers
 * ATA secure erase (hdparm) in addition to the baseline
 * wipe sequence.
 *
 * All safety flags and behaviours are inherited from the core
 * storageWipe runner (per-device confirmation by default,
 * --dry-run support, system disk skipped unless explicitly
 * included, etc.).
 */

require_once __DIR__ . '/../lib/php/StorageWipe.php';

$argvPreset = $argv;
array_splice($argvPreset, 1, 0, '--secure-erase');

exit(\mcxForge\StorageWipeRunner::run($argvPreset));

