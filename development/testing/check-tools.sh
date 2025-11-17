#!/usr/bin/env bash
set -euo pipefail

require_tool() {
  local name="$1"
  if ! command -v "$name" >/dev/null 2>&1; then
    echo "Missing required tool: $name" >&2
    exit 127
  fi
}

optional_tool() {
  local name="$1"
  if ! command -v "$name" >/dev/null 2>&1; then
    if [[ "${ALLOW_TOOL_SKIP:-0}" == "1" ]]; then
      echo "Optional tool missing (skipping): $name" >&2
      return 0
    fi
    echo "Optional tool missing: $name (set ALLOW_TOOL_SKIP=1 to skip)" >&2
    return 1
  fi
}

# Required for all tests
require_tool php

# Optional tools used by some flows
optional_tool curl || true
optional_tool socat || true
optional_tool composer || true
optional_tool shellcheck || true
optional_tool shfmt || true

exit 0

