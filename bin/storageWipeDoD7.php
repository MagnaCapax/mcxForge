#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * storageWipeDoD7.php
 *
 * Convenience wrapper around storageWipe.php for a 7-pass
 * full-device overwrite (in addition to the baseline wipefs,
 * blkdiscard, and header zeroing).
 *
 * All safety flags and behaviours are inherited from the core
 * storageWipe runner (per-device confirmation by default,
 * --dry-run support, system disk skipped unless explicitly
 * included, etc.).
 *
 * @author Aleksi Ursin
 */

require_once __DIR__ . '/../lib/php/StorageWipe.php';

$argvPreset = $argv;
array_splice($argvPreset, 1, 0, '--passes=7');

exit(\mcxForge\StorageWipeRunner::run($argvPreset));
