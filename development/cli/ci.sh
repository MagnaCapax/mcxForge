#!/usr/bin/env bash
# Author: Aleksi Ursin
set -euo pipefail

echo "[ci] starting CI prompt assembly…" >&1

# ci.sh — one-command helper
#
# Defaults:
#  - Downloads artifacts from the latest CI run to a temp workspace
#  - Includes the CI job logs (if present)
#  - Assembles a QA-focused prompt honoring repository rails
#  - If --exec is provided, pipes to your assistant CLI
#
# Usage:
#  development/cli/ci.sh                 # build prompt; prints location
#  development/cli/ci.sh --exec 'codex chat --input -'
#  development/cli/ci.sh --job test      # include specific job logs
#  development/cli/ci.sh --prompt "..."  # custom prompt

HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
cd "$ROOT"

job=""
exec_cmd=""
custom_prompt=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --job)
      job=${2:-}
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
      sed -n '1,80p' "$0"
      exit 0
      ;;
    *)
      echo "[ci] unknown option: $1" >&2
      exit 2
      ;;
  esac
done

args=()
[[ -n "$job" ]] && args+=(--job "$job")
[[ -n "$custom_prompt" ]] && args+=(--prompt "$custom_prompt")
[[ -n "$exec_cmd" ]] && args+=(--exec "$exec_cmd")

set +e
bash "$ROOT/development/cli/ci-codex.sh" "${args[@]}"
rc=$?
set -e
echo "[ci] ci-codex.sh exited with rc=$rc" >&1
exit $rc
