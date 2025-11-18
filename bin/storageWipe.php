#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * storageWipe.php
 *
 * High-level drive wipe utility for mcxForge.
 *
 * Default behaviour:
 *  - Discover all non-loop "disk" devices via lsblk.
 *  - Skip the disk that backs '/' unless --include-system-device is given.
 *  - For each device, prompt the operator for explicit confirmation.
 *  - When confirmed (or when --confirm-all is used), run:
 *      - wipefs -a
 *      - blkdiscard
 *      - dd header zeroing (20MiB)
 *      - optional multi-pass full-device overwrites (--passes)
 *      - optional hdparm secure erase (--secure-erase)
 *      - optional random write loops (--random-data-write)
 *
 * Use --dry-run to inspect the planned commands without executing them.
 *
 * This tool is intentionally destructive when used without --dry-run.
 * Tests MUST only exercise the dry-run behaviour.
 *
 * @author Aleksi Ursin
 */

require_once __DIR__ . '/../lib/php/StorageWipe.php';

exit(\mcxForge\StorageWipeRunner::run($argv));
