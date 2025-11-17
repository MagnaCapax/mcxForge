# `benchmarkCPUGeekbench` — CPU Benchmark (Geekbench)

## Overview

`benchmarkCPUGeekbench` is a small wrapper around Geekbench 5/6 that:

- Downloads and extracts the appropriate Geekbench Linux tarball (5 or 6) under `/opt/`.
- Runs the benchmark binary on the current host.
- Appends raw output to a dated log file under `/tmp/benchmarkGeekbench[5|6]-YYYYMMDD.log`.
- Prints a human-readable summary (by default).
- Always emits a final programmatic score line:

```text
{{SCORE:12345}}
```

This makes it suitable both for operators on a live console and for automation that only wants the numeric score.

## Usage

Primary entrypoint:

```sh
bin/benchmarkCPUGeekbench.php [--version=5|6] [--score-only] [--no-color]
```

For backward compatibility, `bin/benchmarkGeekbench.php` is also available and forwards to the same implementation.

### Options

- `--version=5`  
  Run Geekbench 5 (default version can be overridden via `GEEKBENCH5_VER` or `GEEKBENCH_VER`).

- `--version=6`  
  Run Geekbench 6 (default when `--version` is omitted; overridable via `GEEKBENCH6_VER` or `GEEKBENCH_VER`).

- `--score-only`  
  Suppress all human-readable output and print only the final score line:

  ```text
  {{SCORE:12345}}
  ```

- `--no-color`  
  Disable ANSI colors in human output. The tool also respects the `NO_COLOR` environment variable.

- `-h`, `--help`  
  Show a brief usage summary.

### Environment Variables

- `GEEKBENCH5_VER`, `GEEKBENCH6_VER`, `GEEKBENCH_VER`  
  Override the Geekbench version string used to build the tarball URL (for example: `6.5.0`).

- `GEEKBENCH5_URL`, `GEEKBENCH6_URL`, `GEEKBENCH_URL`  
  Override the full download URL for the tarball. This is useful for air‑gapped mirrors.

## Paths and Logs

- Binaries are extracted under:

  - `/opt/Geekbench-<version>-Linux/`

- Logs are appended under:

  - `/tmp/benchmarkGeekbench5-YYYYMMDD.log`
  - `/tmp/benchmarkGeekbench6-YYYYMMDD.log`

Each run appends the raw Geekbench output to the corresponding log file for the current date.

## Exit Codes

- `0` – Geekbench ran successfully and a score was parsed.
- Non‑zero – Preparation, execution, or parsing failed; error details are printed to `stderr`.
