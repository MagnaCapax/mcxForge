#!/usr/bin/env bash
set -euo pipefail
set -o errtrace

if [[ "${MCXFORGE_CI_CODEX_DEBUG:-0}" == "1" ]]; then
  export PS4='[ci-codex:trace] '
  set -x
fi

# Predeclare for shellcheck: assigned in trap context.
rc=0

trap 'rc=$?; echo "[ci-codex] ERROR rc=$rc at line $LINENO while: $BASH_COMMAND" >&1' ERR

echo "[ci-codex] start: assembling CI context and invoking assistant" >&1

# ci-codex.sh — Fetch latest CI logs and feed them to a coding assistant.
#
# Usage:
#   development/cli/ci-codex.sh                          # assemble prompt + logs into ci-codex/prompt.txt
#   development/cli/ci-codex.sh --job test               # include only 'test' job logs in the prompt
#   development/cli/ci-codex.sh --prompt "text..."       # use custom high-level prompt text
#   development/cli/ci-codex.sh --exec 'codex'           # send prompt to Codex CLI directly

HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"

TMP="${TMPDIR:-/tmp}"
OUTDIR="$(mktemp -d "${TMP%/}/mcxforge-ci-codex-XXXXXXXX")"
ARTDIR="$OUTDIR/artifacts"
SUMMARY="$OUTDIR/run-summary.txt"
JOBLOG="$OUTDIR/job.log"
PROMPT="$OUTDIR/prompt.txt"

DEFAULT_PROMPT=$(
  cat <<'MCXFORGEPROMPT'
mcxForge CI Assist — Strict Rails Mode

Goal: Make required CI jobs pass with the smallest coherent change, while keeping mcxForge’s safety rails intact.

Read First (do not proceed until read):
- AGENTS.md (rails / Constitution / safety doctrine).
- docs/architecture.md (what mcxForge is and is not; layout and boundaries).
- docs/tests.md and docs/ci-cd.md (testing / CI expectations, non-destructive defaults).
- docs/adr/* (when present, governing decisions for the area you touch).

Must Follow:
- Safe-by-default: no destructive operations (wiping, repartitioning, RAID reconfiguration, firmware changes) without explicit flags and guardrails. CI and dev tests must never perform destructive actions.
- KISS / DRY / YAGNI: keep implementations small and boring; reuse existing helpers; avoid clever abstractions and features that are not driven by a concrete use-case.
- Minimal edits & one flow: keep diffs tight and focused; avoid adding new flows or modes unless required by an ADR; prefer improving or deleting existing code.
- Language & dependencies: Bash for orchestration, PHP for more complex workflows; do not add new runtimes or heavy system dependencies without an ADR.
- Interfaces & naming: keep CLI entrypoints and flags stable; use long, kebab-case flags; do not add aliases; follow context-first naming and existing terminology.
- Observability: emit concise, structured output suitable for humans and log scraping; keep logs readable and avoid noisy debug spam.

Absolutes:
- Do not add destructive behaviour to CI or test helpers.
- Do not introduce environment-specific hacks that break use under /opt/mcxForge on live rescue systems.
- Keep operations idempotent and safe to re-run after partial completion.
- Never create git branches from this helper; work on the existing branch only.
- After applying a fix, stage and commit with a clear, focused message (no push).

Workflow (do this now):
- Triage the first failing required CI job/step using the CI summary and job logs listed below.
- Form a hypothesis for the failure and propose the smallest coherent fix consistent with the rails above.
- Implement the fix and verify locally, for example:
  * php -l on each changed PHP file.
  * php development/tests/development/runner.php
  * bash development/testing/test.sh
- Describe what you changed and why, and list the verification commands you ran.

Proceed to triage the CI summary, job logs, and any artifacts. Propose a minimal patch and the commands needed to validate it.
MCXFORGEPROMPT
)

wait_secs="${MCXFORGE_CI_WAIT_SECS:-300}"

job_name=""
exec_cmd=""
custom_prompt=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --job)
      job_name=${2:-}
      shift 2 || true
      ;;
    --exec)
      exec_cmd=${2:-}
      shift 2 || true
      ;;
    --prompt)
      custom_prompt=${2:-}
      shift 2 || true
      ;;
    -h | --help)
      sed -n '1,120p' "$0"
      exit 0
      ;;
    *)
      echo "[ci-codex] unknown option: $1" >&2
      exit 2
      ;;
  esac
done

if ! command -v gh >/dev/null 2>&1; then
  echo "[ci-codex] GitHub CLI not found. Install gh and run 'gh auth login'" >&1
  exit 127
fi

echo "[ci-codex] gh: $(command -v gh)" >&1 || true
gh --version 2>/dev/null | sed 's/^/[ci-codex] /' >&1 || true

mkdir -p "$OUTDIR" "$ARTDIR"

echo "[ci-codex] workspace: $OUTDIR" >&1
echo "[ci-codex] artifact dir: $ARTDIR" >&1

echo "[ci-codex] discovering latest run..." >&1
run_id=$(gh run list --limit 1 --json databaseId --jq '.[0].databaseId')
if [[ -z "$run_id" ]]; then
  echo "[ci-codex] no workflow runs found" >&2
  exit 1
fi

echo "[ci-codex] latest run id: $run_id" >&1

echo "[ci-codex] waiting for run completion (timeout ${wait_secs}s)…" >&1
status=$(gh run view "$run_id" --json status --jq .status 2>/dev/null || echo queued)
deadline=$(($(date +%s) + wait_secs))
while [[ "$status" != "completed" && $(date +%s) -lt $deadline ]]; do
  echo "[ci-codex] run status: $status (waiting)" >&1
  sleep 5
  status=$(gh run view "$run_id" --json status --jq .status 2>/dev/null || echo queued)
done
echo "[ci-codex] run status now: $status" >&1

# Download artifacts (best-effort).
echo "[ci-codex] downloading artifacts to $ARTDIR" >&1
mkdir -p "$ARTDIR"
art_count=0
for attempt in {1..10}; do
  if gh run download "$run_id" --dir "$ARTDIR" >/dev/null 2>&1; then
    :
  fi
  art_count=$(find "$ARTDIR" -type f 2>/dev/null | wc -l | tr -d ' ')
  if [[ "$art_count" -gt 0 || "$status" == "completed" && $attempt -ge 3 ]]; then
    break
  fi
  echo "[ci-codex] artifacts not ready (attempt $attempt); waiting…" >&1
  sleep 5
done
echo "[ci-codex] artifacts downloaded: $art_count file(s)" >&1

# Prepare CI summary and capture latest artifact path for reference.
gh run view "$run_id" >"$SUMMARY" || true
latest_art=""
if compgen -G "$ARTDIR/*" >/dev/null; then
  latest_art=$(find "$ARTDIR" -type f -printf '%T@ %p\n' | sort -nr | head -n1 | cut -d' ' -f2-)
fi

fetch_job_log() {
  local name="$1" out="$2"
  local id
  id=$(gh run view "$run_id" --json jobs --jq ".jobs[] | select(.name == \"$name\").databaseId") || true
  if [[ -n "$id" ]]; then
    gh run view --job "$id" --log >"$out" || true
  fi
}

if [[ -n "$job_name" ]]; then
  echo "[ci-codex] fetching job logs for '$job_name'" >&1
  fetch_job_log "$job_name" "$JOBLOG"
else
  echo "[ci-codex] fetching job logs for 'test'" >&1
  fetch_job_log "test" "$OUTDIR/job-test.log"
fi

nonempty_logs=0
for attempt in {1..10}; do
  nonempty_logs=0
  for jl in "$OUTDIR"/job-*.log "$JOBLOG"; do
    [[ -s "$jl" ]] && nonempty_logs=$((nonempty_logs + 1))
  done
  if [[ $nonempty_logs -gt 0 || "$status" == "completed" && $attempt -ge 3 ]]; then
    break
  fi
  echo "[ci-codex] job logs not ready (attempt $attempt); waiting…" >&1
  sleep 5
  if [[ -n "$job_name" ]]; then
    fetch_job_log "$job_name" "$JOBLOG"
  else
    fetch_job_log "test" "$OUTDIR/job-test.log"
  fi
done
echo "[ci-codex] job logs present: $nonempty_logs file(s)" >&1

prompt_text=${custom_prompt:-$DEFAULT_PROMPT}
{
  echo "$prompt_text"
  echo
  echo "Context to open (paths in this workspace):"
  echo " - $SUMMARY (CI summary)"
  for jl in "$OUTDIR"/job-*.log "$JOBLOG"; do
    [[ -s "$jl" ]] || continue
    echo " - $jl"
  done
  if [[ -n "$latest_art" ]]; then
    echo " - $latest_art (newest artifact file)"
  fi
  echo
  echo "Do not inline these; read them directly from disk."
} >"$PROMPT"

prompt_bytes=$(wc -c <"$PROMPT" | tr -d ' ')
prompt_lines=$(wc -l <"$PROMPT" | tr -d ' ')
echo "[ci-codex] prompt written: $PROMPT (${prompt_bytes} bytes, ${prompt_lines} lines)" >&1

prompt_str=$(cat "$PROMPT")
if [[ -n "$exec_cmd" && "$exec_cmd" != "codex" ]]; then
  echo "[ci-codex] unsupported --exec value ('$exec_cmd'); defaulting to 'codex'" >&1
fi
if command -v codex >/dev/null 2>&1; then
  echo "[ci-codex] invoking: codex [prompt-string]" >&1
  codex "$prompt_str" || {
    echo "[ci-codex] codex invocation failed. Run manually:" >&1
    echo "  codex \"\$(cat '$PROMPT')\"" >&1
    exit 1
  }
else
  echo "[ci-codex] Codex CLI not found. Run manually:" >&1
  echo "  codex \"\$(cat '$PROMPT')\"" >&1
fi

# Auto-commit any changes created by the assistant (no branches, no push)
MCXFORGE_CI_AUTOCOMMIT=${MCXFORGE_CI_AUTOCOMMIT:-1}
if [[ "$MCXFORGE_CI_AUTOCOMMIT" == "1" ]]; then
  if command -v git >/dev/null 2>&1 && git -C "$ROOT" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    echo "[ci-codex] auto-commit: checking for changes" >&1
    if [[ -n "$(git -C "$ROOT" status --porcelain)" ]]; then
      msg="ci-codex: apply assistant changes for run $run_id"
      git -C "$ROOT" add -A
      git -C "$ROOT" commit -m "$msg" && echo "[ci-codex] auto-commit: committed changes" >&1 || echo "[ci-codex] auto-commit: commit failed" >&1
    else
      echo "[ci-codex] auto-commit: no changes to commit" >&1
    fi
  else
    echo "[ci-codex] auto-commit: git not available or not inside a repo" >&1
  fi
else
  echo "[ci-codex] auto-commit disabled (MCXFORGE_CI_AUTOCOMMIT=0)" >&1
fi

echo "[ci-codex] done" >&1
