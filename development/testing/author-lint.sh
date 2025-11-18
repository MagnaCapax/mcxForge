#!/usr/bin/env bash
# Author: Aleksi Ursin
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"

status=0

echo "Author lint: checking bin PHP entrypoints for @author..."
for file in "$ROOT_DIR"/bin/*.php; do
  if [[ "$file" == "$ROOT_DIR/bin/*.php" ]]; then
    break
  fi
  if ! grep -q '@author' "$file"; then
    echo "Author lint error: missing @author in bin entrypoint $(basename "$file")" >&2
    status=1
  fi
done

echo "Author lint: checking development/testing shell helpers..."
for file in "$ROOT_DIR"/development/testing/*.sh; do
  if [[ "$file" == "$ROOT_DIR/development/testing/*.sh" ]]; then
    break
  fi
  if ! grep -q '^# Author:' "$file"; then
    echo "Author lint error: missing 'Author:' header in testing script $(basename "$file")" >&2
    status=1
  fi
done

echo "Author lint: checking development/cli helpers..."
for file in "$ROOT_DIR"/development/cli/*.sh; do
  if [[ "$file" == "$ROOT_DIR/development/cli/*.sh" ]]; then
    break
  fi
  if ! grep -q '^# Author:' "$file"; then
    echo "Author lint error: missing 'Author:' header in CLI helper $(basename "$file")" >&2
    status=1
  fi
done

if [[ "$status" -ne 0 ]]; then
  echo "Author lint failed: missing authorship markers in one or more files." >&2
fi

exit "$status"

