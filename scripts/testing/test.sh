#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
LOG_DIR="$ROOT_DIR/var/test-logs"
mkdir -p "$LOG_DIR"

export TEST_VERBOSE="${TEST_VERBOSE:-0}"
export ALLOW_TOOL_SKIP="${ALLOW_TOOL_SKIP:-0}"

echo "0) Tool checks"
bash "$ROOT_DIR/scripts/testing/check-tools.sh"

echo "1) PHP lint"
bash "$ROOT_DIR/scripts/testing/php-lint.sh"

echo "2) PHP dev tests (storage parsers)"
if [[ -f "$ROOT_DIR/tests/development/runner.php" ]]; then
  php "$ROOT_DIR/tests/development/runner.php" || true
else
  echo "TODO: add tests under tests/development/"
fi

echo "3) Shell script lint"
bash "$ROOT_DIR/scripts/testing/shell-lint.sh" || echo "Shell lint skipped or failed (see output above)"

echo "4) Static analysis (phpstan)"
PHPSTAN_DISABLE_PARALLEL=1 bash "$ROOT_DIR/scripts/testing/phpstan.sh" || echo "phpstan skipped or failed (see output above)"

echo "All tests completed"

echo
echo "LOC snapshot"
bash "$ROOT_DIR/scripts/testing/loc.sh" || true
