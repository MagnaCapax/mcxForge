#!/usr/bin/env bash
# Author: Aleksi Ursin
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
ADR_DIR="$ROOT_DIR/docs/adr"

if [[ ! -d "$ADR_DIR" ]]; then
  echo "No docs/adr directory found, skipping ADR metadata checks."
  exit 0
fi

status=0
missing_count=0

for file in "$ADR_DIR"/*.md; do
  # If the glob does not match anything, Bash leaves it unchanged; skip in that case.
  if [[ "$file" == "$ADR_DIR/*.md" ]]; then
    break
  fi

  if ! grep -q 'Author:' "$file"; then
    status=1
    ((missing_count+=1))
    echo "ADR metadata error: missing 'Author:' line in $(basename "$file")" >&2
  fi
done

if [[ $status -ne 0 ]]; then
  echo "ADR metadata checks failed: Author line missing in ${missing_count} file(s)." >&2
fi

exit "$status"
