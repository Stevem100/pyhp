# Changelog

## 0.4.1 — `pyhp` everywhere: one-line Unix installer, no more `php bin/pyhp`

**macOS / Linux are first-class now**
- **`install.sh`** — the Windows one-liner comes to Unix, no admin rights:
  `curl -fsSL https://raw.githubusercontent.com/Stevem100/pyhp/main/install.sh | bash`.
  Installs PyHP to `~/.local/share/pyhp`, reuses any PHP 8 (lookup order:
  `PHP_BIN` → bundled `php/php` from a previous install → `PATH`; per-OS
  install hints when none is found), drops a `pyhp` launcher into
  `~/.local/bin`, adds that to your PATH (single marked line in `.zshrc` /
  `.bashrc` / `.profile`), and runs a smoke test. Downloads the latest GitHub
  release asset, falls back to the tag archive and then the `main` branch;
  extracts with `unzip` or `python3 -m zipfile` when `unzip` is missing.
  Flags: `--dir`, `--bin-dir`, `--local <folder>` (install from an unzipped
  release or a clone), `--main`, `--no-path`.
- **`uninstall.sh`** — removes the install folder, the launcher and the
  marked PATH line (mirrors `uninstall.ps1`).
- The install dir is kept on the release channel: `.git` is never copied, so
  `pyhp update` keeps using GitHub releases.
- `bin/pyhp` already carries a `#!/usr/bin/env php` shebang — from a checkout,
  `./bin/pyhp run ...` works out of the box.

**Every documented command is now plain `pyhp run` / `pyhp build`**
- README, REFERENCE, the three domain guides, all ten examples, the CLI help
  text and the AI kit (`llms.txt`, regenerated `pyhp-qa.jsonl`) dropped the
  `php bin/pyhp` prefix. `php bin/pyhp` remains valid from a checkout but is
  no longer the documented form. Running a transpiled output file (`php
  hello.php`) and dev tools (`php tests/run_tests.php`,
  `php tools/build_ai_data.php`) still use `php` directly — that is PHP, not
  PyHP.
- `ai/pyhp-spec.json` gains an `install` section; the `selfUpdate` block is
  now emitted by the generator instead of being hand-patched (regeneration
  stays stable).
- Release notes for new tags point macOS/Linux users at the one-line
  installer instead of the old `alias pyhp='php …'` workaround.

**Fixed**
- **Transpiler: corrupted `require_once` path when the output folder was an
  ancestor of the PyHP install** (e.g. PyHP installed under `/tmp/...` or
  `~/x/pyhp/` while building to `/tmp/app.php` / `~/x/app.php`). The
  `__DIR__`-relative emit dropped the `/` separator, producing
  `require_once __DIR__ . 'pyhp-home/...'` → `/tmppyhp-home/...` and a fatal
  "Failed opening required" on first run of the built file. Found by the new
  installer smoke test; all three emit modes (relative / install-root /
  absolute) are now verified.

**CI**
- New `install-sh` job: installs from the local checkout into a clean HOME,
  runs `pyhp version` / `pyhp run` / `pyhp build`, asserts no `.git` leaked
  into the install dir, then uninstalls and asserts everything is gone.
- The test matrix gains macOS (alongside Ubuntu and Windows, PHP 8.2/8.3).

## 0.4.0 — the CLI manages itself: `pyhp update` & `pyhp version`

**New CLI commands**
- **`pyhp update`** — self-update in place. Fetches the latest GitHub release
  (via `api.github.com`), downloads the `pyhp-<ver>.zip` asset and copies it
  over the install folder while **preserving your bundled `php/` runtime and
  `vendor/` packages**. Falls back to the `main`-branch zip when the API is
  unreachable, and switches to `git pull --ff-only` automatically when you run
  from a git clone. Flags: `--force` (apply even if not newer), `--main`
  (track the main branch instead of releases).
- **`pyhp version`** — now prints the version, PHP version, install path and
  install source; **`pyhp version --check`** also queries GitHub for the
  latest release and tells you whether an update is available.

**CI & release automation**
- `.github/workflows/ci.yml` — the 35-case suite runs after **every commit**
  (push) and every PR, on Ubuntu + Windows with PHP 8.2 and 8.3; the two
  native-backend tests compile with g++ when present and skip otherwise.
- `.github/workflows/release.yml` — tagging any `v*` tag runs the suite, then
  builds `pyhp-<version>.zip` from the tagged commit (`git archive`, same
  layout as the manual zips) and publishes a GitHub release with the asset,
  release notes and install instructions. Releases now exist for every tag.

**Docs & AI kit**
- README: self-update/`--check` quick-start lines, CI badge, 0.4.0 notes.
- `ai/pyhp-spec.json`: `cli` updated with `pyhp version [--check]`,
  `pyhp update [--force] [--main]` and the release channels.

## 0.3.0 — C++ muscles become real, stdlib for AI / security / IoT

**Language — C++ is now part of PyHP**
- **`cpp { ... }` native blocks**: embed C++17 directly in a `.pyh` file.
  Functions declared with a `// pyhp-export:` directive (C ABI: `int64_t`,
  `double`, `const char*`, `void`) become callable from PyHP. The block is
  compiled lazily with g++/clang++ (cached in `./.pyhp_native/`), and the
  bridge picks its mode automatically: PHP ext/ffi when available, otherwise a
  portable helper-binary protocol that needs only a C++ compiler.
- **Native C++ backend**: `pyhp build app.pyh --target cpp -o app.cpp` emits a
  single self-contained C++17 file implementing the PyHP value model;
  `--target native -o app` also compiles it with g++. Embedded `cpp` blocks are
  pasted straight into the binary — PyHP + C++ in, machine code out.
  Supported: the full value model, operators, strings/lists/dicts with all
  methods, slicing, f-strings, comprehensions, top-level functions
  (recursion/defaults/variadics), foreach/while/do/for, try/catch/throw,
  `require()` of modules and stdlib, `cpp` blocks, SHA-256, JSON and
  base64/url builtins. Classes/lambdas/`finally` are not there yet (clear
  compile errors point at the PHP engines).
- `pyhp build --target php|cpp|native` flag; `pyhp_build` unchanged by default.

**Stdlib (`lib/`, loads with `require("ai")` etc. — no install step)**
- `ai` — machine learning: `linear_fit`/`linear_predict` (gradient descent),
  `kmeans`/`nearest_centroid`, `dot/euclid/manhattan/cosine`, `matmul`/
  `transpose`, `mean/variance/stdev/median`, `minmax`/`standardize`,
  `train_test_split`, `rng_next`/`rand_list` (seeded 32-bit LCGs that fit
  exactly in 64-bit math on every engine), `sigmoid`/`relu`,
  `accuracy/mse/mae/r2`.
- `sec` — security research (CTF/authorized labs): `caesar/rot13/vigenere/
  xor_cipher`, `crack_caesar` (chi-square English scoring), `entropy`,
  `char_freq`, `hexdump`, `log2` (pure-PyHP, engine-exact), `ip_to_int/
  int_to_ip/subnet/host_range`, `password_gen`, `hash_file`.
- `iot` — sensors and telemetry: `sensor_sim`, `moving_avg/ema/deadband/
  rolling_stats/threshold_flags/clamp/median`, `crc16` (CRC-16/Modbus,
  matches the 0x4B37 check vector), `telemetry/parse_telemetry/
  telemetry_batch` (JSON/NDJSON), `mqtt_frame/mqtt_parse/mqtt_publish/
  mqtt_consume` (frames with CRC trailers).

**Built-ins (53 → 62)**
- `json_parse` / `json_string` (deterministic output; lists/dicts round-trip).
- `md5`, `sha256` (also implemented natively in the C++ backend), `crc32`.
- `base64e` / `base64d` (strict), `urlenc` / `urldec` (RFC 3986).

**Engine parity fixes found by the new cross-engine tests**
- `pyhp_mod` now uses exact 64-bit integer math for int/int (the old float
  fallback lost precision on large operands, e.g. `(r * 1103515245 + 12345) %
  2147483648`).
- `for`-update parts may now be assignments (`for (i = 0; i < n; i += 2)`),
  matching what both engines already supported semantically.
- Native backend matches PHP float formatting (PHP 14-digit precision,
  minimal exponents like `E-9`), int-division results, and dict/list repr.

**Docs & AI kit**
- `docs/REFERENCE.md` — 0.3.0: native blocks, C++ backend, stdlib reference.
- New guides: `docs/guides/AI-DEVELOPMENT.md`,
  `docs/guides/SECURITY-RESEARCH.md`, `docs/guides/IOT.md`.
- `ai/pyhp-spec.json`, `ai/llms.txt`, `ai/pyhp-qa.jsonl`, `ai/pyhp-code.jsonl`
  regenerated from the live engine with the new features (all snippets
  executed and verified).
- Tests: 29 → 35 cases (json/crypto, ai, sec, iot parity + two native-backend
  tests that skip gracefully when g++ is missing).

## 0.2.0 — modules, packages & AI kit

**Language**
- **Modules**: `require("./lib.pyh")` and `require("pkgname")` run a file once in
  an isolated scope and return an export table (dict) of its top-level
  functions, lambdas and classes (classes as factories). Cached per path;
  circular requires are detected. Works on **both engines** — compiled builds
  inline modules at build time (literal specs only).
- Dict-stored callables are now dot-callable (`ops.twice("x")`), and member
  reads on dicts fall through to keys (`m.fn` as a value).
- Fixed: compound assignments on strings/lists in compiled mode (`+=` now uses
  the shared value helpers, matching the interpreter).

**Package manager** — `pyhp pkg init | add | remove | list` with a `pyhp.json`
manifest, a `vendor/` directory, version pins (`pkg add mathx@1.0.0`) and the
`PYHP_REGISTRY` search path. Bundled first-party registry packages:
- `strings` 1.0.0 — case conversion, padding, palindromes, word counts
- `mathx` 1.0.0 — gcd/lcm, primes, fib, mean/median/variance, base conversion, factorization
- `texttable` 1.0.0 — aligned ASCII tables from lists of dicts

**Docs & AI**
- `docs/REFERENCE.md` — complete reference: CLI, syntax, operators, all 53
  builtins with arities, all methods, modules, packages, engine-parity notes.
- `ai/` — LLM kit: `llms.txt`, machine-readable `pyhp-spec.json`,
  `pyhp-qa.jsonl` (58 verified Q&A pairs) and `pyhp-code.jsonl` (26 code tasks,
  every output captured by executing the code on the real interpreter).
  Regenerate with `php tools/build_ai_data.php`.

**Tooling / installers**
- `install.ps1` one-line Windows installer (+ `install.bat`, uninstaller) —
  bundles PHP 8.3 if no PHP is present, adds `pyhp` to PATH.
- `pyhp.bat` auto-detects bundled PHP.

**Tests** — 26 → 29 cases; module tests run on both engines (relative requires,
vendor packages, nested requires inside functions, cache identity).

## 0.1.0 — initial release

- Tree-walking interpreter (lexer → recursive-descent parser → AST evaluator)
- PyHP → PHP 8 transpiler producing standalone output
- CLI (`run`/`build`/`compile`/`repl`) + REPL with bare-expression echo
- Python DNA: aliasing lists/dicts, stepped slicing, comprehensions, closures,
  classes with `super`, `__str__`, rich method sets
- C DNA: hex/binary literals, bitwise ops, runtime-enforced static types,
  deterministic `malloc_arena` with use-after-free/double-free detection,
  file/env/argv/system access
- 26-case dual-engine test suite, 5 runnable examples, Windows launcher
