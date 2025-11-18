#!/usr/bin/env bash
# Author: Aleksi Ursin
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
DEV_DIR="$ROOT_DIR/development"
BIN="$DEV_DIR/vendor/bin/phpmd"
PHP_BIN="${PHP_BIN:-php}"

LOG_DIR="$DEV_DIR/var/test-logs"
mkdir -p "$LOG_DIR"
OUT_FILE="$LOG_DIR/complexity-phpmd.txt"

if [[ ! -x "$BIN" ]]; then
  if command -v composer >/dev/null 2>&1; then
    echo "[complexity-phpmd] phpmd not found; attempting composer install (dev, development/)" >&2
    set +e
    composer install --no-interaction --no-progress --prefer-dist --working-dir "$DEV_DIR"
    rc=$?
    set -e
    if [[ $rc -ne 0 || ! -x "$BIN" ]]; then
      echo "[complexity-phpmd] phpmd unavailable after composer install in development/; skipping report" >&2
      exit 0
    fi
  else
    echo "[complexity-phpmd] phpmd not found and composer is not installed; skipping report" >&2
    exit 0
  fi
fi

TARGETS="$ROOT_DIR/bin,$ROOT_DIR/lib"
RULES="cleancode,codesize,design,unusedcode"

echo "[complexity-phpmd] running phpmd on $TARGETS with rules: $RULES" >&2

set +e
"$PHP_BIN" "$BIN" "$TARGETS" text "$RULES" >"$OUT_FILE" 2>&1
rc=$?
set -e

if [[ $rc -ne 0 ]]; then
  echo "[complexity-phpmd] phpmd reported issues (exit $rc); see $OUT_FILE" >&2
else
  echo "[complexity-phpmd] phpmd completed with no reported issues; see $OUT_FILE" >&2
fi

exit 0

