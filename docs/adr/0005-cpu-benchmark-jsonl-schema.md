# ADR-0005: CPU Benchmark JSONL Score Schema

## Metadata
- Author: Aleksi Ursin
- Status: Accepted

## Context
- mcxForge includes multiple CPU benchmarks (Geekbench, sysbench, stress-ng, xmrig) that will be run both manually and by higher-level automation.
- Early prototypes emitted ad-hoc `{{SCORE:...}}` lines, with inconsistent semantics (sometimes per-thread, sometimes total, sometimes without a clear prefix) and no structured metadata.
- To keep downstream tooling simple, we need one small, uniform, machine-parsable score format that:
  - Works identically across all CPU benchmarks.
  - Exposes a single primary score while leaving room for per-thread variants and other context.
  - Is easy to consume from shell or PHP scripts without bespoke parsing per tool.

## Decision
- All CPU benchmark entrypoints under `bin/` MUST emit exactly one JSON score line on successful completion:
  - The line is prefixed with a benchmark tag for easy grepping, followed by a single JSON object:
    - Geekbench: `[benchmarkCPUGeekbench] {...}`
    - sysbench: `[benchmarkCPUSysbench] {...}`
    - stress-ng: `[benchmarkCPUStressNg] {...}`
    - xmrig: `[benchmarkCPUXmrig] {...}`
  - Automation MUST treat everything before the first `{` as a prefix and parse only the JSON object from that `{` onward.

- JSON schema (v1):
  - All CPU benchmark JSON score lines MUST share the following core shape:

    - `schema` (string, required)  
      Always `mcxForge.cpu-benchmark.v1` for this version.

    - `benchmark` (string, required)  
      Identifies the benchmark entrypoint. Current values:
      - `cpugeekbench`
      - `cpusysbench`
      - `cpustressng`
      - `cpuxmrig`

    - `status` (string, required)  
      - `ok` when the benchmark ran and a score was successfully parsed.
      - No JSON line is emitted on failure; the absence of a JSON line and a non-zero exit code together signal failure.

    - `metric` (string, required)  
      Indicates the underlying metric:
      - Geekbench: `geekbench_score`
      - sysbench: `events_per_second`
      - stress-ng: `bogo_ops_per_second`
      - xmrig: `hashrate`

    - `unit` (string, required)  
      Explicit unit for the metric:
      - Geekbench: `score`
      - sysbench: `events/s`
      - stress-ng: `bogo ops/s`
      - xmrig: `H/s`

    - `score` (number, required)  
      Primary score for the benchmark, always the multi-thread total:
      - Geekbench: multi-core score when present, otherwise single-core score.
      - sysbench: total events per second.
      - stress-ng: total real-time bogo ops/s for the CPU stressor.
      - xmrig: average total hash rate in H/s across all threads.

    - `logFile` (string, required)  
      Absolute path to the log file containing raw benchmark output for this run.

  - Optional fields (MAY be present when meaningful):

    - `scorePerThread` (number)  
      Normalized per-thread score, when a thread count is well-defined:
      - sysbench & stress-ng: `score / threads` (events/s or bogo ops/s per worker).
      - xmrig: `score / threads` (H/s per thread) when the effective thread count is known.

    - `scoreSingleThread` (number)  
      A single-thread score, when natively provided by the benchmark:
      - Geekbench: single-core score when available.

    - `threads` (integer)  
      Number of worker threads or CPU workers used by the benchmark.

    - `durationSeconds` (integer)  
      Effective run time for the benchmark (for example, sysbench/stress-ng duration, xmrig configured duration).

    - Additional benchmark-specific fields MAY be added in v1 as long as they do not change the semantics of `score`.

- Emission rules:
  - The JSON score line MUST be the last thing written to stdout on success (both in normal and `--score-only` modes).
  - `--score-only` mode MUST suppress human-readable chatter and emit only the JSON score line on stdout.
  - On any failure (tool not found, non-zero exit, parse failure), the benchmark MUST:
    - Exit with a non-zero status.
    - Print human-readable error context to stderr (including the log path).
    - Emit no JSON score line to stdout.

## Consequences
- Downstream tools can treat all CPU benchmarks uniformly:
  - Capture stdout, find the first `{`, `json_decode` the remainder, and inspect `benchmark` and `score`.
  - Store `{benchmark, score}` plus optional `scorePerThread` or `scoreSingleThread` without bespoke per-tool parsers.
- Human operators retain useful console output:
  - Start line showing the benchmark, duration, threads, and log file path.
  - Parsed score summary including per-thread normalization where applicable.
  - A final JSON score line for automation and archival.
- Future CPU benchmarks must:
  - Reuse the same JSON schema and prefix pattern.
  - Choose a clear `benchmark` identifier and `metric`/`unit` pair.
  - Populate `score` with the best multi-thread total available.

## Guardrails
- Any change to the meaning of `score`, `metric`, or `unit` for an existing benchmark, or any backwards-incompatible change to the JSON shape, requires:
  - A new schema identifier (for example, `mcxForge.cpu-benchmark.v2`).
  - A new ADR that documents the evolution from v1 to v2.
- Additional fields MAY be added under the `mcxForge.cpu-benchmark.v1` schema as long as:
  - Existing consumers that rely only on `score` and `benchmark` continue to work.
  - The semantics of existing fields do not change.

## Implementation Notes
- As of this ADR, the following entrypoints implement the v1 schema:
  - `bin/benchmarkCPUGeekbench.php`
  - `bin/benchmarkCPUSysbench.php`
  - `bin/benchmarkCPUStressNg.php`
  - `bin/benchmarkCPUXmrig.php`
- JSON score payloads are constructed by:
  - `benchmarkGeekbenchBuildScorePayload(...)`
  - `benchmarkCPUSysbenchBuildScorePayload(...)`
  - `benchmarkCPUStressNgBuildScorePayload(...)`
  - `benchmarkCPUXmrigBuildScorePayload(...)`
- Tests under `development/tests/development/` validate payload shape for each benchmark to keep the schema stable.

