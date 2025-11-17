# Repository Guidelines (mcxForge)

These guidelines, and the surrounding agentic development framework used in this repository, were initially designed and assembled by Aleksi Ursin, using experience and natural-language prompting workflows for most of the initial implementation.

## Project Context
- **Purpose**: mcxForge is a suite of command‑line tools and helpers installed under `/opt/mcxForge` on a live rescue system to qualify, identify, benchmark, and prepare bare‑metal hardware for production use.
- **Audience**: Sysadmins on a live console and automation driven by higher‑level orchestration. Tools must be safe by default, scriptable, and usable under pressure.
- **Scope**: Hardware discovery, qualification, burn‑in, benchmarking, RAID/filesystem management, secure wiping, partition/clone flows, and glue around install templating. This repository is about on‑host inspection and action, not long‑term business logic.
- **Environment**: Runs on a live rescue OS with minimal guarantees (network may be flaky, disks may be degraded, firmware weird). Design for rough conditions and partial failures.
- **Installation Target**: Tools are designed to live under `/opt/mcxForge` with well‑defined entrypoints suitable for both manual and automated invocation.

## Governing Law & Sources of Truth
- This `AGENTS.md` governs the entire repository. If more specific rules are needed for subtrees, add additional `AGENTS.md` files; the most specific one wins.
- Code is the ground truth for behavior; docs explain intent. If docs and code disagree, follow the code and update the docs.
- ADRs under `docs/adr/` are part of the mcxForge “constitution”: once an ADR is marked Accepted, its decisions are binding for code, docs, and tooling until explicitly superseded by a later ADR.
- Significant changes to behavior, interfaces, safety rails, or workflows require an ADR under `docs/adr/` (one subject per ADR). Cross‑reference related ADRs.
- Before designing new flows, scan `docs/adr/` and `docs/` for relevant decisions and architecture notes. Keep new work aligned with existing decisions or add an ADR to change them. Pull requests that contradict existing Accepted ADRs without updating or adding a new ADR should be rejected.

## Universal Engineering Principles
- **KISS (Keep It Simple, Stupid)**: Prefer straightforward, boring implementations. Avoid cleverness and unnecessary abstractions, especially in safety‑critical paths.
- **DRY (Don’t Repeat Yourself)**: Reuse shared helpers and libraries. When you find copy‑paste logic, extract a common helper instead of duplicating behavior.
- **YAGNI (You Aren’t Gonna Need It)**: Do not build features, flags, or abstractions until they are required for a concrete use‑case.
- **Deletion First**: Prefer removing or simplifying code over adding more. The best part is often no part.
- **One Flow, No Special Cases**: Keep a single explicit flow per operation (e.g., “qualify hardware”, “wipe + partition + clone”). Exceptions require a clear rationale and a removal plan.
- **Pit of Success Defaults**: Make the safe, correct behavior the default. Risky operations must require explicit, noisy opt‑in.
- **Minimal Edits**: Keep diffs small, coherent, and reviewable. Avoid “drive‑by” changes unrelated to the task at hand.
- **No Aliases**: Use consistent names for the same concept across scripts, flags, logs, and docs. Do not introduce alternate names or synonyms for the same thing.
- **Stability Over Perfection**: Prefer incremental improvements that preserve working flows over large refactors. Never break existing automation or scripts without an ADR, migration path, and clear documentation.

## Safety & Operational Doctrine
- **Safety First**: Destructive actions (wiping, repartitioning, RAID reconfiguration, firmware changes) must be guarded with explicit checks, clear prompts (where interactive), and obvious `--force` or equivalent flags for automation.
- **Idempotence & Recovery**: Design operations so they can be safely re‑run after partial completion. Provide explicit cleanup helpers where needed (stop arrays, unmount filesystems, remove temp state).
- **Fail‑Soft Bias**: Prefer failing a single step and reporting clearly over leaving the system in an unknown half‑broken state. Do not mask errors, but avoid unnecessary hard exits when a degraded path is acceptable.
- **Environment Awareness**: Be explicit about assumptions (disk naming, RAID presence, boot devices, NVMe layouts). Detect reality and adapt instead of hard‑coding layout expectations.
- **Observability**: Emit concise, structured status output suitable for both humans and log scraping. Long‑running operations should show clear progress steps.
- **Dry‑Run Friendly**: Where possible, provide `--dry-run` or “plan only” modes that print what would happen without making changes.

## Language & Tooling Policy
- **Primary Languages**: Bash for orchestration and glue; PHP for more complex workflows, data processing, or reporting where needed.
- **Avoid New Stacks**: Do not introduce new language runtimes (e.g., Python, Node.js, Ruby) or heavy dependencies without an ADR and explicit approval.
- **Static Checks**:
  - Bash: `bash -n` and `shellcheck` for syntax and common issues; format with `shfmt` if configured.
  - PHP: `php -l` for syntax; follow PSR‑12‑style formatting using existing tooling if/when it is added to this repo.
- **Dependencies**: Minimize external tool dependencies. When a new system package or binary is truly necessary, document why and where it is used, and add an ADR if it materially affects installation or operations.

## UX & CLI Contracts
- **Stable Interfaces**: Once a CLI entrypoint or flag is used by automation, treat it as a contract. Backwards‑incompatible changes require an ADR, a migration path, and clear documentation.
- **Consistent Flags & Output**: Reuse flag names and output formats across tools (`--help`, `--json`, `--dry-run`, `--device`, `--target`, etc.). Keep output machine‑parsable when requested and human‑friendly by default.
- **Exit Codes**: Use exit codes consistently (`0` success, non‑zero on failure). For multi‑step tools, ensure failures surface via meaningful exit codes and summary log lines.
- **Help & Usage**: Every public tool must have a `--help` or equivalent that explains arguments, behavior, and safety considerations in a few lines.

## Documentation & ADRs
- Keep `docs/` and `docs/adr/` up to date with behavior and design decisions.
- Each ADR should cover one decision (e.g., “how we detect disks”, “naming scheme for devices”, “layout of `/opt/mcxForge`”) and include context, options considered, decision, and consequences.
- Reference relevant ADRs in code comments, docs, and PR descriptions so future readers can understand why things are the way they are.

## Workflow Expectations
- Before changing code, check for `AGENTS.md` in the relevant subdirectory and follow the most specific instructions.
- Run available linting/check scripts before committing. If no unified check script exists yet, at minimum run `bash -n`, `shellcheck`, and `php -l` where applicable.
- Keep changes narrowly focused. If you discover unrelated issues, either fix them in a separate change or clearly scope them in their own commit/ADR.
- When in doubt about safety, naming, or scope, pause and ask for clarification rather than guessing.

## Repository Layout (expected)
- `bin/`: CLI entrypoints intended for direct use by humans and automation. Keep these thin; most logic should live in libraries under `lib/`.
- `lib/`: Shared libraries and helpers.
  - `lib/bash/`: Shell helper functions (logging, argument parsing, device discovery, safety guards).
  - `lib/php/`: PHP libraries for more complex flows, reporting, or data processing.
  - Additional subdirectories are allowed but must be documented in `docs/architecture.md` and kept cohesive.
- `docs/`: Architecture notes, usage guides, and high‑level runbooks.
  - `docs/adr/`: Architecture Decision Records (ADRs); one subject per ADR.
- `tests/` (when added): Non‑destructive, hermetic tests for parsing, orchestration logic, and safety rails. Tests that touch real disks or destructive paths must be explicitly gated and never run by default in CI.
- `etc/` (optional): Static configuration templates or default profiles; keep them small and documented.

Any deviations from this layout must be captured in an ADR before implementation.

## Naming & Style
- Prefer **context‑first naming** in scripts, logs, and identifiers (e.g., datacenter → rack → chassis → node; or platform → component → action).
- Use a single, consistent prefix for public tools and helper scripts so they are easy to discover (for example, starting command names with the same short prefix rather than many unrelated names).
- CLI flags:
  - Use long, kebab‑case flags for clarity (e.g., `--device`, `--target-disk`, `--profile-name`).
  - Avoid short flags unless they are truly standard (`-h`/`--help`, `-v`/`--version`).
  - Do not introduce aliases for the same option.
- Acronyms:
  - Treat common hardware acronyms as all‑caps when they stand alone in names (e.g., `CPU`, `RAM`, `SSD`, `NVMe`).
  - In identifiers and filenames that combine acronyms with other words, keep the acronym in all‑caps and reuse a single spelling everywhere (for example, `benchmarkCPUGeekbench`, not `benchmarkCpuGeekbench` or `benchmarkCpuGeekBench`).
- Shell:
  - Prefer POSIX‑compatible shell where practical; use Bash features when they materially simplify the code and are available in the target environment.
  - Functions and local variables should use lowerCamelCase or lower_snake_case consistently within a file; pick one per file and stick to it.
- PHP:
  - Follow PSR‑12 style guidelines where applicable (indentation, braces, naming).
  - Use namespaces for shared libraries under `lib/php/` once they emerge; keep naming consistent with directory layout.
- Environment variables must use `UPPER_SNAKE_CASE`. Config keys and JSON fields should use a single consistent style (e.g., lowerCamelCase) and never mix styles for the same concept.

## Testing & Verification
- Default stance:
  - For shell scripts, run `bash -n` and `shellcheck` before committing.
  - For PHP, run `php -l` on modified files.
  - When a dedicated test runner exists under `tests/` or `scripts/`, run it for the relevant subset.
- Non‑destructive first:
  - Tests in this repo must not modify live systems by default. Destructive tests (e.g., wiping or repartitioning) must be opt‑in and clearly labeled.
  - Prefer unit‑style checks for parsing and orchestration logic; reserve destructive integration tests for carefully controlled environments.
- Visible footprint:
  - When adding substantial functionality, consider the impact on lines of code and conceptual complexity.
  - Favor changes that reduce net complexity or replace many ad‑hoc scripts with fewer, better‑structured tools.

## First‑Principles & Footprint Addendum
- **Delete before add**: When you see overlapping scripts or flows, first look for opportunities to remove or consolidate rather than adding another variant.
- **Minimize part count**: Fewer tools that do the right thing are preferable to many almost‑identical commands. When adding a new entrypoint, justify why an existing one cannot be extended instead.
- **One flow**: For each major operation (qualification, benchmarking, storage fix‑up), keep a single primary flow and avoid a combinatorial explosion of modes. Branches should be explicit and documented.
- **Comment the “why”**: Comments and docs should explain invariants and intent, not restate obvious code. Keep them brief but precise.
- **Operate from constraints**: Design starting from safety, idempotence, and operability constraints, then fit features inside those boundaries.

These principles are meant to keep mcxForge small, sharp, and understandable while still powerful enough for real‑world bare‑metal work.
