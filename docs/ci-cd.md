# CI/CD and Agentic Test Flow

This repository keeps its testing suite in-tree so local runs and CI share the same entrypoints. CI invokes the same scripts you use locally, under the `development/` tree so the repository root stays clean for on-host use.

## Overview

- Orchestrator: `development/testing/test.sh` – tool checks → PHP lint → PHP dev tests (storage parsers) → naming/docblock/Doctrine lints → shell lint → static analysis (PHPStan) → ADR/author/license checks → LOC snapshot.
- CI: `.github/workflows/ci.yml` runs `development/testing/test.sh` on push/PR.
- Optional assistant integration: `development/cli/ci.sh` and `development/cli/ci-codex.sh` can assemble CI context and prompt text for a coding assistant.

## Local Requirements

- Ubuntu/Debian: `sudo apt install -y php-cli shellcheck shfmt` (plus any utilities used by mcxForge tools such as `lsblk`, `smartctl`, etc.).
- Optional: `composer` (for installing PHPStan as a dev dependency), `git`, `gh` (GitHub CLI) and `codex` if you want to use the CI prompt helper.

## Running Locally

Run the full suite from the repository root:

```sh
development/testing/test.sh
```

This mirrors what GitHub Actions runs in `.github/workflows/ci.yml`.

## Assistant-Oriented CI Helper

To pull the latest CI logs and build a prompt for an assistant:

```sh
development/cli/ci.sh
```

Options:

- `--job <name>` – limit logs to a specific job (defaults to the `test` job).
- `--prompt "<text>"` – override the default high-level prompt text.
- `--exec 'codex'` – invoke Codex CLI directly with the assembled prompt.

The helper expects:

- `gh` configured with `gh auth login`.
- Optionally `codex` in `PATH` when using `--exec` (otherwise it prints the command to run manually).

## Assistant-Oriented Refactor Helper

To assemble a strict-rails refactor prompt focused on recent, complex code:

```sh
development/cli/refactor.sh
```

This helper:
- Collects a summary of the last commits and their touched files.
- Uses existing complexity snapshots under `development/var/test-logs/` (phploc/phpmd) when available.
- Writes a verbose refactor prompt and context paths into a temporary directory under `/tmp`.
- Encourages small, behavior-preserving refactors that favor deletion and DRY over new features.

Auto-commit of assistant changes is enabled by default for refactor runs, but is guarded by:
- A full run of `development/testing/test.sh` before attempting to commit.
- Simple size rails: commits are skipped when the diff exceeds a configurable file/line budget (see `MCXFORGE_REFACTOR_MAX_FILES` and `MCXFORGE_REFACTOR_MAX_LINES`).

## CI Workflow

- See `.github/workflows/ci.yml`. It sets up PHP 8.2, installs dev dependencies via Composer (including PHPStan), and runs `development/testing/test.sh`.
- Logs and artifacts from the `test` job can be consumed by `development/cli/ci-codex.sh` to assemble a context-rich prompt for agentic workflows.
