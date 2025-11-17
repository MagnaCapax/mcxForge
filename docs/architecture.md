# Architecture Overview (mcxForge)

## What mcxForge Is
- A command‑line toolkit for on‑host hardware discovery, qualification, benchmarking, and rescue operations on bare‑metal servers.
- A small, self‑contained component that is loaded into an existing Linux environment (typically a live rescue OS) under `/opt/mcxForge`.
- A collection of scripts and libraries that are safe by default, with explicit opt‑in for destructive actions.

## What mcxForge Is Not
- Not a full system overlay or OS distribution; it does not own the base system or package set.
- Not a long‑running daemon or control plane; it runs on demand via CLI entrypoints.
- Not the source of truth for fleet or business data; it focuses on local inspection and action.

## Execution Environment
- **Primary target**: an MCX‑branded live rescue system that boots bare‑metal hardware and provides a console and minimal tooling.
- **Secondary target**: other compatible Linux systems where operators wish to run the same qualification and diagnostics tooling.
- mcxForge is expected to live under `/opt/mcxForge` on the host filesystem. The surrounding environment (e.g., the live system) is responsible for ensuring the repository is present and up to date.

## Components & Layout (High‑Level)
- `bin/`:
  - Entry points invoked directly by operators or automation.
  - Should remain thin and delegate to libraries under `lib/`.
- `lib/`:
  - Shared logic for logging, argument parsing, device discovery, safety checks, test orchestration, and reporting.
  - May be split by language (e.g., `lib/bash/`, `lib/php/`) and by domain (e.g., `lib/hardware/`, `lib/storage/`), as long as the structure stays documented and cohesive.
- `docs/`:
  - This architecture overview and any additional runbooks or guides.
  - `docs/adr/`: Architecture Decision Records that define the rules, boundaries, and key design choices.
- `tests/` (planned):
  - Non‑destructive verification of parsing, orchestration, and reporting behavior.
  - Destructive tests must be opt‑in and clearly separated from default test runs.

See ADR‑0001 for the initial scope and layout decision.

## Typical Deployment Flow (Live Rescue)
1. Bare‑metal hardware boots into the live rescue system.
2. A boot‑time script or command ensures `/opt/mcxForge` exists by cloning or updating this repository from its canonical source.
3. The surrounding automation or operator chooses an mcxForge entrypoint under `bin/` (for example, a qualification or rescue flow) and provides the relevant parameters.
4. mcxForge:
   - Discovers hardware and environment details.
   - Executes the requested tests or operations (e.g., burn‑in, benchmarks, RAID checks, partitioning, fix‑ups), respecting safety rails.
   - Produces human‑readable console output and, where appropriate, machine‑readable reports suitable for upload or archiving.

mcxForge itself does not assume direct connectivity to any control plane; if upload or synchronization is required, flows must make that explicit and remain optional.

## Typical Deployment Flow (Generic Linux)
1. Operator clones mcxForge onto the target system (typically into `/opt/mcxForge`).
2. Operator runs one or more entrypoints under `bin/` with the desired options.
3. mcxForge performs the same style of discovery, testing, and reporting as on the live rescue system, subject to whatever tools and privileges are available on the host.

Exact commands, profiles, and report formats will be documented alongside the tools as they are implemented.

## Responsibilities & Boundaries
- mcxForge **owns**:
  - Local inspection and hardware discovery logic.
  - Execution of qualification, benchmarking, and rescue procedures, including safety checks.
  - The shape and semantics of its CLI interfaces and reports.
- mcxForge **does not own**:
  - Boot configuration, network provisioning, or DHCP.
  - Long‑term storage of results; external systems or operators must decide how and where to archive reports.
  - Business logic such as customer lifecycle or billing.

Keep new features within this boundary. If a proposed change starts to look like a full configuration management or control plane system, it likely belongs elsewhere.

