<?php

declare(strict_types=1);

namespace mcxForge\Tests;

require_once __DIR__ . '/../common/testCase.php';

// Autoload all *Test.php in this directory.
foreach (glob(__DIR__ . '/*Test.php') as $testFile) {
    require_once $testFile;
}

// Collect declared classes that extend our base.
$classes = array_filter(
    get_declared_classes(),
    static function (string $class): bool {
        return is_subclass_of($class, testCase::class);
    }
);

$total = 0;
$failures = 0;

foreach ($classes as $class) {
    /** @var testCase $instance */
    $instance = new $class();
    foreach ($instance->run() as [$status, $method, $message]) {
        $total++;
        if ($status) {
            echo "[PASS] {$class}::{$method}\n";
        } else {
            $failures++;
            echo "[FAIL] {$class}::{$method} - {$message}\n";
        }
    }
}

echo PHP_EOL . "Tests: {$total}, Failures: {$failures}" . PHP_EOL;
exit($failures === 0 ? 0 : 1);

