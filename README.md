# PyHP 0.7.0

[![CI](https://github.com/Stevem100/pyhp/actions/workflows/ci.yml/badge.svg)](https://github.com/Stevem100/pyhp/actions/workflows/ci.yml)

**PHP syntax. Python brains. C muscles.**

PyHP is a small dynamically-typed scripting language that fuses three bloodlines:

| DNA | What it brings |
| --- | --- |
| **PHP** | familiar syntax — braces, semicolons, `echo`, `foreach`, `$`-free variables |
| **Python** | lists & dicts that alias like objects, slicing with steps, comprehensions, lambdas & closures, rich builtins, string/list/dict methods, classes with `super`, generators & lazy views, big integers, Python-style truthiness |
| **C** | hex/binary literals, bitwise operators, optional static type annotations, a deterministic `malloc`/`free` byte-buffer arena, file I/O and shell escape hatches |
| **C++** | first-class: embed real C++ with `cpp { ... }` blocks, and compile whole programs to a native executable |

PyHP ships with **three execution paths** built from the same front-end:

1. **Our own bytecode VM** — hand-written lexer → recursive-descent parser → bytecode → stack-machine dispatch loop (`pyhp run`; `--tree` keeps the classic tree-walking evaluator, and `pyhp repl` uses it too).
2. **A transpiler** — PyHP → clean, standalone PHP 8 source (`pyhp build` / `pyhp compile`) that runs natively and requires only `src/runtime.php`.
3. **A native C++ backend** — PyHP → single-file C++17 → machine code (`pyhp build --target cpp|native`). Your embedded `cpp { ... }` blocks go straight into the binary: no PHP, no interpreter, no FFI.

All paths share the exact same data model, so the 49-case test suite passes every program through the engines (tree, VM, transpiler — plus native and the C++ exec helper where g++ exists) and asserts byte-identical output.

New in 0.7.0: **generators** (`yield` — lazy sequences you can `break` out of,
with a new `next(it, default)`), **lazy views** (`range`/`map`/`filter`/`zip`/
`enumerate`/`reversed` stream like Python; `range(2 ** 70)` costs nothing),
**big integers** (Python-style promotion: `9223372036854775807 + 1` and
`2 ** 100` are exact, huge literals parse exactly), a **bytecode VM** for the
interpreter path, **native classes** (full OOP in the C++ backend: ctor kwargs,
`super`, `__str__`, `isinstance`, the iterator protocol), **dicts across the
cpp bridge** (`pyhp_dict` joins `pyhp_list`), and **`pyhp langs`** — an
offline one-command bridge to PHP/Python/C++/JavaScript syntax and functions,
so you never need those runtimes installed just to look something up (same
tables as [docs/BRIDGE.md](docs/BRIDGE.md)).

Also in 0.6.0: **f-string format specs** — `f"pi={x:.2f}"`, `f"[{n:>10}]"`,
`f"{n:,}"`, `f"{p:.1%}"` and the full Python mini-language, byte-identical on
all three engines. **Modules now carry state and classes across files**:
module-level vars work in compiled mode, `class X extends S` where
`S = require("./m.pyh").Cls` just works, and `isinstance` sees through
namespaces. **`pyhp pkg` goes remote**: `pkg add` accepts registry names,
remote `index.json` registries or direct zip URLs, and every install is
pinned in **`pyhp.lock.json`** (exact source + sha256) with `pkg install` /
`pkg update` for reproducible vendor trees. **`pyhp_list` crosses the C++
bridge** (cpp blocks take and return lists), **lambdas run in the native
backend** (with `sorted(key = ...)`), and classes can implement
**`__iter__` / `__next__`** so `foreach`, `sorted` and `map` consume custom
iterators. New in 0.5.0: **keyword arguments** — call functions, methods and constructors
with `name = value` arguments (Python style): `greet(name = "Ada")`,
`sorted(xs, key = f, reverse = true)`, `super(x, sound = "Woof")` — in the
interpreter and the transpiler alike. Windows now installs to **`C:\pyhp` by
default** and `pyhp update` keeps that folder current in place. New in
0.4.1: **`pyhp` everywhere** — a one-line **macOS / Linux installer**
(`install.sh`) that puts a `pyhp` launcher on your PATH, so every documented
command is now plain `pyhp run` / `pyhp build` on all three OSes. New in
0.4.0: **`pyhp update` / `pyhp version`** — the CLI manages itself now (self-update
from GitHub releases, `--check` for the latest version), and the repo ships **CI** that
runs the full test suite after every commit plus an automated **release pipeline** that
packages a zip for every `v*` tag. New in 0.3.0: **C++ native blocks + a native C++
backend**, a **stdlib** with `ai` (machine learning), `sec` (security research) and
`iot` (telemetry & framing) modules, JSON/crypto built-ins, and domain guides in
[`docs/guides/`](docs/guides/). See [CHANGELOG.md](CHANGELOG.md).

---

## Quick start

Requirements: PHP 8.0+ (CLI). No extensions needed — the optional `gmp` extension just speeds up big-int arithmetic (there is a pure-PHP fallback).

> **Windows?** One line in PowerShell — no admin rights:
>
> ```powershell
> irm https://raw.githubusercontent.com/Stevem100/pyhp/main/install.ps1 | iex
> ```
>
> The installer sets up PyHP *and* PHP 8.3 (only if you don't already have PHP)
> into **`C:\pyhp`**, adds `pyhp` to your PATH, and runs a smoke test —
> `pyhp update` then keeps that folder current forever. Full details, parameters and
> a manual route: [INSTALL-WINDOWS.md](INSTALL-WINDOWS.md).

> **macOS / Linux?** One line in a terminal — no admin rights:
>
> ```bash
> curl -fsSL https://raw.githubusercontent.com/Stevem100/pyhp/main/install.sh | bash
> ```
>
> The installer reuses any PHP 8 you have (and tells you how to get it if you
> don't), installs PyHP to `~/.local/share/pyhp`, drops a `pyhp` launcher into
> `~/.local/bin`, and runs a smoke test. From an unzipped release or a clone:
> `./install.sh --local .` — uninstall any time with `./uninstall.sh`.

> **Already installed an older PyHP (0.1.x–0.3.x)?** `pyhp update` only exists
> from **0.4.0** on — on older installs it prints `unknown command 'update'`
> (and a banner like `PyHP 0.1.0`). Jump straight to the latest version by
> re-running the one-line installer above; it upgrades the install folder in
> place and keeps your code. Then check `pyhp version`.
>
> If `pyhp version` still shows an old version, an old install folder is still
> winning the PATH race: run `uninstall.ps1` (Windows) from that folder, or
> delete it and reopen the terminal. From 0.4.0 on you'll never have to do
> this again — `pyhp update` keeps every future install current in place.

```bash
# run with the built-in interpreter
pyhp run examples/01_hello.pyh

# run with the transpiled fast path
pyhp compile examples/01_hello.pyh

# transpile to a standalone PHP file you can deploy anywhere
pyhp build examples/01_hello.pyh -o hello.php
php hello.php extra cli args   # -> available as argv()

# interactive shell
pyhp repl

# offline syntax/function bridge to PHP, Python, C++ and JavaScript
# (no other runtime needed — answers "how do I write X in Python again?")
pyhp langs                # or: pyhp langs python / php / cpp / js / full

# run the test suite (49 cases across all engines)
php tests/run_tests.php

# packages: install from the bundled registry, then require()
pyhp pkg add mathx
pyhp run examples/pkg-demo/main.pyh

# C++ inside PyHP, then everything compiled to a native binary
pyhp run examples/07_native_cpp.pyh
pyhp build examples/07_native_cpp.pyh --target native -o /tmp/pyhp_demo
/tmp/pyhp_demo

# domain toolkits ship with the language — no install step
pyhp run examples/08_ai_ml.pyh        # machine learning
pyhp run examples/09_security.pyh     # security research toolkit
pyhp run examples/10_iot.pyh          # IoT telemetry & framing

# the CLI manages itself
pyhp version --check                  # local version + latest on GitHub
pyhp update                           # self-update in place (keeps php/ and vendor/)
```

(Working from a repo checkout instead of an install? `./bin/pyhp` carries a
`#!/usr/bin/env php` shebang, so `./bin/pyhp run ...` works too.)

### REPL in 60 seconds

```
$ pyhp repl
pyhp> x = 5
pyhp> x * 2
=> 10
pyhp> [n ** 2 for n in range(5)]
=> [0, 1, 4, 9, 16]
pyhp> f"n={x}, double={x * 2}"
=> 'n=5, double=10'
pyhp> function fact(n) { return n <= 1 ? 1 : n * fact(n - 1); }
pyhp> fact(6)
=> 720
pyhp> :vars
pyhp> :quit
```

---

## The language in one file

```c
// ---- variables: no $, semicolons, // # and block comments ----
name = "world";          // dynamic
int count = 0;           // optional static typing (enforced at runtime)
float ratio = 2.5;       // int values widen to float
list tags = ["php", "python"];
dict ages = {"ada": 36, 7: "seven"};

// ---- strings: quotes, escapes, f-strings ----
s = "tab\tnl\n" + 'single \'quoted\'';
t = f"hi {name}, 1+1={1 + 1}, {{escaped}}";

// ---- control flow ----
if count > 3 { echo "big\n"; } elseif count == 0 { echo "empty\n"; } else { echo "small\n"; }
while count < 3 { count++; }
do { count--; } while count > 0;
for (int i = 0; i < 10; i++) { if i % 2 == 0 { continue; } if i == 7 { break; } }
foreach item in tags { echo item, " "; }
foreach key, value in ages { echo key, "=", value, "\n"; }   // index+value for lists too

// ---- functions: defaults, variadics, types, lambdas, closures ----
function clamp(int v, int lo, int hi) -> int {
    return v < lo ? lo : (v > hi ? hi : v);
}
function total(...nums) { return sum(nums); }        // variadic
function greet(name, greeting = "Hello") { return f"{greeting}, {name}!"; }
square = lambda x: x * x;
function make_counter() {
    n = 0;
    return function() { n += 1; return n; };         // closures capture by name
}

// ---- generators: lazy, resumable sequences (0.7.0) ----
function fib() {
    a = 0; b = 1;
    while true { yield a; t = a + b; a = b; b = t; }
}
g = fib();
print(next(g)); print(next(g)); print(next(g));   // 0 1 1 — nothing ran before the first next()
foreach v in fib() { if v > 20 { break; } print(v); }   // 0 1 1 2 3 5 8 13 — break any time

// ---- collections: Python semantics ----
xs = [3, 1, 2];
xs.append(4); xs.sort();
xs[0]; xs[-1]; xs[1:3]; xs[::-1]; xs[::2];           // negative + stepped slicing
ys = [x * 2 for x in xs if x > 1];                   // list comprehension
zs = {k: len(k) for k, v in {"a": 1, "b": 2}};       // dict comprehension
xs + [9]; xs * 2; 3 in xs; "ell" in "hello"; not in; // concat, repeat, membership
"abc".upper(); "a,b".split(","); "-".join(["x"]);    // string methods
xs.sort(); xs.index(2); ages.keys(); ages.get("k", "dflt");

// ---- classes: this, super, __str__, custom exceptions ----
class Animal {
    string name;
    function __construct(string name) { this.name = name; }
    function speak() { return this.name + " makes a sound"; }
}
class Dog extends Animal {
    function speak() { return super.speak() + " (woof)"; }
}
d = Dog("Rex");
print(d.speak());                  // Rex makes a sound (woof)
print(d);                          // custom via __str__ if defined

// ---- modules & packages ----
lib  = require("./util.pyh");       // relative file module
math = require("mathx");            // package from vendor/ or the registry
echo math.gcd(12, 18);              // top-level fns/lambdas/classes are the API
shape = math.Circle(5);             // classes come through as factories

// ---- errors ----
try {
    if count == 0 { throw ValueError("count is zero"); }
    xs[99];
} catch (ValueError e) { print(e.message); }
catch (IndexError e) { print(e.message); }
finally { print("done"); }

// ---- the C DNA ----
flags = 0xF0 & 0x0F;               // hex, binary literals, & | ^ ~ << >>
bits = 0b1010 | 0b0001; bits <<= 2;
arena = malloc_arena();            // deterministic memory control
buf = arena.alloc(16);             // byte buffer with bounds checks
buf.write("PyHP!", 0); buf[0]; buf.set(1, 0x59); buf.to_string();
arena.used(); arena.free();        // use-after-free and double-free raise MemError
file_write("log.txt", "started\n");
res = exec("uname -s");            // {stdout, code} — shell escape hatch
print(res["stdout"]);

// ---- C++ native blocks (works in every engine) ----
cpp {
    // pyhp-export: int64_t pyh_fib(int64_t n);
    int64_t pyh_fib(int64_t n) {
        if (n < 2) return n;
        int64_t a = 0, b = 1;
        for (int64_t i = 1; i < n; i++) { int64_t t = a + b; a = b; b = t; }
        return b;
    }
}
echo pyh_fib(90), "\n";            // raw machine code

// ---- stdlib: AI / security / IoT ----
ai  = require("ai");
sec = require("sec");
iot = require("iot");
model  = ai.linear_fit([0, 1, 2, 3], [1, 3, 5, 7], 0.05, 2000);
net    = sec.subnet("10.0.5.130/26");
reads  = iot.sensor_sim(42, 8, 21.0, 1.5);
wire   = iot.mqtt_publish("th-1", reads, time());
doc    = iot.mqtt_consume(wire);   // CRC-verified
json   = json_string({"ok": true, "w": model.w});
exit(0);
```

---

Full command reference: [docs/REFERENCE.md](docs/REFERENCE.md) · cross-language
bridge tables: [docs/BRIDGE.md](docs/BRIDGE.md) ·
AI/LLM training kit: [ai/llms.txt](ai/llms.txt) + `ai/pyhp-spec.json` + verified
JSONL datasets (generated by `php tools/build_ai_data.php`).

## Reference

### Values & types

`int` `float` `string` `bool` `null` `list` `dict` `function` `buffer` `arena`, plus user classes. `type(x)` returns the name. Lists and dicts **alias by reference** (`b = a; b.append(1)` grows `a`); use `a[:]` or `.copy()` for a shallow copy. Equality is deep and Python-strict (`1 == 1.0` is true, `"1" == 1` is false). Truthiness is Python-style: `0`, `0.0`, `""`, `[]`, `{}` and `null` are falsy (note: `"0"` is *truthy*, unlike PHP).

### Operators

`+ - * / % **` (true division always yields float; `%` follows Python sign semantics), comparisons `< <= > >= == !=`, logical `and or not` / `&& || !` (short-circuit, value-preserving), bitwise `& | ^ ~ << >>`, ternary `c ? a : b`, membership `in` / `not in`, compound assigns `+= -= *= /= %= **= &= |= ^= <<= >>=`, postfix `++ --`, string concat via `+` (auto-converts the other side).

### Static typing (optional)

Annotate variables, parameters and return types: `int float string bool list dict`.
* `int x = 1; x = "s";` raises a catchable `TypeError`.
* `float f = 2;` widens to `2.0` (C-style).
* Checks run at assignment, call and return time in **both** engines.

### Memory arena (deterministic, safe)

`malloc_arena()` → arena; `arena.alloc(n)` → byte `buffer`; buffers support `get/set/write/fill/to_string/size` and `buf[i]` indexing; `arena.free()` releases everything. Out-of-bounds access, `use-after-free`, `double free`, allocation from a dead arena and byte values outside 0–255 all raise `MemError`/`ValueError` — C semantics with safety rails.

### System access

`exec(cmd)` → dict `{stdout, code}` · `system(cmd)` → exit code · `file_read / file_write / file_append / file_exists / file_delete / mkdir` · `env(name)` · `argv()` (args after the script path) · `time() sleep(ms) rand()` · `exit(code)`.

### Builtins

`print println echo` · `len range map filter zip enumerate sorted reversed next sum min max any all abs floor ceil round sqrt pow rand` · `int float str bool list dict type repr isinstance` · `chr ord hex bin` · `keys values items` · `json_parse json_string` · `md5 sha256 crc32 base64e base64d urlenc urldec` · `file_* exec system env argv exit time sleep input` · `malloc_arena`.

### Error hierarchy

`Exception` ← `TypeError ValueError KeyError IndexError ZeroDivisionError OverflowError StopIteration MemError RuntimeError`. `catch` with no type catches everything; user classes can `extends Exception`; `throw` accepts instances, builtin error classes, or plain strings (wrapped in `RuntimeError`).

---

## Transpiler notes & semantics parity

The transpiler generates readable PHP (closures, `parent::`, typed wraps) and preserves interpreter semantics, with these documented deltas:

| Behaviour | Interpreter | Compiled |
| --- | --- | --- |
| Classes inside functions | allowed | error (top level only) |
| Calling a top-level function before its declaration line | error | works (PHP hoists) |
| Type check message source | runtime | identical runtime checks |
| `require()` with a computed (non-literal) spec | works | compile-time error |

Both engines enforce argument arity and type errors themselves (no reliance on PHP's native argument rules), so error messages match.

## Architecture

```
install.sh       one-line installer (macOS/Linux): pyhp launcher on your PATH
uninstall.sh     removes install dir + launcher + PATH line (install.ps1 on Windows)
bin/pyhp          CLI: run (--tree) | build (--target php|cpp|native) | compile
                  | repl | langs | pkg | version | update
src/Lexer.php     source -> tokens (f-strings, raw `cpp { ... }` blocks)
src/Parser.php    tokens -> AST (recursive descent, ~36 node kinds)
src/Interpreter.php  AST -> PyHPEnv scopes, signals, classes (our own interpreter)
src/Vm.php        bytecode VM: stack machine over flat ops (default `pyhp run` engine)
src/GenCompiler.php  yield -> real generators on both PHP engines
src/Transpiler.php   AST -> PHP 8 source
src/CppTranspiler.php AST -> C++17 (single self-contained file, native backend)
src/langdata.php  81-entry cross-language tables behind `pyhp langs`
src/runtime.php   shared data model: PyList/PyDict, big-ints, errors, arena/buffers,
                  builtin registry, value helpers, the C++ native bridge
lib/              stdlib: ai.pyh (ML), sec.pyh (security), iot.pyh (telemetry)
examples/         eleven runnable tours + examples/pkg-demo (packages in action)
registry/         bundled package registry: strings, mathx, texttable
tests/run_tests.php  49 cases, asserted across tree, VM, compiled, native and exec-helper
docs/REFERENCE.md complete language reference · docs/BRIDGE.md cross-language tables
                  · docs/guides/ (AI, security, IoT)
ai/               llms.txt + pyhp-spec.json + verified JSONL training data
tools/            build_ai_data.php (AI kit) · build_langs.php (BRIDGE.md from langdata)
```

## Grammar (EBNF, abridged)

```
program     = statement* ;
statement   = funcdecl | classdecl | vardecl | if | while | dowhile | for
            | foreach | try | throw | return | break | continue | echo
            | block | exprstmt ;
vardecl     = ("int"|"float"|"string"|"bool"|"list"|"dict"|"var") ident ["=" expr] ";" ;
if          = "if" expr block { ("elseif"|"elif") expr block } ["else" block] ;
for         = "for" "(" [simple] ";" [expr] ";" [update] ")" block ;
foreach     = "foreach" ident ["," ident] "in" expr block ;
expr        = ternary ; ternary = or [ "?" ternary ":" ternary ] ;
or          = and { ("or"|"||") and } ;  ... arithmetic, bitwise, compare ...
postfix     = primary { "(" args ")" | "[" [expr] [":" [expr] [":" [expr]]] "]"
            | "." ident | "++" | "--" } ;
primary     = INT | FLOAT | STRING | FSTRING | "true" | "false" | "null" | ident
            | "this" | "super" | listlit | dictlit | comprehension
            | "(" expr ")" | lambda | anonfunc ;
```

## Roadmap

The open backlog is now **empty** — 0.7.0 cleared everything that was left
after 0.6.0's sprint (native classes with a heap-scoped model, lazy
generators + lazy `range`/`map` views, dicts across the C++ bridge, the
bytecode VM, big-int arithmetic) and added `pyhp langs`, the offline
cross-language bridge.

Ideas for future sprints (not started): generator `send()`/`close()`, `yield
from`, async I/O, a `pip`-style registry UI for `pyhp pkg`, and cross-engine
big-int support in the native backend (currently int64 only with clear
errors).

## License

MIT — see `LICENSE`.
