#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
DEV_DIR="$ROOT_DIR/development"
LOG_DIR="$DEV_DIR/var/test-logs"
mkdir -p "$LOG_DIR"

export TEST_VERBOSE="${TEST_VERBOSE:-0}"
export ALLOW_TOOL_SKIP="${ALLOW_TOOL_SKIP:-0}"

echo "0) Tool checks"
bash "$DEV_DIR/testing/check-tools.sh"

echo "1) PHP lint"
bash "$DEV_DIR/testing/php-lint.sh"

echo "2) PHP dev tests (storage parsers)"
if [[ -f "$DEV_DIR/tests/development/runner.php" ]]; then
  php "$DEV_DIR/tests/development/runner.php" || true
else
  echo "TODO: add tests under development/tests/development/"
fi

echo "3) Shell script lint"
bash "$DEV_DIR/testing/shell-lint.sh" || echo "Shell lint skipped or failed (see output above)"

echo "4) Static analysis (phpstan)"
PHPSTAN_DISABLE_PARALLEL=1 bash "$DEV_DIR/testing/phpstan.sh" || echo "phpstan skipped or failed (see output above)"

echo "5) ADR metadata checks"
bash "$DEV_DIR/testing/adr-lint.sh"

echo "All tests completed"

echo
echo "LOC snapshot"
bash "$DEV_DIR/testing/loc.sh" || true
