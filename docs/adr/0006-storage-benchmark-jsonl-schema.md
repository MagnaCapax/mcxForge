# ADR-0006: Storage Benchmark JSONL Score Schema

## Metadata
- Author: Aleksi Ursin
- Status: Draft

## Context

mcxForge now has several storage benchmarks alongside the CPU and memory
benchmarks covered by ADR-0005:

- `bin/benchmarkStorageIOPing.php` – latency/IOPS via `ioping`.
- `bin/benchmarkStorageHdparm.php` – cached + buffered reads via `hdparm -Tt`.
- `bin/benchmarkStorageSequentialRead.php` – mid-device sequential throughput via `dd` with `iflag=direct`.
- `bin/benchmarkStorageFioRandRead.php` – read-only random read IOPS via `fio`.
- `bin/benchmarkStorageFioMetadata.php` – metadata-heavy filesystem workload via `fio` directory mode.

Today these tools:

- Emit a human-friendly summary.
- End with a single `{{SCORE:...}}` line for simple shell/CI consumption.
- Log raw output to `/tmp/benchmarkStorage*` files.

They do **not** yet emit a uniform JSON score line comparable to the
CPU schema in ADR-0005. As storage coverage grows (more fio profiles,
metadata tests, potential destructive modes behind explicit flags), we
need:

- A single JSON score shape for storage benchmarks.
- Clear semantics for what `score` means per benchmark.
- Machine-friendly metadata about:
  - Which device was tested.
  - Which profile was run (sequential vs. random, bs, iodepth, etc.).
  - Which mode/profile (quick/full/long) was selected.
  - Where detailed logs live.

This ADR sketches a **draft** schema and mapping, so we can implement
the JSON lines later in a straightforward way without fighting design
questions again.

## Goals

- Mirror the spirit of ADR-0005 (CPU JSONL schema) for storage:
  - One small, stable JSON object per successful run.
  - Human output remains unchanged.
  - `{{SCORE:...}}` stays for simple scripting.
- Provide enough fields to:
  - Identify the benchmark type and device.
  - Record the primary metric and unit.
  - Capture basic profile parameters (bs, iodepth, mode).
  - Point to the per-tool log file for deeper analysis.
- Leave room for future extensions (queue-depth matrices, mixed
  read/write, suite orchestration) without breaking existing JSON
  consumers.

## Non-Goals (for v1)

- No attempt to encode the full per-profile matrix results for fio in
  the JSON line. Those remain in log files for now.
- No cross-benchmark aggregation in this schema (that is the job of a
  future suite schema).
- No destructive modes (write, verify, secure wipe) are covered here;
  those would require separate guardrails and possibly separate
  schemas.

## Proposed Schema: mcxForge.storage-benchmark.v1

### Top-level fields (common)

Each storage benchmark JSON score line would carry the following
fields:

- `schema` (string, required)  
  Always `mcxForge.storage-benchmark.v1` for this version.

- `benchmark` (string, required)  
  Identifies the storage benchmark entrypoint. Initial values:
  - `storage-ioping`        (for `benchmarkStorageIOPing.php`)
  - `storage-hdparm`        (for `benchmarkStorageHdparm.php`)
  - `storage-seqread`       (for `benchmarkStorageSequentialRead.php`)
  - `storage-fio-randread`  (for `benchmarkStorageFioRandRead.php`)
  - `storage-fio-metadata`  (for `benchmarkStorageFioMetadata.php`)

- `status` (string, required)  
  - `ok` when the benchmark ran and a score was successfully parsed.
  - No JSON line is emitted on failure; the absence of a JSON line plus
    non-zero exit status signals failure (same pattern as CPU).

- `metric` (string, required)  
  Metric identifier for the score:
  - `iops`                 (ioping, fio randread, fio metadata)
  - `buffered_read_mib_s`  (hdparm buffered reads, converted to MiB/s if needed)
  - `seq_read_mib_s`       (dd sequential read)

- `unit` (string, required)  
  Explicit unit string:
  - `IOPS`
  - `MiB/s`
  - (we avoid `MB/s vs MiB/s` confusion by normalizing to MiB/s when we
    choose so in code and reflecting that here).

- `score` (number, required)  
  Primary score for the benchmark:
  - ioping / fio randread: total IOPS for the chosen profile.
  - fio metadata: total read IOPS summed across all worker jobs.
  - hdparm: best buffered read throughput in MiB/s per device.
  - dd sequential: best sequential read throughput in MiB/s.

- `logFile` (string, required)  
  Absolute path to the per-tool log file for this run:
  - ioping: `/tmp/benchmarkStorageIOPing-YYYYMMDD.log`
  - hdparm: `/tmp/benchmarkStorageHdparm-YYYYMMDD.log`
  - seqread: `/tmp/benchmarkStorageSequentialRead-YYYYMMDD.log`
  - fio randread: `/tmp/benchmarkStorageFioRandRead-YYYYMMDD.log`
  - fio metadata: `/tmp/benchmarkStorageFioMetadata-YYYYMMDD.log`

- `device` (string, required for block-device benchmarks)  
  Path to the tested block device when applicable:
  - `/dev/sda`, `/dev/nvme0n1`, `/dev/md0`, etc.
  - For metadata tests targeting a directory, this may be omitted or set
    to a special value (see below).

- `target` (string, required)  
  Human-readable target identifier:
  - For block benchmarks: same as `device` (e.g., `/dev/md0`).
  - For metadata benchmark: the target directory (e.g.,
    `/mnt/scratch/mcxforge-metadata-test`).

### Optional fields (profile / environment)

These fields are optional but strongly recommended where they make
sense. Consumers must tolerate their absence.

- `profile` (string)  
  High-level profile name:
  - `quick`, `full`, `long` for fio-based tests and any other benchmark
    that adopts the profile scheme.

- `mode` (string)  
  Benchmark-mode specific value:
  - For fio randread:
    - `main` vs `matrix` (note: JSON line is tied to the `main`
      profile).
  - For seqread or hdparm:
    - Could be `seq` / `rand` / `buffered`, etc., but this can also be
      inferred from `benchmark` in many cases. v1 MAY leave this empty
      and rely on `benchmark` alone; this field exists for future use.

- `queueDepth` (integer)  
  - For fio randread: `iodepth` used for the canonical profile.
  - For ioping: could reflect `-q`-style concurrency if we introduce
    it later.

- `blockSize` (string)  
  - For fio randread: the `bs` value (e.g., `4k`, `512k`, `1M`).
  - For seqread: block size used by dd (e.g., `1MiB`).

- `durationSeconds` (integer)  
  - Effective run time for the canonical profile.
  - For fio matrix tests, `durationSeconds` refers to each profile’s
    runtime (not the total suite time).

- `jobs` (integer)  
  - For fio-based tests: number of fio jobs (`numjobs`).

- `notes` (string)  
  - Free-form notes for future extensions; not required but can capture
    human-readable hints (e.g., “mdraid resync in progress”).

### Storage-specific considerations

- **Metadata benchmark**:
  - `device` MAY be omitted or set to `null` for the metadata test; the
    primary target is the directory:
    - `target` holds the absolute directory path.
  - `metric= iops`, `unit=IOPS`, `score` is total read IOPS across all
    jobs.

- **MD RAID devices**:
  - For `/dev/md*`, `device` is the md path; we do NOT attempt to stuff
    constituent member devices into this schema (that belongs in a RAID
    health/inventory schema).

## Mapping: Benchmarks → Schema Fields

### benchmarkStorageIOPing.php

- `benchmark`: `storage-ioping`
- `metric`: `iops`
- `unit`: `IOPS`
- `score`: best parsed IOPS across all devices.
- `device` / `target`: `/dev/...` per run.
- Optional:
  - `durationSeconds` – derived from ioping stats if stable, otherwise
    omitted.

### benchmarkStorageHdparm.php

- `benchmark`: `storage-hdparm`
- `metric`: `buffered_read_mib_s`
- `unit`: `MiB/s`
- `score`: best buffered read MB/s per device, normalized to MiB/s if
  needed.
- `device` / `target`: `/dev/...`.

### benchmarkStorageSequentialRead.php

- `benchmark`: `storage-seqread`
- `metric`: `seq_read_mib_s`
- `unit`: `MiB/s`
- `score`: best sequential read MiB/s across devices.
- `device` / `target`: `/dev/...`.
- Optional:
  - `blockSize`: derived from `--bs-mib` (e.g., `1MiB`).
  - `durationSeconds`: approximate runtime per device (not as precise
    as fio; we may estimate from data size and bytes/s).

### benchmarkStorageFioRandRead.php

- `benchmark`: `storage-fio-randread`
- `metric`: `iops`
- `unit`: `IOPS`
- `score`: IOPS from the main profile (bs=512k, iodepth=16 by default)
  for the best device.
- `device` / `target`: `/dev/...`.
- Optional:
  - `profile`: `quick|full|long`.
  - `mode`: likely `main` (we may omit `matrix` from JSON).
  - `queueDepth`: `iodepth` for the canonical profile.
  - `blockSize`: `bs` string (e.g., `512k`).
  - `durationSeconds`: runtime per profile.
  - `jobs`: `numjobs` (currently 1).

### benchmarkStorageFioMetadata.php

- `benchmark`: `storage-fio-metadata`
- `metric`: `iops`
- `unit`: `IOPS`
- `score`: sum of `read.iops` across all jobs.
- `device`: optional / `null`.
- `target`: the directory path used in `--target-dir`.
- Optional:
  - `profile`: `quick|full|long`.
  - `durationSeconds`: runtime.
  - `jobs`: `numjobs`.
  - `blockSize`: `filesize` (e.g., `4k`).
  - `jobs` / `nrfiles`: may be exposed as separate fields if useful.

## Emission Rules

- As with CPU:
  - JSON line is emitted **only** when the benchmark fully succeeds and
    a score is parsed.
  - No JSON line is emitted on failure.
  - The JSON line is the **last thing** printed on stdout on success
    (after human summary).
- The JSON line is prefixed by the benchmark tag for easy grepping:
  - Example:
    - `[benchmarkStorageFioRandRead] {"schema":"mcxForge.storage-benchmark.v1", ...}`
  - Automation MUST treat the text before the first `{` as a prefix and
    parse only from the `{` onward (same rule as ADR-0005).
- `--score-only` behavior:
  - Human-readable output is suppressed.
  - Only the JSON score line is emitted on stdout.
  - The `{{SCORE:...}}` line may remain for a transitional period, but
    long-term we should consider deprecating it in favor of JSON-only
    modes (TBD).

## Interaction with {{SCORE:...}} Lines

- For now, keep `{{SCORE:...}}` as-is to avoid breaking existing
  scripts:
  - It is simple and widely used.
  - JSON is an additive, richer channel.
- Long-term options (to be decided in a future ADR):
  - Deprecate `{{SCORE:...}}` in favor of JSON score lines only.
  - Keep both but guarantee that JSON appears last, while `{{SCORE}}`
    remains a human-ish compatibility hint.

## Open Questions

These are intentionally left for future refinement when we convert the
schema from Draft → Accepted:

- Should storage benchmarks also emit per-iteration JSON (e.g., for
  each fio profile in matrix mode), or only a single canonical score?
- Do we want a unified `benchmark` namespace across CPU/memory/storage
  (e.g., `cpu-geekbench`, `storage-fio-randread`, `memory-sysbench`)?
- Should we normalize MB/s vs. MiB/s strictly, or allow benchmarks to
  report whichever unit they naturally produce and declare it in
  `unit`?
- How much of the profile configuration do we want in the JSON line
  versus relying on log files?

## Migration & Implementation Plan (High-Level)

1. Implement JSON score builder helpers per benchmark:
   - `benchmarkStorageIOPingBuildScorePayload(...)`
   - `benchmarkStorageHdparmBuildScorePayload(...)`
   - `benchmarkStorageSequentialReadBuildScorePayload(...)`
   - `benchmarkStorageFioRandReadBuildScorePayload(...)`
   - `benchmarkStorageFioMetadataBuildScorePayload(...)`

2. Wire JSON emission into each benchmark:
   - After the human summary and before (or replacing) `{{SCORE:...}}`,
     depending on our transitional strategy.

3. Add tests:
   - `*ScoreJsonTest.php` equivalents for storage benchmarks.
   - Validate shape and selected field values (schema, benchmark,
     metric, unit, score, device/target, logFile).

4. Once implementation is stable:
   - Update this ADR from `Status: Draft` → `Accepted`.
   - Reference this ADR from benchmark docs and code comments.

