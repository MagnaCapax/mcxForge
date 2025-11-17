#!/usr/bin/env bash
set -euo pipefail

# Lint all PHP files (excluding vendor) with `php -l`.

if ! command -v php >/dev/null 2>&1; then
  echo "php not found in PATH" >&2
  exit 127
fi

mapfile -t FILES < <(find . -type f -name "*.php" -not -path "./vendor/*" | sort)

fail=0
for f in "${FILES[@]}"; do
  php -l "$f" >/dev/null || { echo "Syntax error: $f" >&2; fail=1; }
done

if [[ $fail -ne 0 ]]; then
  echo "PHP lint errors detected" >&2
  exit 1
fi
echo "PHP lint OK (${#FILES[@]} files)"

