#!/usr/bin/env bash
# Author: Aleksi Ursin
set -euo pipefail
set -o errtrace

if [[ "${MCXFORGE_REFACTOR_CODEX_DEBUG:-0}" == "1" ]]; then
  export PS4='[refactor-codex:trace] '
  set -x
fi

# Predeclare for shellcheck: assigned in trap context.
rc=0

trap 'rc=$?; echo "[refactor-codex] ERROR rc=$rc at line $LINENO while: $BASH_COMMAND" >&1' ERR

echo "[refactor-codex] start: assembling refactor context and invoking assistant" >&1

# refactor-codex.sh — Analyze recent commits and complexity reports, then
# assemble a strict-rails refactor prompt for a coding assistant.
#
# Usage:
#   development/cli/refactor-codex.sh                            # assemble prompt into refactor-codex/prompt.txt
#   development/cli/refactor-codex.sh --commits 10               # include last 10 commits
#   development/cli/refactor-codex.sh --target lib/php           # narrow scope to a subtree
#   development/cli/refactor-codex.sh --prompt "text..."         # use custom high-level prompt text
#   development/cli/refactor-codex.sh --exec 'codex'             # send prompt to Codex CLI directly

HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"

TMP="${TMPDIR:-/tmp}"
OUTDIR="$(mktemp -d "${TMP%/}/mcxforge-refactor-codex-XXXXXXXX")"
COMMITS_SUMMARY="$OUTDIR/commits-summary.txt"
COMMITS_FILES="$OUTDIR/commits-files.txt"
CANDIDATES="$OUTDIR/candidate-files.txt"
PROMPT="$OUTDIR/prompt.txt"

DEV_DIR="$ROOT/development"
LOG_DIR="$DEV_DIR/var/test-logs"
PHPLC_LOG="$LOG_DIR/complexity-phploc.txt"
PHPMD_LOG="$LOG_DIR/complexity-phpmd.txt"

commits=10
target=""
exec_cmd=""
custom_prompt=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --commits)
      commits=${2:-}
      shift 2 || true
      ;;
    --target)
      target=${2:-}
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
      sed -n '1,160p' "$0"
      exit 0
      ;;
    *)
      echo "[refactor-codex] unknown option: $1" >&2
      exit 2
      ;;
  esac
done

if ! [[ "$commits" =~ ^[0-9]+$ ]] || [[ "$commits" -le 0 ]]; then
  echo "[refactor-codex] invalid --commits value: $commits" >&2
  exit 2
fi

echo "[refactor-codex] output directory: $OUTDIR" >&1

# Gather recent commits and touched files (best-effort).
if git -C "$ROOT" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  echo "[refactor-codex] collecting last $commits commits…" >&1
  git -C "$ROOT" log -n "$commits" --pretty=format:'%h %s' >"$COMMITS_SUMMARY" || true
  git -C "$ROOT" log -n "$commits" --name-only --pretty=format:'--- %H' \
    | awk '/^--- / { next } NF { print }' \
    | sort -u >"$COMMITS_FILES" || true
else
  echo "[refactor-codex] not inside a git repository; skipping commit context" >&1
fi

# Ensure complexity logs exist (best-effort; scripts may be no-ops if tools missing).
mkdir -p "$LOG_DIR"
if [[ ! -f "$PHPLC_LOG" ]]; then
  echo "[refactor-codex] phploc complexity log missing; attempting to generate" >&1
  bash "$DEV_DIR/testing/complexity-phploc.sh" || true
fi
if [[ ! -f "$PHPMD_LOG" ]]; then
  echo "[refactor-codex] phpmd complexity log missing; attempting to generate" >&1
  bash "$DEV_DIR/testing/complexity-phpmd.sh" || true
fi

if [[ -f "$PHPLC_LOG" ]]; then
  echo "[refactor-codex] phploc complexity log: $PHPLC_LOG" >&1
fi
if [[ -f "$PHPMD_LOG" ]]; then
  echo "[refactor-codex] phpmd complexity log: $PHPMD_LOG" >&1
fi

# Build a candidate file list from recent commits, optionally narrowed by target.
if [[ -s "$COMMITS_FILES" ]]; then
  cp "$COMMITS_FILES" "$CANDIDATES"
  if [[ -n "$target" ]]; then
    # Keep any file whose path contains the target substring.
    awk -v t="$target" 'index($0, t) > 0' "$CANDIDATES" >"$CANDIDATES.tmp" || true
    if [[ -s "$CANDIDATES.tmp" ]]; then
      mv "$CANDIDATES.tmp" "$CANDIDATES"
    else
      rm -f "$CANDIDATES.tmp"
    fi
  fi
else
  : >"$CANDIDATES"
fi

DEFAULT_PROMPT=$(
  cat <<'MCXFORGEREFACTORPROMPT'
mcxForge Refactor Assist — Strict Rails Mode

Goal: Make the mcxForge codebase simpler, smaller, and more DRY by performing a tiny, behavior-preserving refactor or deletion in the most recent and most complex areas of the code, while strictly honoring the repository rails and safety doctrine.

High-level expectations:
- Think like a highly experienced, 10x senior engineer with strong systems and reliability instincts.
- Optimize for minimal cognitive load: code should be easy to reason about under pressure on a live console.
- Prefer architecture-by-data and code reuse over ad-hoc branching and copy-paste.
- Favor deletion and simplification over new abstractions; the best component is often no component.

Read first (do not proceed until read):
- AGENTS.md (rails / Constitution / safety and design doctrine).
- docs/architecture.md (what mcxForge is and is not; layout and boundaries).
- docs/tests.md and docs/ci-cd.md (testing / CI expectations, non-destructive defaults).
- docs/adr/* (especially any Accepted ADRs relevant to the target files).

Core engineering principles to honor (and not dilute):
- KISS: keep implementations boring, straightforward, and easy to follow; avoid cleverness.
- DRY: remove duplication via shared helpers; do not copy-paste logic.
- YAGNI: do not add new features, flags, or modes that are not required by a concrete, immediate need.
- Pit of Success: make the safe, correct path the default; dangerous paths must be explicit and noisy.
- Delete before add: first look for code that can be removed or consolidated; do not pile on new layers.
- Minimize part count: fewer tools, helpers, and flows that do the right thing are better than many almost-duplicates.
- One flow: avoid combinatorial explosion of modes; prefer one clear flow per major operation.
- Operate from constraints: safety, idempotence, observability, and cross-environment robustness come first.

Scope for this refactor:
- Focus on files touched in the last N commits and captured in the commit summary and file list.
- Optionally narrow scope to a target subtree (for example, lib/php or a specific tool).
- Use complexity snapshots (phploc/phpmd) to prioritize the most complex or messy files within that set.
- Do not roam outside this scope unless absolutely necessary to achieve DRY within the change budget.

Hard rails (must follow all of these):
- No behavior changes:
  - Do not change CLI flags, argument semantics, environment variables, JSON field names, or exit codes.
  - Do not change user-visible text: help output, log lines, JSON schemas, or report formats.
  - Treat bin/ entrypoints and existing tests as behavioral contracts.
- No safety regressions:
  - Do not make destructive operations easier to trigger or less guarded.
  - Do not introduce new destructive code paths (wiping, repartitioning, RAID, firmware, etc.).
  - You may add safety checks or early exits, but do not weaken existing guardrails.
- Minimal, local edits:
  - Touch at most ~5 files and keep total additions + deletions small (aim for <= 200 lines, preferably less).
  - If a refactor idea cannot be safely completed within this budget, do not start it.
  - Prefer a single, coherent change in one subsystem over scattered tweaks.
- Deletion and DRY as primary goals:
  - Prefer deleting unused or redundant code, scripts, or helpers over adding new abstractions.
  - Prefer consolidating near-duplicate code into a shared helper callable from multiple sites.
  - Avoid adding new modes, flags, or configuration knobs; no new public flows.
- No interface changes:
  - CLI contracts, JSON schemas, and filesystem layouts are stable; do not change them.
  - Only adjust docs to better describe existing behavior, not to introduce new semantics.
- Tests and verification:
  - Do not weaken or delete tests that encode current behavior.
  - You may add tests around code you simplify, but they must not relax expectations.
  - Keep destructive behavior out of tests; never introduce real wipes or repartitioning into default test flows.

Refactor style guidance:
- Choose a small target:
  - Prefer a single file or tight cluster of related files, especially those from the last few commits or high-complexity reports.
  - Avoid sweeping cross-cutting changes; favor small, surgical refactors.
- Look for:
  - Unused functions, classes, or scripts not referenced by bin/ entrypoints or tests, and remove them when safe.
  - Obvious duplication that can be replaced with a small shared helper or data table.
  - Overly nested conditionals that can be simplified without changing logic.
  - Trivial wrapper functions that add no clarity; consider inlining them.
- Prefer architecture-by-data:
  - Replace repeated conditional branches with simple data tables or parameterized helpers where it clearly reduces repeated code.
  - Reuse existing terminology and concepts from AGENTS.md and ADRs; avoid new names for old ideas.
- Cognitive load:
  - Aim for code that a tired operator or maintainer can understand quickly.
  - Minimize the number of concepts and code paths needed to understand how a tool works.

Workflow (do this now):
1) Read AGENTS.md, docs/architecture.md, docs/tests.md, docs/ci-cd.md, and relevant docs/adr/* files.
2) Inspect the listed recent commits, changed files, and complexity reports.
3) Pick one small, high-value refactor or deletion opportunity that fits the rails above.
4) Implement the change with minimal edits:
   - Keep behavior and outputs identical.
   - Prefer deletion and DRY improvements; avoid adding new features or modes.
   - Keep changes localized to the chosen scope.
5) Run local verification:
   - php -l on each changed PHP file.
   - php development/tests/development/runner.php when touching lib/php or bin/*.php.
   - bash development/testing/test.sh for the full non-destructive suite.
6) Stage and commit:
   - After verifying, stage the changes and commit with a clear, focused message (for example, "refactor: deduplicate storage helpers").
   - Do not create new branches or push; keep work on the current branch.
7) Summarize:
   - What you simplified or deleted.
   - Why it is safe and behavior-preserving.
   - Which verification commands you ran.

Operate with SpaceX/Tesla-style engineering discipline:
- Respect constraints and safety margins.
- Prefer designs that fail soft, are observable, and are easy to recover.
- Favor small, iterative improvements that steadily reduce complexity and increase reliability.

MCXFORGEREFACTORPROMPT
)

prompt_text=${custom_prompt:-$DEFAULT_PROMPT}
{
  echo "$prompt_text"
  echo
  echo "Context to open (paths in this workspace):"
  if [[ -f "$COMMITS_SUMMARY" ]]; then
    echo " - $COMMITS_SUMMARY (recent commits summary)"
  fi
  if [[ -f "$COMMITS_FILES" ]]; then
    echo " - $COMMITS_FILES (files touched in the last $commits commits)"
  fi
  if [[ -f "$CANDIDATES" ]]; then
    echo " - $CANDIDATES (candidate files within scope)"
  fi
  if [[ -f "$PHPLC_LOG" ]]; then
    echo " - $PHPLC_LOG (phploc complexity snapshot)"
  fi
  if [[ -f "$PHPMD_LOG" ]]; then
    echo " - $PHPMD_LOG (phpmd complexity report)"
  fi
  echo
  echo "Do not inline these; read them directly from disk."
} >"$PROMPT"

prompt_bytes=$(wc -c <"$PROMPT" | tr -d ' ')
prompt_lines=$(wc -l <"$PROMPT" | tr -d ' ')
echo "[refactor-codex] prompt written: $PROMPT (${prompt_bytes} bytes, ${prompt_lines} lines)" >&1

prompt_str=$(cat "$PROMPT")
if [[ -n "$exec_cmd" && "$exec_cmd" != "codex" ]]; then
  echo "[refactor-codex] unsupported --exec value ('$exec_cmd'); defaulting to 'codex'" >&1
fi
if command -v codex >/dev/null 2>&1; then
  echo "[refactor-codex] invoking: codex [prompt-string]" >&1
  codex "$prompt_str" || {
    echo "[refactor-codex] codex invocation failed. Run manually:" >&1
    echo "  codex \"\$(cat '$PROMPT')\"" >&1
    exit 1
  }
else
  echo "[refactor-codex] Codex CLI not found. Run manually:" >&1
  echo "  codex \"\$(cat '$PROMPT')\"" >&1
fi

# Auto-commit any changes created by the assistant (no branches, no push).
MCXFORGE_REFACTOR_AUTOCOMMIT=${MCXFORGE_REFACTOR_AUTOCOMMIT:-1}
MCXFORGE_REFACTOR_MAX_FILES=${MCXFORGE_REFACTOR_MAX_FILES:-20}
MCXFORGE_REFACTOR_MAX_LINES=${MCXFORGE_REFACTOR_MAX_LINES:-1000}
if [[ "$MCXFORGE_REFACTOR_AUTOCOMMIT" == "1" ]]; then
  if command -v git >/dev/null 2>&1 && git -C "$ROOT" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    echo "[refactor-codex] auto-commit: checking for changes" >&1
    if [[ -n "$(git -C "$ROOT" status --porcelain)" ]]; then
      echo "[refactor-codex] auto-commit: running tests (development/testing/test.sh)" >&1
      if ! bash "$DEV_DIR/testing/test.sh"; then
        echo "[refactor-codex] auto-commit: tests failed; NOT committing changes" >&1
      else
        shortstat=$(git -C "$ROOT" diff --shortstat HEAD 2>/dev/null || true)
        file_count=0
        line_count=0
        if [[ -n "$shortstat" ]]; then
          file_count=$(echo "$shortstat" | awk '{print $1+0}')
          line_count=$(echo "$shortstat" | awk '{
            ins=0; del=0;
            for (i=1; i<=NF; i++) {
              if ($(i+1) ~ /^insertions?\\(\\+\\),?$/) ins=$i;
              if ($(i+1) ~ /^deletions?\\(-\\),?$/) del=$i;
            }
            print ins+del;
          }')
        fi
        if [[ "$file_count" -gt "$MCXFORGE_REFACTOR_MAX_FILES" || "$line_count" -gt "$MCXFORGE_REFACTOR_MAX_LINES" ]]; then
          echo "[refactor-codex] auto-commit: diff too large (files=$file_count, lines=$line_count, limits files=$MCXFORGE_REFACTOR_MAX_FILES, lines=$MCXFORGE_REFACTOR_MAX_LINES); NOT committing changes" >&1
        else
          msg="refactor-codex: apply assistant refactor"
          git -C "$ROOT" add -A
          if git -C "$ROOT" commit -m "$msg"; then
            echo "[refactor-codex] auto-commit: committed changes" >&1
          else
            echo "[refactor-codex] auto-commit: commit failed" >&1
          fi
        fi
      fi
    else
      echo "[refactor-codex] auto-commit: no changes to commit" >&1
    fi
  else
    echo "[refactor-codex] auto-commit: git not available or not inside a repo" >&1
  fi
else
  echo "[refactor-codex] auto-commit disabled (MCXFORGE_REFACTOR_AUTOCOMMIT=0)" >&1
fi

echo "[refactor-codex] done" >&1
