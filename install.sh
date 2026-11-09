#!/bin/sh
# ----------------------------------------------------------------------
# PyHP installer for macOS / Linux - PHP syntax, Python brains, C muscles.
#
# One-command setup, no admin rights required:
#   - installs PyHP to a per-user folder (default: ~/.local/share/pyhp)
#   - reuses any PHP 8 already on the machine (PHP_BIN -> bundled -> PATH)
#     and tells you exactly how to get PHP if none is found
#   - drops a `pyhp` launcher into ~/.local/bin (added to PATH if needed)
#   - runs a smoke test to prove everything works
#
# From a terminal (recommended):
#   curl -fsSL https://raw.githubusercontent.com/Stevem100/pyhp/main/install.sh | bash
#
# Options (download the script first, then pass them):
#   ./install.sh --dir PATH      where to install    (default ~/.local/share/pyhp)
#   ./install.sh --bin-dir PATH  launcher location   (default ~/.local/bin)
#   ./install.sh --local PATH    install from a local folder (unzipped release / git clone)
#   ./install.sh --main          install the main branch instead of the latest release
#   ./install.sh --no-path       do not touch any shell rc files
#
# Uninstall: run uninstall.sh from the same place, or delete
# ~/.local/share/pyhp and ~/.local/bin/pyhp by hand.
# ----------------------------------------------------------------------

set -eu

REPO='Stevem100/pyhp'
ZIP_MAIN="https://codeload.github.com/${REPO}/zip/refs/heads/main"
API_LATEST="https://api.github.com/repos/${REPO}/releases/latest"
DIR="$HOME/.local/share/pyhp"
BIN_DIR="$HOME/.local/bin"
SRC=''; WANT_MAIN=0; NO_PATH=0
PHP_EXE=''

while [ $# -gt 0 ]; do
    case "$1" in
        --dir)     DIR="$2"; shift 2 ;;
        --bin-dir) BIN_DIR="$2"; shift 2 ;;
        --local)   SRC="$2"; shift 2 ;;
        --main)    WANT_MAIN=1; shift ;;
        --no-path) NO_PATH=1; shift ;;
        -h|--help)
            sed -n '2,28p' "$0" 2>/dev/null || true
            exit 0 ;;
        *) echo "install.sh: unknown option '$1' (try --help)" >&2; exit 1 ;;
    esac
done

say()  { printf '    %s\n' "$1"; }
step() { printf '==> %s\n' "$1"; }
die() {
    printf '\nINSTALL FAILED: %s\n' "$1" >&2
    printf 'If this keeps happening, please open an issue:\n  https://github.com/%s/issues\n' "$REPO" >&2
    exit 1
}

fetch() { # fetch URL DEST
    if command -v curl >/dev/null 2>&1; then
        curl -fsSL --max-time 60 "$1" -o "$2"
    elif command -v wget >/dev/null 2>&1; then
        wget -q -T 60 -O "$2" "$1"
    else
        return 1
    fi
}

# ---------------------------------------------------------------- 1. PHP
step "looking for PHP 8 (PHP_BIN -> bundled -> PATH)"
if [ -n "${PHP_BIN:-}" ]; then
    if [ -d "$PHP_BIN" ] && [ -x "$PHP_BIN/php" ]; then
        PHP_EXE="$PHP_BIN/php"
    elif [ -x "$PHP_BIN" ]; then
        PHP_EXE="$PHP_BIN"
    else
        say "PHP_BIN is set but not a usable php binary: $PHP_BIN (ignoring)"
    fi
fi
if [ -z "$PHP_EXE" ] && [ -x "$DIR/php/php" ]; then
    PHP_EXE="$DIR/php/php"; say "using bundled PHP from a previous install"
fi
if [ -z "$PHP_EXE" ]; then
    if command -v php >/dev/null 2>&1; then
        PHP_EXE="$(command -v php)"
    fi
fi
if [ -z "$PHP_EXE" ]; then
    printf '\nPyHP needs PHP 8.0+ (CLI). It was not found on this machine.\n\n' >&2
    printf '  macOS:           brew install php\n' >&2
    printf '  Debian/Ubuntu:   sudo apt install php-cli\n' >&2
    printf '  Fedora:          sudo dnf install php-cli\n' >&2
    printf '  Arch:            sudo pacman -S php\n' >&2
    printf '  Already have it? export PHP_BIN=/path/to/php  and re-run this installer\n' >&2
    exit 1
fi
say "php: $($PHP_EXE -r 'echo PHP_VERSION;' 2>/dev/null || echo '?') at $PHP_EXE"

# ------------------------------------------------------------ 2. sources
TMP=''
cleanup() { [ -n "$TMP" ] && [ -d "$TMP" ] && rm -rf "$TMP"; return 0; }
trap cleanup EXIT

INNER=''
if [ -n "$SRC" ]; then
    step "installing from local folder: $SRC"
    INNER="$SRC"
else
    TMP="$(mktemp -d)"
    ZIP_URL=''
    if [ "$WANT_MAIN" = 1 ]; then
        ZIP_URL="$ZIP_MAIN"
    else
        step "finding the latest release"
        if fetch "$API_LATEST" "$TMP/rel.json" 2>/dev/null; then
            TAG="$(sed -n 's/.*"tag_name"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$TMP/rel.json" | head -n 1)"
        else
            TAG=''
        fi
        if [ -n "$TAG" ]; then
            ZIP_URL="https://github.com/${REPO}/releases/download/${TAG}/pyhp-${TAG#v}.zip"
        else
            say "releases API unreachable - falling back to the main branch"
            ZIP_URL="$ZIP_MAIN"
        fi
    fi
    say "downloading $ZIP_URL"
    fetch "$ZIP_URL" "$TMP/pyhp.zip" || {
        # release asset missing (older tags): fall back to the tag archive, then main
        if [ -n "${TAG:-}" ]; then
            say "asset not found - trying the tag archive"
            fetch "https://codeload.github.com/${REPO}/zip/refs/tags/${TAG}" "$TMP/pyhp.zip" \
                || fetch "$ZIP_MAIN" "$TMP/pyhp.zip" || die "download failed (network?)"
        else
            die "download failed (network?)"
        fi
    }
    step "extracting"
    mkdir -p "$TMP/x"
    if command -v unzip >/dev/null 2>&1; then
        unzip -q "$TMP/pyhp.zip" -d "$TMP/x"
    elif command -v python3 >/dev/null 2>&1; then
        python3 -m zipfile -e "$TMP/pyhp.zip" "$TMP/x"
    else
        die "need unzip (or python3) to extract the download"
    fi
    for d in "$TMP"/x/*/; do
        if [ -f "${d}bin/pyhp" ] && [ -f "${d}src/runtime.php" ]; then
            INNER="${d%/}"; break
        fi
    done
    [ -n "$INNER" ] || die "unexpected zip layout (no bin/pyhp + src/runtime.php inside)"
fi
[ -f "$INNER/bin/pyhp" ] && [ -f "$INNER/src/runtime.php" ] \
    || die "source folder has no bin/pyhp + src/runtime.php: $INNER"

# ------------------------------------------------------------- 3. install
step "installing to $DIR"
mkdir -p "$DIR"
# merge-copy over an existing install; php/ (bundled runtime) and vendor/
# packages already there are untouched - same behaviour as `pyhp update`
cp -R "$INNER/." "$DIR/"
rm -rf "$DIR/.git"          # an install dir must stay on the release channel
chmod +x "$DIR/bin/pyhp"

# ------------------------------------------------------------ 4. launcher
step "creating the pyhp launcher in $BIN_DIR"
mkdir -p "$BIN_DIR"
cat > "$BIN_DIR/pyhp" <<LAUNCHER
#!/bin/sh
# PyHP launcher (macOS/Linux) - generated by install.sh
PYHP_HOME="$DIR"
if [ -n "\${PHP_BIN:-}" ]; then
    if [ -d "\$PHP_BIN" ] && [ -x "\$PHP_BIN/php" ]; then PHP_EXE="\$PHP_BIN/php"
    elif [ -x "\$PHP_BIN" ]; then PHP_EXE="\$PHP_BIN"
    else
        echo "[pyhp] PHP_BIN is set but not a usable php: \$PHP_BIN" >&2
        exit 1
    fi
elif [ -x "\$PYHP_HOME/php/php" ]; then
    PHP_EXE="\$PYHP_HOME/php/php"
elif command -v php >/dev/null 2>&1; then
    PHP_EXE=php
else
    echo "[pyhp] PHP 8.0+ was not found. Install it (brew install php / apt install php-cli)," >&2
    echo "[pyhp] or point PyHP at an existing one: export PHP_BIN=/path/to/php" >&2
    exit 1
fi
exec "\$PHP_EXE" "\$PYHP_HOME/bin/pyhp" "\$@"
LAUNCHER
chmod +x "$BIN_DIR/pyhp"

# ---------------------------------------------------------------- 5. PATH
CHANGED_RC=''
in_path() {
    case ":${PATH}:" in *":$1:"*) return 0 ;; *) return 1 ;; esac
}
if [ "$NO_PATH" = 0 ] && ! in_path "$BIN_DIR"; then
    RC=''
    case "${SHELL:-}" in
        *zsh) RC="$HOME/.zshrc" ;;
        *) if [ -f "$HOME/.bashrc" ] || [ "${SHELL:-}" = */bash ]; then RC="$HOME/.bashrc"; else RC="$HOME/.profile"; fi ;;
    esac
    if [ -n "$RC" ]; then
        [ -f "$RC" ] || : > "$RC"
        if ! grep -q 'added by pyhp installer' "$RC" 2>/dev/null; then
            printf 'export PATH="%s:$PATH" # added by pyhp installer\n' "$BIN_DIR" >> "$RC"
            CHANGED_RC="$RC"
        fi
    fi
fi
export PATH="$BIN_DIR:$PATH"

# ----------------------------------------------------------- 6. smoke test
step "smoke test"
"$BIN_DIR/pyhp" version
"$BIN_DIR/pyhp" run "$DIR/examples/01_hello.pyh" >/dev/null \
    || die "pyhp run smoke test failed"
say "pyhp run ... OK"

VER="$(sed -n "s/.*PYHP_VERSION = '\([^']*\)'.*/\1/p" "$DIR/src/runtime.php" | head -n 1)"
printf '\n'
printf '  PyHP %s installed. PHP syntax, Python brains, C muscles.\n' "${VER:-?}"
printf '    install dir : %s\n' "$DIR"
printf '    launcher    : %s/pyhp\n' "$BIN_DIR"
printf '    php         : %s\n' "$PHP_EXE"
if [ -n "$CHANGED_RC" ]; then
    printf '\n  Added PATH export to %s.\n  Open a new terminal, or run now:  source %s\n' "$CHANGED_RC" "$CHANGED_RC"
elif [ "$NO_PATH" = 0 ]; then
    printf '\n  Try it:  pyhp repl\n'
else
    printf '\n  Try it:  %s/pyhp repl   (--no-path: launcher dir is not on PATH)\n' "$BIN_DIR"
fi
