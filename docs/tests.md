# Testing Overview

Current automated checks live under `development/testing/` so local runs and CI use the same entrypoints.

## Available Scripts
- `development/testing/test.sh` – orchestrates tool checks, PHP lint, PHP dev tests (storage parsers), naming/docblock/Doctrine lints, shell lint, static analysis, ADR/author/license checks, complexity snapshots, and a LOC snapshot.
- `development/testing/php-lint.sh` – wraps `php -l` over the repository.
- `development/testing/shell-lint.sh` – runs `bash -n` and `shellcheck` over `*.sh` scripts when available.
- `development/testing/phpstan.sh` – runs PHPStan (honours `PHPSTAN_DISABLE_PARALLEL=1`).
- `development/testing/loc.sh` – prints a LOC breakdown (bin PHP, tests, Bash, docs).
- `development/testing/adr-lint.sh` – verifies that each ADR under `docs/adr/` contains an `Author:` line in its metadata.
- `development/testing/author-lint.sh` – verifies that bin PHP entrypoints and development shell helpers declare authorship markers.
- `development/testing/camelcase-lint.sh` – enforces basic filename/class naming conventions for PHP in `bin/`, `lib/php/`, and `development/tests/development/`.
- `development/testing/docblock-lint.sh` – enforces non-trivial docblocks for classes and public methods in `lib/php/`.
- `development/testing/doctrine-lint.sh` – enforces basic doctrine rules (ADR numbering/title hygiene, no stray `docs/apis`).
- `development/testing/complexity-phploc.sh` – captures a phploc-based complexity snapshot into `development/var/test-logs/complexity-phploc.txt` when available.
- `development/testing/complexity-phpmd.sh` – runs PHP Mess Detector on `bin/` and `lib/` and writes a report to `development/var/test-logs/complexity-phpmd.txt` when available.

Storage-specific and other PHP tests live under `development/tests/development/` and use a small in-tree harness.

## Running locally

Requirements: PHP 8.x and basic shell tools.

```sh
development/testing/test.sh
```

This will:
- Check that required tools are installed (`php`), and report missing optional tools.
- Lint all PHP files.
- Run storage parser and other PHP dev tests.
- Run naming/docblock/Doctrine lints.
- Run shell lint where `shellcheck` is available.
- Run static analysis with PHPStan when installed (or via Composer dev deps).
- Run ADR metadata checks to ensure all ADRs declare an `Author:` line.
- Run author and license checks to ensure key entrypoints and helper scripts declare authorship and that the Apache 2.0 license is present.
- When `phploc` and `phpmd` are installed under `development/`, run complexity snapshots and reports into `development/var/test-logs/`.

Verbose logs can be enabled via:

```sh
TEST_VERBOSE=1 development/testing/test.sh
```

## Direction

- As mcxForge grows, additional tests (for new entrypoints and libraries) should be added under `development/tests/` and wired into `development/testing/test.sh`.
- Opinionated checks (docblock/ADR metadata, naming conventions, etc.) are now part of the default workflow; further checks can be introduced here once the repository structure stabilizes.
