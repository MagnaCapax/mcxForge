#!/usr/bin/env bash

set -u

if [ -z "${EXIT_OK:-}" ]; then
  EXIT_OK=0
fi
if [ -z "${EXIT_ERROR:-}" ]; then
  EXIT_ERROR=1
fi

print_help() {
  cat <<'TEXT'
Usage: inventorySystemNeofetch.sh [--help]

Ensure neofetch is available on this system and run it.

Behaviour:
  - If neofetch is already in PATH, it is used as-is.
  - Otherwise, this helper will attempt to download the neofetch script
    into $MCXFORGE_ROOT/tools/neofetch (default root: /opt/mcxForge).
  - If download fails or no downloader is available, a clear error is
    printed and the script exits with a non-zero status.

All arguments except --help are passed through to neofetch.
TEXT
}

detect_root() {
  if [ -n "${MCXFORGE_ROOT:-}" ]; then
    printf '%s\n' "$MCXFORGE_ROOT"
    return
  fi

  if [ -d "/opt/mcxForge" ]; then
    printf '%s\n' "/opt/mcxForge"
    return
  fi

  # Fallback to current directory for non-standard layouts.
  printf '%s\n' "$(pwd)"
}

ensure_neofetch() {
  if command -v neofetch >/dev/null 2>&1; then
    printf '%s\n' "$(command -v neofetch)"
    return
  fi

  local root tools_dir target url
  root="$(detect_root)"
  tools_dir="${root}/tools"
  target="${tools_dir}/neofetch"

  if [ -x "$target" ]; then
    printf '%s\n' "$target"
    return
  fi

  mkdir -p "$tools_dir" 2>/dev/null || true

  url="${MCXFORGE_NEOFETCH_URL:-https://raw.githubusercontent.com/dylanaraps/neofetch/master/neofetch}"

  if command -v curl >/dev/null 2>&1; then
    if ! curl -fsSL -o "$target" "$url"; then
      echo "inventorySystemNeofetch: curl download failed from $url" >&2
      return 1
    fi
  elif command -v wget >/dev/null 2>&1; then
    if ! wget -q -O "$target" "$url"; then
      echo "inventorySystemNeofetch: wget download failed from $url" >&2
      return 1
    fi
  else
    echo "inventorySystemNeofetch: neither curl nor wget is available to fetch neofetch" >&2
    return 1
  fi

  chmod +x "$target" 2>/dev/null || true
  if [ ! -x "$target" ]; then
    echo "inventorySystemNeofetch: downloaded neofetch but it is not executable ($target)" >&2
    return 1
  fi

  printf '%s\n' "$target"
}

main() {
  local args=()

  for arg in "$@"; do
    case "$arg" in
      --help|-h)
        print_help
        return "$EXIT_OK"
        ;;
      *)
        args+=("$arg")
        ;;
    esac
  done

  local neofetch_bin
  if ! neofetch_bin="$(ensure_neofetch)"; then
    echo "inventorySystemNeofetch: failed to ensure neofetch is available" >&2
    return "$EXIT_ERROR"
  fi

  "$neofetch_bin" "${args[@]}"
}

if [ "${BASH_SOURCE[0]}" = "$0" ]; then
  main "$@"
fi

