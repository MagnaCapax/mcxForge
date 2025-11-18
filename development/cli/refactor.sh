#!/usr/bin/env bash
# Author: Aleksi Ursin
set -euo pipefail

echo "[refactor] starting refactor prompt assembly…" >&1

# refactor.sh — one-command helper
#
# Defaults:
#  - Focuses on files touched in the last N commits (default: 10)
#  - Optionally narrows scope to a target path or mode
#  - Assembles a refactor-focused prompt honoring repository rails
#  - If --exec is provided, pipes to your assistant CLI
#
# Usage:
#  development/cli/refactor.sh                      # build prompt; prints location
#  development/cli/refactor.sh --commits 10         # adjust commit window
#  development/cli/refactor.sh --target lib/php     # restrict target tree
#  development/cli/refactor.sh --mode complexity    # hint refactor intent
#  development/cli/refactor.sh --exec 'codex'       # invoke assistant directly
#  development/cli/refactor.sh --prompt "..."       # custom high-level prompt

HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
cd "$ROOT"

commits=""
mode=""
target=""
exec_cmd=""
custom_prompt=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --commits)
      commits=${2:-}
      shift 2 || true
      ;;
    --mode)
      mode=${2:-}
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
      sed -n '1,120p' "$0"
      exit 0
      ;;
    *)
      echo "[refactor] unknown option: $1" >&2
      exit 2
      ;;
  esac
done

args=()
[[ -n "$commits" ]] && args+=(--commits "$commits")
[[ -n "$mode" ]] && args+=(--mode "$mode")
[[ -n "$target" ]] && args+=(--target "$target")
[[ -n "$custom_prompt" ]] && args+=(--prompt "$custom_prompt")
[[ -n "$exec_cmd" ]] && args+=(--exec "$exec_cmd")

set +e
bash "$ROOT/development/cli/refactor-codex.sh" "${args[@]}"
rc=$?
set -e
echo "[refactor] refactor-codex.sh exited with rc=$rc" >&1
exit $rc
