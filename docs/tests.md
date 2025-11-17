# Testing Overview

Current automated checks live under `development/testing/` so local runs and CI use the same entrypoints.

## Available Scripts
- `development/testing/test.sh` – orchestrates tool checks, PHP lint, storage parser tests, shell lint, static analysis, and a LOC snapshot.
- `development/testing/php-lint.sh` – wraps `php -l` over the repository.
- `development/testing/shell-lint.sh` – runs `bash -n` and `shellcheck` over `*.sh` scripts when available.
- `development/testing/phpstan.sh` – runs PHPStan (honours `PHPSTAN_DISABLE_PARALLEL=1`).
- `development/testing/loc.sh` – prints a LOC breakdown (bin PHP, tests, Bash, docs).

Storage-specific tests live under `tests/development/` and use a small in-tree harness.

## Running locally

Requirements: PHP 8.x and basic shell tools.

```sh
development/testing/test.sh
```

This will:
- Check that required tools are installed (`php`), and report missing optional tools.
- Lint all PHP files.
- Run storage parser tests (e.g., SMART output parsing, scheme detection).
- Run shell lint where `shellcheck` is available.
- Run static analysis with PHPStan when installed (or via Composer dev deps).

Verbose logs can be enabled via:

```sh
TEST_VERBOSE=1 development/testing/test.sh
```

## Direction

- As mcxForge grows, additional tests (for new entrypoints and libraries) should be added under `development/tests/` and wired into `development/testing/test.sh`.
- More opinionated checks (docblock/ADR metadata, naming conventions, etc.) can be introduced here once the repository structure stabilizes.
