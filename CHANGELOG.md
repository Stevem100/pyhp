# Changelog

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
