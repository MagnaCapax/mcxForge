<?php

declare(strict_types=1);

namespace mcxForge\Tests;

require_once __DIR__ . '/../common/testCase.php';

final class testWorkflowScriptTest extends testCase
{
    private function readFile(string $relativePath): string
    {
        $root = realpath(__DIR__ . '/../../..');
        $path = $root . '/' . ltrim($relativePath, '/');
        $contents = @file_get_contents($path);
        $this->assertTrue($contents !== false, "Expected to read {$relativePath}");

        return (string) $contents;
    }

    public function testTestScriptHasStepHeadings(): void
    {
        $script = $this->readFile('development/testing/test.sh');

        $this->assertTrue(strpos($script, '0) Tool checks') !== false);
        $this->assertTrue(strpos($script, '1) PHP lint') !== false);
        $this->assertTrue(strpos($script, '2) PHP dev tests') !== false);
        $this->assertTrue(strpos($script, '3) Shell script lint') !== false);
        $this->assertTrue(strpos($script, '4) Static analysis (phpstan)') !== false);
        $this->assertTrue(strpos($script, 'LOC snapshot') !== false);
    }

    public function testTestScriptInvokesExpectedHelpers(): void
    {
        $script = $this->readFile('development/testing/test.sh');

        $this->assertTrue(strpos($script, 'check-tools.sh') !== false);
        $this->assertTrue(strpos($script, 'php-lint.sh') !== false);
        $this->assertTrue(strpos($script, 'shell-lint.sh') !== false);
        $this->assertTrue(strpos($script, 'phpstan.sh') !== false);
        $this->assertTrue(strpos($script, 'loc.sh') !== false);
    }

    public function testCheckToolsScriptRequiresPhpAndCurl(): void
    {
        $script = $this->readFile('development/testing/check-tools.sh');

        $this->assertTrue(strpos($script, 'require_tool php') !== false);
        $this->assertTrue(strpos($script, 'require_tool curl') !== false);
    }

    public function testPhpStanScriptTargetsBinAndLib(): void
    {
        $script = $this->readFile('development/testing/phpstan.sh');

        $this->assertTrue(strpos($script, 'analyse -c "$CONF" "$ROOT_DIR/bin" "$ROOT_DIR/lib"') !== false);
    }

    public function testLocScriptCountsExpectedCategories(): void
    {
        $script = $this->readFile('development/testing/loc.sh');

        $this->assertTrue(strpos($script, 'Bin PHP') !== false);
        $this->assertTrue(strpos($script, 'Tests PHP') !== false);
        $this->assertTrue(strpos($script, 'Bash scripts') !== false);
        $this->assertTrue(strpos($script, 'Docs ADR') !== false);
        $this->assertTrue(strpos($script, 'Docs other') !== false);
    }
}

