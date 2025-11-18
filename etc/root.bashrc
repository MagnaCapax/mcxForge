# mcxForge root shell helpers
#
# This file is intended to be sourced from the real root ~/.bashrc
# on the live system. It assumes the mcxForge checkout lives under
# /opt/mcxForge by default, but honours MCXFORGE_ROOT when set.

if [ -z "${MCXFORGE_ROOT:-}" ]; then
  if [ -d "/opt/mcxForge" ]; then
    export MCXFORGE_ROOT="/opt/mcxForge"
  else
    export MCXFORGE_ROOT="$(pwd)"
  fi
fi

if [ -d "${MCXFORGE_ROOT}/bin" ]; then
  case ":${PATH}:" in
    *":${MCXFORGE_ROOT}/bin:"*) ;;
    *) export PATH="${MCXFORGE_ROOT}/bin:${PATH}" ;;
  esac
fi

# Convenience aliases for common tools
alias yabs='yabs'
alias geekbench="${MCXFORGE_ROOT}/bin/benchmarkCPUGeekbench.php"
alias stressng-cpu="${MCXFORGE_ROOT}/bin/benchmarkCPUStressNg.php"
alias sysbench-cpu="${MCXFORGE_ROOT}/bin/benchmarkCPUSysbench.php"
alias xmrig="${MCXFORGE_ROOT}/bin/benchmarkCPUXmrig.php"
alias neofetch="${MCXFORGE_ROOT}/bin/inventorySystemNeofetch.sh"

# Quick inventory summary helper; safe to call on login.
mcxforge_summary() {
  echo "=== mcxForge inventory summary ==="

  if [ -x "${MCXFORGE_ROOT}/bin/inventoryCPU.php" ]; then
    "${MCXFORGE_ROOT}/bin/inventoryCPU.php" --format=human || true
  fi

  if [ -x "${MCXFORGE_ROOT}/bin/inventoryStorage.php" ]; then
    echo
    "${MCXFORGE_ROOT}/bin/inventoryStorage.php" --format=human || true
  fi

  if [ -x "${MCXFORGE_ROOT}/bin/inventorySystemNeofetch.sh" ]; then
    echo
    "${MCXFORGE_ROOT}/bin/inventorySystemNeofetch.sh" || true
  fi
}

# Run summary automatically when this profile is loaded in an interactive shell.
case "$-" in
  *i*)
    mcxforge_summary
    ;;
esac

