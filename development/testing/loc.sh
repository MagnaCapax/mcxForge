#!/usr/bin/env bash
# Author: Aleksi Ursin
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
DEV_DIR="$ROOT_DIR/development"

count_find() {
  local desc="$1"; shift
  local dir="$1"; shift
  local expr=("$@")
  local total
  mapfile -d '' FILES < <(find "$dir" "${expr[@]}" -print0 2>/dev/null || true)
  if [[ ${#FILES[@]} -eq 0 ]]; then
    total=0
  else
    total=$(wc -l "${FILES[@]}" | tail -n1 | awk '{print $1}')
  fi
  printf "%-18s %6d\n" "$desc:" "${total:-0}"
}

echo "Lines of code (by category)"
echo "---------------------------------"

# PHP entrypoints
count_find "Bin PHP" "$ROOT_DIR/bin" -type f -name '*.php'

# Tests PHP
count_find "Tests PHP" "$DEV_DIR/tests" -type f -name '*.php'

# Bash scripts (development)
count_find "Bash scripts" "$DEV_DIR" -type f -name '*.sh'

# ADR markdown
count_find "Docs ADR" "$ROOT_DIR/docs/adr" -type f -name '*.md'

# Other docs markdown
count_find "Docs other" "$ROOT_DIR/docs" -type f -name '*.md' ! -path "$ROOT_DIR/docs/adr/*"

echo "---------------------------------"
