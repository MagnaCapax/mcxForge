#!/usr/bin/env bash
# Author: Aleksi Ursin
set -euo pipefail

# Naming lint for mcxForge PHP files.
# - Filenames under bin/, lib/php/, and development/tests/development/ must be:
#     ^[a-z][a-zA-Z0-9]*\.php$
#   (lowercase first letter, then letters/digits, .php)
# - Class names should follow PSR-ish conventions:
#     - bin/ entrypoints: mostly functions; we do not enforce class style there.
#     - lib/php/ classes: StudlyCaps (^[A-Z][A-Za-z0-9]*$).

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
VIOLATIONS=0

is_camel_file() {
  local base="$1"
  [[ "$base" =~ ^[a-z][a-zA-Z0-9]*\.php$ ]]
}

is_psr_class() {
  local name="$1"
  # Allow StudlyCaps and simple lowercase camelCase for flexibility.
  [[ "$name" =~ ^[A-Z][A-Za-z0-9]*$ ]] || [[ "$name" =~ ^[a-z][A-Za-z0-9]*$ ]]
}

check_tree_files_only() {
  local dir="$1"
  while IFS= read -r -d '' f; do
    local base
    base="$(basename "$f")"
    if ! is_camel_file "$base"; then
      echo "filename violation: $f" >&2
      VIOLATIONS=$((VIOLATIONS+1))
    fi
  done < <(find "$dir" -type f -name "*.php" -print0 2>/dev/null || true)
}

check_tree_with_classes() {
  local dir="$1"
  while IFS= read -r -d '' f; do
    local base
    base="$(basename "$f")"
    if ! is_camel_file "$base"; then
      echo "filename violation: $f" >&2
      VIOLATIONS=$((VIOLATIONS+1))
    fi
    # Extract declared classes/interfaces/traits in this file.
    while read -r kind name; do
      if ! is_psr_class "$name"; then
        echo "class naming violation: $f -> $name" >&2
        VIOLATIONS=$((VIOLATIONS+1))
      fi
    done < <(grep -Eo '^(final[[:space:]]+)?(class|interface|trait)[[:space:]]+[A-Za-z_][A-Za-z0-9_]*' "$f" 2>/dev/null | awk '{print "class", $NF}')
  done < <(find "$dir" -type f -name "*.php" -print0 2>/dev/null || true)
}

# bin entrypoints: filenames only.
check_tree_files_only "$ROOT_DIR/bin"

# lib/php classes: filenames and class names.
check_tree_with_classes "$ROOT_DIR/lib/php"

# development tests: filenames only.
check_tree_files_only "$ROOT_DIR/development/tests/development"

if [[ $VIOLATIONS -gt 0 ]]; then
  echo "camelCase / naming lint: $VIOLATIONS violation(s) found" >&2
  exit 1
fi
echo "camelCase / naming lint: OK"

