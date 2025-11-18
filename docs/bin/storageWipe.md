# `storageWipe` — Destructive Storage Wipe Utility

## Overview

`storageWipe` is the primary mcxForge entrypoint for intentionally destroying
data on block devices before partitioning or re‑use. It is designed for use on
live rescue systems by experienced operators and higher‑level automation.

The tool:

- Discovers block devices using the shared inventory helpers.
- Skips the disk that backs `/` by default (can be overridden).
- Asks for explicit confirmation per device unless `--confirm-all` is used.
- Runs a baseline wipe sequence on each confirmed device:
  - `wipefs -a`
  - `blkdiscard`
  - `dd` header zeroing (first 20MiB).
- Can optionally:
  - Add one or more full‑device zero passes.
  - Attempt ATA secure erase (hdparm) where supported.
  - Run time‑limited random‑position zero writes.

`--dry-run` prints the exact commands that *would* run without executing them.

## Usage

Primary entrypoint:

```sh
bin/storageWipe.php [options]
```

### Options

- `--dry-run`  
  Print the planned commands for each selected device and exit without
  executing anything. This is the recommended default in scripts until
  behaviour is well understood.

- `--confirm-all`  
  Do not prompt per device; wipe every selected device non‑interactively.
  When omitted, the tool asks:

  ```text
  Wipe ALL DATA on /dev/…? Type 'yes' to confirm:
  ```

- `--device=PATH`  
  Restrict wiping to the given device path or bare name (for example,
  `--device=/dev/sdb` or `--device=sdb`). May be specified multiple times.

- `--include-system-device`  
  Include the disk that backs `/` in the candidate set. Without this flag,
  the system disk is detected via the shared topology helper and skipped.

- `--passes=N`  
  Number of full‑device zero overwrite passes (integer `N >= 1`). When
  omitted, only the baseline sequence (blkdiscard + header zeroing) is run.
  When set, `N` full‑device `dd` passes are added on top of the baseline.

- `--secure-erase`  
  Attempt device‑native secure erase in addition to the baseline sequence:
  - For SATA/SAS and similar ATA devices, this uses `hdparm` security erase.
  - For NVMe devices, this uses `nvme format -s 1 -f` when available.
  On SSDs, secure erase may also be auto‑enabled based on bus type and
  rotational flag; a log line is emitted when auto‑enabled.

- `--random-data-write`  
  After the baseline sequence (and any full‑device passes / secure erase),
  run time‑limited random‑position zero writes across the device. This is
  intended as an additional scrub and **does not guarantee full coverage**.

- `--random-duration-seconds=N`  
  Duration for the random write workers in seconds (default: `300`).

- `--random-workers=N`  
  Number of random write worker loops per device (default: `2`).

- `-h`, `--help`  
  Show a brief usage summary.

## Behaviour and Safety

- Device discovery:
  - Uses the same inventory helpers as other storage tools to list block
    devices and group them by bus.
  - Builds a map of rotational vs non‑rotational devices using `lsblk`.

- System disk detection:
  - Prefers `inventoryStorageTopologyCollect()` to identify the disk backing
    `/` via the logical storage topology.
  - Falls back to `findmnt`/`lsblk` only when topology is unavailable.

- SSD / HDD handling:
  - Non‑rotational devices (SSDs / NVMe) are detected via `ROTA=0`.
  - When multiple full‑device passes or random writes are requested on an SSD,
    the tool logs a warning that this increases wear and that secure erase is
    generally preferable.
  - For SATA/SAS SSDs, ATA secure erase may be auto‑enabled; for NVMe SSDs,
    NVMe format‑based secure erase may be auto‑enabled. Operators still retain
    the ability to force or suppress secure erase via `--secure-erase` and
    their choice of passes.

- Coverage semantics:
  - The tool tracks whether any step is expected to cover the entire device.
  - Full coverage is only credited for:
    - Full‑device `dd` passes (from `--passes=N`).
    - Successful `hdparm --security-erase` invocations.
  - Even when `blkdiscard` succeeds, a device that has **no** full‑coverage
    step will emit a WARNING that data may remain.

## Testing Notes

- Automated tests MUST:
  - Use `--dry-run` only; no real commands that modify block devices may run
    as part of default test suites.
  - Exercise the same production code paths; there are no special
    test‑only code paths or environment variables for this tool.

## Examples

- Inspect the planned wipe for all non‑system disks:

  ```sh
  bin/storageWipe.php --dry-run
  ```

- Non‑interactive wipe of all non‑system disks with baseline sequence only:

  ```sh
  bin/storageWipe.php --confirm-all
  ```

- Aggressive wipe of a specific HDD with three full passes:

  ```sh
  bin/storageWipe.php --device=/dev/sdb --confirm-all --passes=3
  ```

- Prefer secure erase for a SATA SSD:

  ```sh
  bin/storageWipe.php --device=/dev/sdc --confirm-all --secure-erase
  ```
