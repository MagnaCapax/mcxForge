#!/usr/bin/env bash
# Author: Aleksi Ursin
set -uo pipefail

# Doctrine/constitution enforcement lints for mcxForge.
# Currently focuses on ADR hygiene and basic docs layout.

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
FAIL=0

check_no_apis_docs() {
  if [[ -d "$ROOT_DIR/docs/apis" ]]; then
    echo "doctrine lint: docs/apis directory should not exist in mcxForge" >&2
    FAIL=$((FAIL+1))
  fi
}

check_adr_numbers_and_titles() {
  local adr_dir="$ROOT_DIR/docs/adr"
  [[ -d "$adr_dir" ]] || return 0

  local nums dup=0
  nums=$(ls -1 "$adr_dir" 2>/dev/null | grep -E '^[0-9]{4}-.*\.md$' | sed -E 's/^([0-9]{4})-.*/\1/' | sort | uniq -c | awk '$1>1{print $2}')
  if [[ -n "$nums" ]]; then
    echo "doctrine lint: duplicate ADR numbers found:" >&2
    echo "$nums" >&2
    dup=1
  fi

  local f num title_ok=1
  while IFS= read -r -d '' f; do
    num=$(basename "$f" | sed -E 's/^([0-9]{4})-.*/\1/')
    if ! grep -Eq "^# ADR-${num}:" "$f"; then
      echo "doctrine lint: ADR title does not match number or format: $f" >&2
      title_ok=0
    fi
  done < <(find "$adr_dir" -maxdepth 1 -type f -name "[0-9][0-9][0-9][0-9]-*.md" -print0)

  if [[ $dup -eq 1 || $title_ok -eq 0 ]]; then
    FAIL=$((FAIL+1))
  fi
}

check_no_apis_docs
check_adr_numbers_and_titles

if [[ $FAIL -ne 0 ]]; then
  echo "doctrine lint: $FAIL issue(s) found" >&2
  exit 1
fi
echo "doctrine lint: OK"

