# `benchmarkCPUXmrig` — CPU Benchmark & QA (xmrig / Monero)

## Overview

`benchmarkCPUXmrig` runs an `xmrig` Monero mining workload as a combined CPU benchmark and long‑running QA tool. It:

- Connects to a public Monero mining pool using a donation address (Monero Project by default, optional Tor Project profile).
- Runs xmrig for a configurable duration (30 minutes by default, or indefinitely when requested).
- Appends raw miner output to a dated log file under `/tmp/benchmarkCPUXmrig-YYYYMMDD.log`.
- Parses periodic `speed` lines from xmrig to compute an average hash rate in H/s.
- Always emits a final programmatic score line:

```text
{{SCORE:<average_hashrate_hs>}}
```

This makes it suitable both for console operators (who can watch the miner) and for automation that only cares about the numeric score.

## Usage

Primary entrypoint:

```sh
bin/benchmarkCPUXmrig.php [--duration=SECONDS] [--pool=NAME] [--beneficiary=NAME] [--address=XMR] [--score-only] [--no-color]
```

### Options

- `--duration=SECONDS`  
  How long to run xmrig.  
  - Default: `1800` (30 minutes).  
  - Use `0` for indefinite burn‑in (until interrupted). The tool will still parse all available output and compute a score when xmrig exits.

- `--pool=NAME`  
  Mining pool profile to use:
  - `moneroocean` (default) → `gulf.moneroocean.stream:20128`
  - `p2pool` → `p2pool.io:3333`
  - `p2pool-mini` → `mini.p2pool.io:3333`

- `--beneficiary=NAME`  
  Donation beneficiary profile when no explicit address is given:
  - `monero` (default) → Monero Project donation address.
  - `tor` → Tor Project donation address.

- `--address=XMR`  
  Explicit Monero address to mine to. When provided, this overrides the beneficiary profile above.

- `--score-only`  
  Suppress human‑readable messages and print only the final score line:

  ```text
  {{SCORE:<average_hashrate_hs>}}
  ```

- `--no-color`  
  Disable ANSI colors in human output. The tool also respects the `NO_COLOR` environment variable.

- `-h`, `--help`  
  Show a brief usage summary.

### Environment

- `XMRIG_BIN`  
  Optional explicit path to the `xmrig` binary. When unset, the tool looks for `xmrig` in `PATH`.

## Paths and Logs

- Logs are appended under:

  - `/tmp/benchmarkCPUXmrig-YYYYMMDD.log`

Each run appends xmrig output to the log file for the current date. This can be used later for deeper analysis (thermal behavior, throttling, error messages).

## Operational Notes

- For quick CPU benchmarking, run:

  ```sh
  bin/benchmarkCPUXmrig.php
  ```

  This starts a ~30‑minute benchmark against the default pool using the Monero Project donation address and prints a final `{{SCORE:...}}` line with the average hash rate.

- For long‑running qualification on unsold servers, use:

  ```sh
  bin/benchmarkCPUXmrig.php --duration=0
  ```

  and run it under `nohup`, `tmux`, `screen`, or a service manager (e.g., systemd) so it can be supervised externally. The tool will continue logging and will compute a score when xmrig eventually exits.

- This workload primarily stresses:
  - CPU cores (integer and cache behavior).
  - Memory subsystem (depending on algorithm and dataset).
  - Network path to the selected pool.

As with all mcxForge tools, this command is read‑only with respect to user data and designed for use on live systems. Ensure local policies permit outbound mining workloads before running it in production environments.  

