<?php

declare(strict_types=1);

namespace mcxForge\Tests;

require_once __DIR__ . '/../common/testCase.php';

final class ciWorkflowConfigTest extends testCase
{
    private function readFile(string $relativePath): string
    {
        $root = realpath(__DIR__ . '/../../..');
        $path = $root . '/' . ltrim($relativePath, '/');
        $contents = @file_get_contents($path);
        $this->assertTrue($contents !== false, "Expected to read {$relativePath}");

        return (string) $contents;
    }

    public function testCiWorkflowRunsDevelopmentTestScript(): void
    {
        $yaml = $this->readFile('.github/workflows/ci.yml');

        $this->assertTrue(strpos($yaml, 'bash development/testing/test.sh') !== false);
    }

    public function testCiWorkflowInstallsExpectedTools(): void
    {
        $yaml = $this->readFile('.github/workflows/ci.yml');

        $this->assertTrue(strpos($yaml, 'curl shellcheck shfmt socat') !== false);
    }

    public function testCiWorkflowUsesPhp82(): void
    {
        $yaml = $this->readFile('.github/workflows/ci.yml');

        $this->assertTrue(strpos($yaml, "php-version: '8.2'") !== false);
    }
}
