<?php

declare(strict_types=1);

namespace mcxForge\Tests;

require_once __DIR__ . '/../common/testCase.php';

final class ciCodexScriptTest extends testCase
{
    private function readFile(string $relativePath): string
    {
        $root = realpath(__DIR__ . '/../../..');
        $path = $root . '/' . ltrim($relativePath, '/');
        $contents = @file_get_contents($path);
        $this->assertTrue($contents !== false, "Expected to read {$relativePath}");

        return (string) $contents;
    }

    public function testCiCodexScriptMentionsStrictRailsMode(): void
    {
        $script = $this->readFile('development/cli/ci-codex.sh');

        $this->assertTrue(strpos($script, 'mcxForge CI Assist — Strict Rails Mode') !== false);
        $this->assertTrue(strpos($script, 'Read First (do not proceed until read)') !== false);
    }

    public function testCiCodexScriptUsesGhRunCommands(): void
    {
        $script = $this->readFile('development/cli/ci-codex.sh');

        $this->assertTrue(strpos($script, 'gh run list') !== false);
        $this->assertTrue(strpos($script, 'gh run view') !== false);
        $this->assertTrue(strpos($script, 'gh run download') !== false);
    }

    public function testCiCodexScriptListsContextPathsInPrompt(): void
    {
        $script = $this->readFile('development/cli/ci-codex.sh');

        $this->assertTrue(strpos($script, 'Context to open (paths in this workspace):') !== false);
        $this->assertTrue(strpos($script, 'Do not inline these; read them directly from disk.') !== false);
    }

    public function testCiCodexScriptAutoCommitDefaultEnabled(): void
    {
        $script = $this->readFile('development/cli/ci-codex.sh');

        $this->assertTrue(strpos($script, 'MCXFORGE_CI_AUTOCOMMIT=${MCXFORGE_CI_AUTOCOMMIT:-1}') !== false);
        $this->assertTrue(strpos($script, 'git -C "$ROOT" add -A') !== false);
        $this->assertTrue(strpos($script, 'git -C "$ROOT" commit -m "$msg"') !== false);
    }
}

