#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/benchmarkCpuGeekbench.php';

if (PHP_SAPI === 'cli') {
    exit(benchmarkGeekbenchMain($argv));
}

