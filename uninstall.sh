#!/bin/sh
# ----------------------------------------------------------------------
# PyHP uninstaller for macOS / Linux - removes the install folder, the
# `pyhp` launcher and the PATH line that install.sh added.
# (Windows equivalent: uninstall.ps1 / uninstall.bat)
#
#   ./uninstall.sh [--dir PATH] [--bin-dir PATH]
# ----------------------------------------------------------------------

set -eu

DIR="${PYHP_HOME:-$HOME/.local/share/pyhp}"
BIN="${PYHP_BIN_DIR:-$HOME/.local/bin/pyhp}"

while [ $# -gt 0 ]; do
    case "$1" in
        --dir)     DIR="$2"; shift 2 ;;
        --bin-dir) BIN="$2"; shift 2 ;;
        -h|--help) sed -n '2,10p' "$0" 2>/dev/null || true; exit 0 ;;
        *) echo "uninstall.sh: unknown option '$1' (try --help)" >&2; exit 1 ;;
    esac
done

REMOVED=0

if [ -e "$DIR" ]; then
    rm -rf "$DIR"
    printf 'removed  %s\n' "$DIR"
    REMOVED=1
fi

if [ -e "$BIN" ]; then
    rm -f "$BIN"
    printf 'removed  %s\n' "$BIN"
    REMOVED=1
fi

for rc in "$HOME/.zshrc" "$HOME/.bashrc" "$HOME/.profile"; do
    if [ -f "$rc" ] && grep -q 'added by pyhp installer' "$rc" 2>/dev/null; then
        TMP="$(mktemp)"
        grep -v 'added by pyhp installer' "$rc" > "$TMP" || true
        mv "$TMP" "$rc"
        printf 'cleaned  %s (PATH line)\n' "$rc"
        REMOVED=1
    fi
done

if [ "$REMOVED" = 0 ]; then
    echo 'PyHP was not found on this machine (nothing to uninstall).'
fi
echo 'done.'
