# ADR-0007: Storage Wipe Tool and Destructive Testing Guardrails

## Metadata
- Author: Aleksi Ursin
- Status: Draft

## Context
- mcxForge needs an explicit, operator-friendly way to perform *intentional* full-device wipes on bare-metal hosts before partitioning and installation.
- Existing tools focus on inventory and benchmarking; there is no unified entrypoint for destructive wipes with consistent safety rails and observability.
- Destructive tooling must be scriptable for automation, but also safe enough for humans operating under pressure on a live console.
- Automated tests and CI must never perform real destructive operations against live systems by default.

## Decision
- Introduce a dedicated storage wipe entrypoint:
  - `bin/storageWipe.php` as the primary CLI for destructive wipes.
  - Core logic implemented in `lib/php/StorageWipe.php`, keeping the entrypoint thin.

- Behaviour of `storageWipe.php`:
  - Discovery:
    - Uses `lsblk -J -b -d` to enumerate `type=disk` block devices.
    - Skips loop devices and similar non-disk types.
    - Detects the disk that backs `/` and *skips it by default*.
  - Safety defaults:
    - Per-device confirmation prompt is required by default.
    - The system disk (backing `/`) is only included when
      `--include-system-device` is explicitly provided.
    - A `--dry-run` mode prints planned commands without executing them.
  - Destructive operations:
    - For each confirmed device, the tool runs a fixed baseline sequence:
      - `wipefs -a`
      - `blkdiscard`
      - `dd` header zeroing (first 20MiB).
    - Optional extensions:
      - `--passes=N` adds N full-device zeroing passes.
      - `--secure-erase` attempts hdparm-based ATA secure erase in addition
        to the baseline sequence (where supported).
      - `--random-data-write` runs time-limited random-position writes using
        zero blocks, with configurable workers and duration.
    - The tool tracks whether any step is expected to cover the entire
      device; if not, it emits an explicit WARNING that data may remain.
    - When multiple overwrite passes or random writes are requested for
      non-rotational devices (SSDs/NVMe), the tool warns that this causes
      additional wear and that secure erase is generally preferable.

- Testing and CI guardrails:
  - All automated tests for the storage wipe tool MUST:
    - Use `--dry-run` exclusively; no real commands that modify block
      devices may be executed as part of default test runs.
  - Destructive integration tests (if ever added) MUST:
    - Be opt-in, clearly labeled, and never run by default in CI.
    - Run only inside hermetic or containerised environments with known
      fake block devices.

## Consequences
- Operators gain a single, well-documented "mother of wiping utilities"
  that:
  - Is safe by default (interactive confirmations, system disk skip).
  - Can be driven non-interactively via `--confirm-all`, `--device`, and
    other flags by higher-level automation.
  - Supports both baseline wipes and more thorough multi-pass or secure
    erase strategies.
- The separation between entrypoint (`bin/storageWipe.php`) and library
  (`lib/php/StorageWipe.php`) keeps the CLI thin and encourages reuse of
  wipe logic from other flows (e.g., partitioning tools).
- The explicit test guardrails reduce the risk of accidental data loss
  during development and CI:
  - Normal test runs exercise only planning and dry-run output.
  - Any future destructive tests must be deliberately opted into and run
    against disposable environments.

## Guardrails
- Any change that relaxes the default safety behaviour (for example,
  including the system disk by default, removing per-device prompts, or
  changing `--dry-run` semantics) MUST be accompanied by:
  - An updated ADR describing the new safety model.
  - Clear documentation updates under `docs/` and in `--help` output.
- Extending the wipe strategies (e.g., new multi-pass patterns, SSD/NVMe
  vendor-specific secure wipe flows) should:
  - Reuse the same basic CLI entrypoint and option naming.
  - Preserve the invariant that tests default to dry-run only.
  - Document any new destructive capabilities and their trade-offs.
