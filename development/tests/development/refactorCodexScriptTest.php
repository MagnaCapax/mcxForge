<?php

declare(strict_types=1);

namespace mcxForge\Tests;

require_once __DIR__ . '/../common/testCase.php';

final class refactorCodexScriptTest extends testCase
{
    private function readFile(string $relativePath): string
    {
        $root = realpath(__DIR__ . '/../../..');
        $path = $root . '/' . ltrim($relativePath, '/');
        $contents = @file_get_contents($path);
        $this->assertTrue($contents !== false, "Expected to read {$relativePath}");

        return (string) $contents;
    }

    public function testRefactorCodexScriptMentionsStrictRailsMode(): void
    {
        $script = $this->readFile('development/cli/refactor-codex.sh');

        $this->assertTrue(strpos($script, 'mcxForge Refactor Assist — Strict Rails Mode') !== false);
        $this->assertTrue(strpos($script, 'Read first (do not proceed until read):') !== false);
    }

    public function testRefactorCodexScriptMentionsCoreRails(): void
    {
        $script = $this->readFile('development/cli/refactor-codex.sh');

        $this->assertTrue(strpos($script, 'No behavior changes:') !== false);
        $this->assertTrue(strpos($script, 'No safety regressions:') !== false);
        $this->assertTrue(strpos($script, 'Minimal, local edits:') !== false);
        $this->assertTrue(strpos($script, 'Deletion and DRY as primary goals:') !== false);
        $this->assertTrue(strpos($script, 'Tests and verification:') !== false);
    }

    public function testRefactorCodexScriptAutoCommitRails(): void
    {
        $script = $this->readFile('development/cli/refactor-codex.sh');

        $this->assertTrue(strpos($script, 'MCXFORGE_REFACTOR_AUTOCOMMIT=${MCXFORGE_REFACTOR_AUTOCOMMIT:-1}') !== false);
        $this->assertTrue(strpos($script, 'MCXFORGE_REFACTOR_MAX_FILES=${MCXFORGE_REFACTOR_MAX_FILES:-20}') !== false);
        $this->assertTrue(strpos($script, 'MCXFORGE_REFACTOR_MAX_LINES=${MCXFORGE_REFACTOR_MAX_LINES:-1000}') !== false);
        $this->assertTrue(strpos($script, 'development/testing/test.sh') !== false);
        $this->assertTrue(strpos($script, 'git -C "$ROOT" add -A') !== false);
        $this->assertTrue(strpos($script, 'git -C "$ROOT" commit -m "$msg"') !== false);
    }

    public function testRefactorCodexScriptListsContextPathsInPrompt(): void
    {
        $script = $this->readFile('development/cli/refactor-codex.sh');

        $this->assertTrue(strpos($script, 'Context to open (paths in this workspace):') !== false);
        $this->assertTrue(strpos($script, 'Do not inline these; read them directly from disk.') !== false);
    }
}

