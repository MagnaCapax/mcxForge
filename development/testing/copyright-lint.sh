#!/usr/bin/env bash
# Author: Aleksi Ursin
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"

status=0

LICENSE_FILE="$ROOT_DIR/LICENSE"

if [[ ! -f "$LICENSE_FILE" ]]; then
  echo "Copyright lint error: LICENSE file missing at repository root." >&2
  exit 1
fi

if ! grep -q 'Apache License' "$LICENSE_FILE"; then
  echo "Copyright lint error: LICENSE does not appear to be Apache-2.0 text." >&2
  status=1
fi

if ! grep -qi 'Magna Capax Finland Oy' "$LICENSE_FILE" && ! grep -qi 'Magna Capax' "$LICENSE_FILE"; then
  echo "Copyright lint warning: LICENSE does not mention Magna Capax Finland Oy." >&2
  # Do not hard-fail here; treat as soft warning to avoid blocking forks with different copyright.
fi

exit "$status"

