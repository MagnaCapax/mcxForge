# ADR-0001: mcxForge Scope and Layout

## Status
- Accepted

## Context
mcxForge is intended to be a small, focused toolkit that runs inside an existing Linux environment (most commonly a live rescue system) to qualify, benchmark, and repair bare‑metal servers. It should not behave like a full overlay distribution or control plane, but it must still have clear boundaries, a stable directory layout, and consistent language/tooling choices.

Without explicit decisions, the repository could grow into an ad‑hoc collection of scripts with inconsistent naming, duplicated logic, and unclear safety guarantees.

## Decision
- **Scope**:
  - mcxForge is responsible for local hardware discovery, qualification, benchmarking, and rescue/fix‑up operations on a single host.
  - It is not responsible for boot orchestration, DHCP, or long‑term data storage; those concerns live in surrounding systems.
  - The toolkit must be safe by default, with explicit opt‑in for destructive operations.
- **Deployment path**:
  - The canonical on‑host location for mcxForge is `/opt/mcxForge`.
  - The surrounding environment (e.g., a live rescue system) is responsible for cloning or updating the repository into this path.
- **Directory layout**:
  - `bin/` holds CLI entrypoints intended for direct use by operators and automation. These scripts should be thin and delegate to shared libraries.
  - `lib/` holds shared libraries and helpers (shell and PHP). Future subdirectories (such as `lib/bash/`, `lib/php/`, and domain‑specific directories) must be documented in `docs/architecture.md`.
  - `docs/` holds architecture notes, runbooks, and ADRs; `docs/adr/` holds one‑subject ADRs like this one.
  - `tests/` (when created) will hold non‑destructive tests and any explicitly gated destructive tests.
  - `etc/` (if needed) may hold configuration templates or profiles, kept small and documented.
- **Language policy**:
  - Shell (POSIX + Bash) is the default for orchestration and glue.
  - PHP CLI is used when workflows become complex enough to benefit from structured data handling or richer reporting.
  - Introducing additional language runtimes requires a separate ADR and explicit justification.

## Consequences
- Contributors have a clear mental model for what belongs in mcxForge and what does not.
- The directory structure sets expectations early, making it easier to find entrypoints and shared logic as the toolkit grows.
- External systems can safely assume mcxForge will live under `/opt/mcxForge` and expose its tools via `bin/`, which simplifies automation and documentation.
- The language policy keeps the runtime surface manageable and avoids accidental proliferation of stacks on rescue systems.

Future ADRs should build on this decision when introducing new top‑level directories, changing the deployment path, or expanding the scope beyond a single host’s qualification and rescue operations.

