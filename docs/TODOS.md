# TODOs & Future Directions (mcxForge)

This file collects medium-term ideas and future improvements so they can be
prioritized and carved into focused ADRs and implementation steps later.

## Benchmark Suites

- `benchmarkSuiteCPU.php`  
  Orchestrate CPU benchmarks (Geekbench, sysbench, stress-ng, xmrig) and emit
  a JSON/JSONL summary using the schema from `docs/adr/0005-cpu-benchmark-jsonl-schema.md`.

- `benchmarkSuiteStorage.php`  
  Orchestrate storage benchmarks (ioping, hdparm, sequential dd, fio randread
  profiles) and emit both human-readable and JSON summaries per device and
  MD RAID array.

- `benchmarkSuiteRAM.php`  
  Coordinate memory stress/benchmark tools (stress-ng, sysbench memory) and
  provide a normalized `{{SCORE:...}}` metric plus JSON payloads.

- `benchmarkSuiteNET.php`  
  Network throughput and latency checks (iperf-style tooling, basic ping
  matrix) with non-destructive defaults and guarded flags for heavier tests.

- `benchmarkSuite.php`  
  High-level entrypoint to run the above suites in a controlled order for
  full-node qualification and emit a single machine-readable report.

## Storage Benchmark Matrix (Fio)

- Extend `benchmarkStorageFioRandRead.php` with:
  - More flexible matrix definitions (CLI flags to select subsets of
    block sizes and queue depths).
  - Optional mixed read/write profiles with explicit `--allow-write` /
    `--destructive-target` style guardrails.
  - Per-profile JSON output that can be aggregated by future suites.

## Metadata & Latency Microbenchmarks

- Investigate a metadata/latency microbenchmark that:
  - Exercises directory operations and file metadata (create/open/close/stat).
  - Supports multiple worker threads to stress FS metadata paths.
  - Runs against an explicit test directory (never arbitrary live trees).
  - Can be implemented either via fio directory mode or a small dedicated
    helper.

## Output & Reporting

- Consider a small shared helper for benchmark JSON output schemas beyond
  CPU (storage, memory, network) once patterns stabilize.
- Explore rotating JSONL logs for benchmark runs under `/tmp/` or a
  configurable mcxForge state directory, keeping retention small but useful
  for forensic analysis.
