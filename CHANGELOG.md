# Changelog

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
