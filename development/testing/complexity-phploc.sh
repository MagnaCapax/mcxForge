#!/usr/bin/env bash
# Author: Aleksi Ursin
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
DEV_DIR="$ROOT_DIR/development"
BIN="$DEV_DIR/vendor/bin/phploc"
PHP_BIN="${PHP_BIN:-php}"

LOG_DIR="$DEV_DIR/var/test-logs"
mkdir -p "$LOG_DIR"
OUT_FILE="$LOG_DIR/complexity-phploc.txt"

if [[ ! -x "$BIN" ]]; then
  if command -v composer >/dev/null 2>&1; then
    echo "[complexity-phploc] phploc not found; attempting composer install (dev, development/)" >&2
    set +e
    composer install --no-interaction --no-progress --prefer-dist --working-dir "$DEV_DIR"
    rc=$?
    set -e
    if [[ $rc -ne 0 || ! -x "$BIN" ]]; then
      echo "[complexity-phploc] phploc unavailable after composer install in development/; skipping snapshot" >&2
      exit 0
    fi
  else
    echo "[complexity-phploc] phploc not found and composer is not installed; skipping snapshot" >&2
    exit 0
  fi
fi

echo "[complexity-phploc] capturing complexity snapshot to $OUT_FILE" >&2

set +e
"$PHP_BIN" "$BIN" --exclude "$DEV_DIR" --exclude "$ROOT_DIR/vendor" --exclude "$ROOT_DIR/development/vendor" "$ROOT_DIR" >"$OUT_FILE" 2>&1
rc=$?
set -e

if [[ $rc -ne 0 ]]; then
  echo "[complexity-phploc] phploc exited with code $rc; see $OUT_FILE" >&2
else
  echo "[complexity-phploc] snapshot written to $OUT_FILE" >&2
fi

exit 0

