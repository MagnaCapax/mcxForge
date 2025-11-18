#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * storageWipeRandomScrub.php
 *
 * Convenience wrapper around storageWipe.php that adds
 * time-limited random-position zero writes after the
 * baseline wipe sequence.
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
array_splice($argvPreset, 1, 0, '--random-data-write');

exit(\mcxForge\StorageWipeRunner::run($argvPreset));
