# Development

Start here if you change this repository, by hand or with a coding agent.

## Read first

1. [`AGENTS.md`](../AGENTS.md): the rules for this repository.
2. [Engineering principles](engineering-principles.md): the organisation-wide rules with their reasons and the mistakes that taught them. Adapted from the canonical copy in mcxPikkumies.
3. [Decision records](../docs/adr/): accepted ADRs are binding. A significant change ships with a new one ([template](../docs/adr/0000-template.md)). [ADR-0008](../docs/adr/0008-architecture-layout-and-languages.md) records the architecture, the layout and the languages.
4. [Architecture](../docs/architecture.md), [tests](../docs/tests.md) and [CI](../docs/ci-cd.md): how mcxForge is laid out, checked and built.
5. Per-tool notes in [`docs/bin/`](../docs/bin/), and open work in [`docs/TODOS.md`](../docs/TODOS.md).

## What lives in this directory

This directory holds the development tooling, none of which is needed on a live host:

- `testing/`: the test and lint scripts, with `testing/test.sh` as the single entrypoint for local runs and CI.
- `tests/`: the PHP tests (`tests/development/*Test.php`) and their small in-tree harness (`tests/common/testCase.php`, `tests/development/runner.php`).
- `cli/`: helper scripts that collect CI logs and recent-change context for a coding assistant.
- `composer.json` and `phpstan.neon.dist`: the development-only PHPStan dependency and its configuration. `vendor/` and `var/` are generated and ignored.

## Before you commit

- Install the development dependencies once: `composer install --working-dir ./development`.
- Run `development/testing/test.sh` from the repository root. CI runs the same script on PHP 8.2.
- Read the output, not only the exit code. The script currently carries on past a failing test run, a failing shell lint and a failing PHPStan run, so check for `Tests: N, Failures: 0` and an `OK` from each lint.
- New behaviour has a test that fails when the behaviour is broken (break it once and see). Destructive paths are tested through `--dry-run` only (ADR-0007).
- Review the change with the [review unit](https://github.com/MagnaCapax/mcxPikkumies/tree/main/pikkumies/tools/development/code-review): correctness, an adversarial pass, weakness classes, and conformance to `AGENTS.md`, the ADRs and the principles. Any agent can follow its `skill.md`.
- Nothing that is not meant for the public (credentials, internal hostnames, customer data): this repository is public.

## Other MagnaCapax repositories

Each has its own `AGENTS.md`, decision records and docs. When your change touches another project, read its records first.

| Repository | What it is | Docs | Decisions |
|---|---|---|---|
| [PMSS](https://github.com/MagnaCapax/PMSS) | Pulsed Media Seedbox Software, the platform Pikkumies ships on | [docs](https://github.com/MagnaCapax/PMSS/tree/main/docs) | [ADRs](https://github.com/MagnaCapax/PMSS/tree/main/docs/adr) |
| [mcxPikkumies](https://github.com/MagnaCapax/mcxPikkumies) | Lightweight helper agent that runs on the server it manages | [docs](https://github.com/MagnaCapax/mcxPikkumies/tree/main/docs) | [ADRs](https://github.com/MagnaCapax/mcxPikkumies/tree/main/docs/adr) |
| [mcxVirsilipas](https://github.com/MagnaCapax/mcxVirsilipas) | Prepaid token-metering proxy for model APIs | [docs](https://github.com/MagnaCapax/mcxVirsilipas/tree/main/docs) | [ADRs](https://github.com/MagnaCapax/mcxVirsilipas/tree/main/docs/adr) |
| [mcxForge](https://github.com/MagnaCapax/mcxForge) | This repository | [docs](https://github.com/MagnaCapax/mcxForge/tree/main/docs) | [ADRs](https://github.com/MagnaCapax/mcxForge/tree/main/docs/adr) |
| [mcxRescue](https://github.com/MagnaCapax/mcxRescue) | Rescue live system for bare-metal servers | [docs](https://github.com/MagnaCapax/mcxRescue/tree/main/docs) | [ADRs](https://github.com/MagnaCapax/mcxRescue/tree/main/docs/adr) |
| [mcxTemplate](https://github.com/MagnaCapax/mcxTemplate) | Templating engine for distribution installs | [docs](https://github.com/MagnaCapax/mcxTemplate/tree/main/docs) | [ADRs](https://github.com/MagnaCapax/mcxTemplate/tree/main/docs/adr) |
| [nodeAgent](https://github.com/MagnaCapax/nodeAgent) | Server telemetry and hardware health agent | [docs](https://github.com/MagnaCapax/nodeAgent/tree/main/docs) | [ADRs](https://github.com/MagnaCapax/nodeAgent/tree/main/docs/adr) |
| [mcxSerp](https://github.com/MagnaCapax/mcxSerp) | Search results API client for PHP | [docs](https://github.com/MagnaCapax/mcxSerp/tree/main/docs) | [ADRs](https://github.com/MagnaCapax/mcxSerp/tree/main/docs/adr) |
| [mcxSauces](https://github.com/MagnaCapax/mcxSauces) | Scripts and configs for running systems and services | [docs](https://github.com/MagnaCapax/mcxSauces/tree/main/docs) | [ADRs](https://github.com/MagnaCapax/mcxSauces/tree/main/docs/adr) |
| [mcxBMCView](https://github.com/MagnaCapax/mcxBMCView) | BMC console screenshot and keyboard automation | [docs](https://github.com/MagnaCapax/mcxBMCView/tree/main/docs) | [ADRs](https://github.com/MagnaCapax/mcxBMCView/tree/main/docs/adr) |
