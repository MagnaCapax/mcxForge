#!/usr/bin/env bash
# Author: Aleksi Ursin
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"

shell_files=()
while IFS= read -r -d '' f; do
  shell_files+=("$f")
done < <(find "$ROOT_DIR" -type f -name "*.sh" -print0 2>/dev/null || true)

if [[ ${#shell_files[@]} -eq 0 ]]; then
  echo "No shell scripts found for linting"
  exit 0
fi

echo "Shell lint: checking ${#shell_files[@]} file(s)"

for f in "${shell_files[@]}"; do
  bash -n "$f"
  if command -v shellcheck >/dev/null 2>&1; then
    shellcheck "$f"
  fi
done

echo "Shell lint OK"
