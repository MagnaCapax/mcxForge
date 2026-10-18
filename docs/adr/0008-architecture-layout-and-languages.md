# ADR-0008: Architecture, Layout and Languages

## Metadata
- Author: Aleksi Ursin
- Date: 2026-10-18
- Status: Accepted
- Category: architecture

## Context
- ADR-0001 set the scope, the deployment path (`/opt/mcxForge`) and the expected layout. The repository has since grown to 29 PHP entrypoints, a shared PHP library, an in-tree test harness and a set of lint scripts, and the layout it actually has is recorded nowhere in one place.
- The MagnaCapax repositories now share one set of engineering principles, with their reasons, in [`development/engineering-principles.md`](../../development/engineering-principles.md). This record states how they apply here, so that a contributor or a coding agent does not have to work it out from the code.
- ADR-0001 and `AGENTS.md` describe shell as the default for orchestration and glue and PHP for "more complex" work. Principle 1 draws the line more sharply: anything that parses, branches, keeps state, does arithmetic or calls an API is PHP, and shell stays thin glue of about 20 lines. The code already follows the sharper line almost everywhere (see below).

## Options Considered
- Leave the layout implicit in ADR-0001, `docs/architecture.md` and the code: no new text, but the actual languages, the test entrypoint and the exceptions stay undocumented, and the next contributor guesses from the nearest file.
- Record the actual architecture and how the principles apply, building on ADR-0001 (chosen): one place to read, and every claim checkable against the tree.

## Decision
- **What mcxForge is.** A command-line toolkit for hardware discovery, qualification, benchmarking, secure wiping and rescue on a single bare-metal host. It is cloned into `/opt/mcxForge`, most often by the mcxRescue live system, which preloads it at image build and updates it at boot. There is no build step and no daemon: operators and automation run the entrypoints in `bin/` directly. Scope and boundaries stay as ADR-0001 and `docs/architecture.md` set them.
- **Layout and ownership.**
  - `bin/`: the public entrypoints, one tool per file, named from broad to specific (`inventoryStorage.php`, `benchmarkCPUGeekbench.php`, `storageWipe.php`). The `storageWipe*` variants are a few lines each: they preset flags and call the shared runner.
  - `lib/php/`: shared PHP. `Cli.php` (common flags and colour), `Logger.php`, and `StorageWipe.php` (the wipe planner and runner behind every `storageWipe*` entrypoint) in namespace `mcxForge`; the benchmark runners in `lib/php/benchmark/`, namespace `mcxForge\Benchmark`.
  - `etc/root.bashrc`: shell helpers that the live system sources from root's `~/.bashrc`; it puts `bin/` on `PATH`.
  - `docs/`: architecture, tests, CI, maintainers, open work (`TODOS.md`), per-tool notes in `docs/bin/`, and the decision records in `docs/adr/`.
  - `development/`: everything a live host does not need. `testing/` holds the lint and test scripts with `testing/test.sh` as the single entrypoint; `tests/` holds the PHP tests and their small harness; `cli/` holds helper scripts that gather CI logs and recent-change context for a coding assistant; `composer.json` and `phpstan.neon.dist` pull in and configure PHPStan. `development/README.md` and `development/engineering-principles.md` are the contributor's starting point.
  - `tests/StorageWipeDryRunTest.php`: a standalone smoke test of `storageWipe.php --help`, run by hand with `php tests/StorageWipeDryRunTest.php`; `testing/test.sh` does not run it.
- **Languages.**
  - PHP is the language for logic: every entrypoint in `bin/` but one, all of `lib/php/`, and the tests. Files start with `declare(strict_types=1)`. CI runs PHP 8.2, and Composer's platform is pinned to 8.2.0. There are no runtime Composer dependencies; PHPStan is a development dependency only.
  - Why PHP: the tools parse the output of `lsblk`, `smartctl`, `fio`, `dmidecode` and friends, keep state across devices, compute scores and emit JSON (ADR-0005). That is exactly the work that is fragile in shell (quoting, no data structures, failures lost in pipes, hand-built JSON) and safe in PHP (`json_encode`, arrays, exceptions). The live system already ships `php-cli`, so PHP costs nothing extra on the target.
  - Shell is thin glue. `bin/inventorySystemNeofetch.sh` fetches and runs an external tool; `etc/root.bashrc` sets up the root shell; `development/testing/*.sh` and `development/cli/*.sh` orchestrate the checks. Several of these are past about 20 lines and some carry logic (the naming and docblock lints parse PHP source; the assistant helpers are 255 and 324 lines). Under principle 1 they become PHP when they are next changed for another reason, not in a sweep of their own.
  - No Python, Node or other runtime, as ADR-0001 already requires; a new runtime needs its own ADR with a capability reason.
- **Build, run, test.** No build. Run a tool with `bin/<tool>.php --help`. Test with `development/testing/test.sh` after `composer install --working-dir ./development`; CI runs the same script (`.github/workflows/ci.yml`). The script runs tool checks, `php -l`, the PHP tests, the naming, docblock and doctrine lints, shell lint, PHPStan at level 1, the ADR, author and licence lints, complexity snapshots and a line count.
- **Conventions in force.**
  - camelCase file names in `bin/` and the tests, StudlyCaps class names in `lib/php/` (`camelcase-lint.sh`); acronyms in capitals inside names (`benchmarkCPUGeekbench`); long kebab-case CLI flags and no aliases (ADR-0002); `UPPER_SNAKE_CASE` environment variables.
  - Docblocks on every class and public method in `lib/php/` (ADR-0003, `docblock-lint.sh`); an `@author` marker in every `bin/*.php` (`author-lint.sh`).
  - Entrypoints stay thin and delegate to `lib/php/`; exit codes come from `EXIT_OK` and `EXIT_ERROR`.
  - One JSON score line per successful benchmark run, schema in ADR-0005 (CPU) and ADR-0006 (storage, draft).
  - Destructive tools default to safe: confirmation per device, the system disk skipped, `--dry-run`, and tests that only ever use `--dry-run` (ADR-0007).
  - Every ADR has an `Author:` line and a `# ADR-NNNN:` title matching its number (`adr-lint.sh`, `doctrine-lint.sh`).
- **How the principles apply.**
  - 1, PHP for logic: as above.
  - 2, files around 150 lines: on 2026-10-18, 27 of the 39 PHP files in `bin/` and `lib/php/` were over 150 lines; the largest is `bin/inventoryStorageRaid.php` at 946. Split a file when you change it anyway, moving shared parsing into `lib/php/`; do not mass-split.
  - 3, names from broad to specific in camelCase: the existing naming rules (ADR-0002, `AGENTS.md`) already ask for this; PHP classes keep StudlyCaps per PSR-12.
  - 4 and 5, KISS, DRY, YAGNI and delete first: `AGENTS.md` already carries these, plus "one flow, no special cases".
  - 6, pit of success: the destructive defaults of ADR-0007 are the model for any new risky tool.
  - 7, never break old users: CLI flags and JSON score lines are contracts for automation (ADR-0005, `AGENTS.md`).
  - 8, tests that can fail: tests live in `development/tests/development/`; the run must be read, not just its exit code, while `test.sh` continues past failures.
  - 9, decisions written down: as `AGENTS.md` and ADR-0004 require.
  - 10, fail soft: `AGENTS.md`'s "fail-soft bias" and the rescue environment it was written for.

## Consequences
- A contributor can find what each directory owns, which language to write in and which command to run, in one record that matches the tree.
- The language line of principle 1 is now written down for this repository. The wording in ADR-0001 and `AGENTS.md` ("Bash for orchestration and glue; PHP for more complex workflows") is broader; until a follow-up decision aligns it, read it through principle 1: shell for pure orchestration, PHP as soon as there is logic.
- Known gaps, left as follow-ups rather than fixed here: `test.sh` does not stop on a failing test run, shell lint or PHPStan run; `tests/StorageWipeDryRunTest.php` sits outside the suite; many files are over the 150-line target; several shell scripts hold logic.

## References
- ADR-0001 (scope and layout), ADR-0002 (naming), ADR-0003 (docblocks and static analysis), ADR-0004 (ADR metadata), ADR-0005 and ADR-0006 (benchmark JSON), ADR-0007 (storage wipe).
- [`development/engineering-principles.md`](../../development/engineering-principles.md), [`docs/architecture.md`](../architecture.md), [`docs/tests.md`](../tests.md).
- mcxRescue ADR 0002, preloading mcxForge at image build: https://github.com/MagnaCapax/mcxRescue/blob/main/docs/adr/0002-preload-mcxrepos.md
