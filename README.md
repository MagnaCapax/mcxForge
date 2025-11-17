# mcxForge

mcxForge is a free and open source toolkit for bare‑metal hardware discovery, qualification, benchmarking, and rescue operations. It is developed by Magna Capax Finland Oy (Pulsed Media) and licensed under the Apache License 2.0.

The primary deployment target is the `mcxRescue` live system, where mcxForge is fetched into `/opt/mcxForge` and used to exercise and prepare MiniDedi (MD) hardware before it enters production. The same tools are intended to work on other Linux systems as well, so power users can run the qualification and diagnostics suite on their own hardware.

## Goals

- Provide a consistent CLI toolkit for:
  - Hardware discovery and inventory
  - Burn‑in and qualification (CPU, memory, storage, network)
  - Benchmarking with standardized output formats
  - Storage management (RAID checks/repairs, partitioning, cloning, secure wiping)
  - Rescue and fix‑up operations on installed systems
- Be safe by default, with explicit opt‑in for destructive actions.
- Produce machine‑readable reports suitable for automation and human‑readable summaries for operators.
- Work well both inside `mcxRescue` and on generic supported Linux distributions.

## Usage Overview

mcxForge is designed to live under `/opt/mcxForge` and expose a set of CLI entrypoints (for example: discovery, qualification, benchmarking, and storage helpers). On `mcxRescue`, this repository is intended to be fetched or updated on boot so the latest stable tooling is available during hardware bring‑up and diagnostics.

Outside of `mcxRescue`, you can clone the repository on a supported Linux system and use the same tools directly from the command line, subject to any requirements described in the documentation and AGENTS.md.

Concrete commands and workflows will be documented as the toolkit matures. See `AGENTS.md` for the repository’s engineering rules and safety expectations, and `docs/` for architecture notes and ADRs as they are added.

## Installation & Placement (early outline)

Until packaging and install scripts are finalized, you can treat mcxForge as a source checkout:

```sh
git clone https://github.com/your-org/mcxForge.git
cd mcxForge
```

From there, tools will be invoked via the CLI scripts under `bin/` once they are implemented.

On the MCX live rescue system, the surrounding environment is expected to clone or update this repository into `/opt/mcxForge` automatically and invoke the relevant entrypoints as part of hardware qualification or rescue workflows.

## Requirements (early estimate)

mcxForge is intended to run on a reasonably standard Linux system with:

- A POSIX shell and Bash available.
- PHP CLI installed (PHP 8.x preferred when available).
- Core GNU/Linux userland tools and common sysadmin utilities for inspecting disks, partitions, RAID, SMART, and network state.

Exact dependencies will be documented alongside specific tools as they are implemented.

## License

This project is licensed under the Apache License, Version 2.0. See `LICENSE` for the full text.

Copyright © Magna Capax Finland Oy.
