#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
DEV_DIR="$ROOT_DIR/development"
BIN="$DEV_DIR/vendor/bin/phpstan"
CONF="$DEV_DIR/phpstan.neon.dist"
PHP_BIN="${PHP_BIN:-php}"
PHP_INI_ARGS=()
EXTRA_ARGS=()

if [[ "${PHPSTAN_DISABLE_PARALLEL:-0}" == "1" ]]; then
  PHP_INI_ARGS+=(-d disable_functions=proc_open)
fi

if [[ -n "${PHPSTAN_ARGS:-}" ]]; then
  # shellcheck disable=SC2206
  EXTRA_ARGS=(${PHPSTAN_ARGS})
fi

if [[ ! -x "$BIN" ]]; then
  if command -v composer >/dev/null 2>&1; then
    echo "phpstan not found; attempting composer install (dev, development/)" >&2
    set +e
    composer install --no-interaction --no-progress --prefer-dist --working-dir "$DEV_DIR"
    rc=$?
    set -e
    if [[ $rc -ne 0 || ! -x "$BIN" ]]; then
      echo "phpstan unavailable after composer install in development/" >&2
      if [[ "${ALLOW_TOOL_SKIP:-0}" == "1" ]]; then
        echo "Skipping phpstan due to ALLOW_TOOL_SKIP=1" >&2
        exit 0
      fi
      echo "Set ALLOW_TOOL_SKIP=1 to skip or install phpstan via composer." >&2
      exit 127
    fi
  else
    echo "phpstan not found and composer is not installed" >&2
    if [[ "${ALLOW_TOOL_SKIP:-0}" == "1" ]]; then
      echo "Skipping phpstan due to ALLOW_TOOL_SKIP=1" >&2
      exit 0
    fi
    exit 127
  fi
fi

exec "$PHP_BIN" "${PHP_INI_ARGS[@]}" "$BIN" analyse -c "$CONF" "$ROOT_DIR/bin" "${EXTRA_ARGS[@]}"
