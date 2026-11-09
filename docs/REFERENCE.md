# PyHP 0.4.0 — Complete Reference

**PHP syntax. Python brains. C muscles.**

Every command, statement, operator, builtin and method in the language —
verified against the implementation by the 35-case test suite, which runs every
program through the execution engines (interpreter, PHP transpiler and, where a
C++ compiler is present, the native C++ backend) and requires byte-identical
output.

- File extension: `.pyh`
- Runs on: PHP 8.0+ CLI (no extensions required); native backend needs g++/clang++
- Engines: `pyhp run` / `pyhp repl` (tree-walking interpreter) ·
  `pyhp build` / `pyhp compile` (PyHP → PHP 8 transpiler) ·
  `pyhp build --target cpp|native` (PyHP → C++17 → machine code)

---

## 1. CLI reference

```
pyhp run     <script.pyh> [args...]      run via the built-in interpreter
pyhp build   <script.pyh> -o out.php     transpile to a standalone PHP file
pyhp build   <f.pyh> --target cpp -o app.cpp       emit C++17 source
pyhp build   <f.pyh> --target native -o app        emit C++17 + g++ -> executable
pyhp compile <script.pyh> [args...]      transpile + execute (fast path)
pyhp repl                                start the interactive shell
pyhp pkg init [name]                     create pyhp.json + src/ stub
pyhp pkg add <name>[@version]            install a package into vendor/
pyhp pkg remove <name>                   uninstall a package
pyhp pkg list                            show deps + registry availability
pyhp version | help
```

`pyhp` is the portable form on every OS: `pyhp.bat` on Windows (installed by
`install.ps1`), `~/.local/bin/pyhp` on macOS/Linux (installed by `install.sh`).
From a repo checkout, `./bin/pyhp <command>` and `php bin/pyhp <command>` also
work — `bin/pyhp` carries a `#!/usr/bin/env php` shebang.

Behavior details:

- Script arguments after `script.pyh` are available to the program as `argv()`.
- Runtime errors print `PyHP <ErrorType>: message` to stderr and exit with code 1.
- `exit(n)` propagates `n` as the process exit code.
- `pyhp build` output is a standalone PHP 8 file that only needs `src/runtime.php`.
- In compiled mode `require()` must take a **literal** string (modules are
  inlined at build time); the interpreter accepts any expression.

### REPL

```
$ pyhp repl
pyhp> x = 5
pyhp> [n ** 2 for n in range(x)]
=> [0, 1, 4, 9, 16]
pyhp> :vars
pyhp> :quit
```

| Command | Effect |
| --- | --- |
| `:help` | list REPL commands |
| `:vars` | dump all global variables |
| `:quit`, `:exit`, `:q`, Ctrl-D | leave the shell |

A bare expression (no semicolon needed) prints `=> repr(value)`. Statements that
span multiple lines are buffered until braces balance.

## 2. Lexical rules

- **Comments**: `// line`, `# line`, `/* block */`. (`//` is *not* integer division.)
- **Statements** end with `;`. Braces `{ }` are required for blocks.
- **Identifiers**: `[A-Za-z_][A-Za-z0-9_]*` — variables have **no `$` prefix**.
- **Keywords** include: `if elseif else while do for foreach in as break continue
  function return try catch finally throw exit echo print and or not true false null
  this super new lambda class extends int float string bool list dict var any`.
- Encoding: UTF-8; strings and identifiers are Unicode-aware (mb_* based).

## 3. Variables and types

```c
name = "world";          // dynamic — declaration by assignment
int count = 0;           // optional static annotation, enforced at runtime
float ratio = 2.5;       // int values widen to float automatically
string tag = "v1";
bool ok = true;
list xs = [1, 2, 3];
dict ages = {"ada": 36, 7: "seven"};
var anything = 42;       // explicit "any" annotation
```

Types: `int`, `float`, `string`, `bool`, `list`, `dict`, `null` plus user classes
and the built-in error classes. `int x = 5;` rejects any later assignment of a
non-int (`type mismatch: cannot assign float to int variable 'x'`).
Assignments without an annotation are always dynamic. A variable annotated but
declared without a value is `null`.

## 4. Literals

| Kind | Examples |
| --- | --- |
| int | `42`, `-7`, hex `0xFF`, binary `0b1010` |
| float | `3.14`, `2e3` |
| string | `"double"`, `'single'`, escapes `\n \t \r \\ \0 \' \"` |
| f-string | `f"hi {name}, 1+1={1 + 1}, {{escaped}}"` — full expressions inside `{}` |
| bool | `true`, `false` |
| null | `null` |
| list | `[1, "two", [3]]` — Python aliasing semantics (reference values) |
| dict | `{"a": 1, 7: "seven", "nested": {"x": 2}}` — keys: string or int |

## 5. Operators (full table)

| Category | Operators | Notes |
| --- | --- | --- |
| arithmetic | `+ - * / % **` | `/` is true division (`7 / 2 == 3.5`); `+` concatenates strings and merges lists/dicts |
| repeat | `*` | string * int and list * int repeat |
| comparison | `== != < <= > >=` | Python-style deep comparison for lists/dicts |
| logic | `and or not` | short-circuit; return operand values like Python |
| bitwise | `& \| ^ ~ << >>` | ints only (`0xFF & 0x0F`) |
| membership | `in not in` | lists, strings (substring), dicts (keys) |
| ternary | `cond ? a : b` | |
| compound assign | `+= -= *= /= %= **= &= \|= ^= <<= >>=` | `+=` concatenates strings/lists |
| increment | `++ --` | statement postfix |
| index/slice | `xs[i]`, `xs[a:b]`, `xs[a:b:step]` | negative indices; `xs[::-1]` reverses |

Operator precedence (high → low): `**` · unary `- ! not ~` · `* / %` · `+ -` ·
`<< >>` · `< <= > >= in not in` · `== !=` · `&` · `^` · `|` · `and` · `or` ·
ternary · assignment.

## 6. Control flow

```c
if x > 3 { echo "big\n"; } elseif x == 0 { echo "zero\n"; } else { echo "small\n"; }

while i < 10 { i++; }

do { i--; } while i > 0;

for (i = 0; i < 5; i++) { echo i; }

foreach item in xs { echo item; }              // values
foreach k, v in dictOrList { ... }             // key => value pairs

break; continue;
```

`foreach` iterates lists, strings (chars), and dicts. `break`/`continue` work in
all loops.

## 7. Functions

```c
function add(a, b) { return a + b; }
function greet(name, punct = "!") { return "hi " + name + punct; }
function total(...nums) { return sum(nums); }          // variadic
function checked(int a) -> int { return a; }           // typed params + return type

add(1, 2);
greet("ada", "?");
double = lambda n: n * 2;                              // lambda: params, colon, one expression
```

- Default parameter values and `...` variadics are supported.
- Closures capture by name from the defining scope; recursion works.
- Functions are first-class values: `g = add; map(double, xs)`.
- `return` without a value returns `null`.

## 8. Classes and OOP

```c
class Animal {
    string name = "";                 // property (optionally typed) with default
    function __construct(name) { this.name = name; }
    function speak() { return this.name + " makes a sound"; }
    function __str__() { return "Animal(" + this.name + ")"; }
}

class Dog extends Animal {
    function speak() { return this.name + " barks"; }
    function speakLoud() { return super.speak().upper(); }
}

d = Dog("Rex");
d.speak();            // method call
d.name;               // property read
str(d);               // uses __str__ (also prints via print/echo)
isinstance(d, "Dog"); // true — walks the inheritance chain
```

- Constructor: `__construct` (or `__init`).
- String hook: `__str__` (or `__toString`).
- `super.method(...)` / `super(...)` call the parent implementation.
- Property type annotations are enforced on every assignment.
- The 8 built-in error classes (below) behave like normal classes; you can
  extend them (`class HttpError extends Exception {...}`).

## 9. Errors, try/catch/finally

Built-in error classes: `Exception`, `TypeError`, `ValueError`, `KeyError`,
`IndexError`, `ZeroDivisionError`, `MemError`, `RuntimeError`.

```c
try {
    throw ValueError("bad input: " + str(v));
} catch (ValueError e) {
    print("caught:", e.message);
} catch (Exception e) {
    print("other:", e.message);
} finally {
    print("always runs");
}
```

`e.message` holds the text. An uncaught exception prints
`PyHP uncaught exception <Type>: <message>` and exits 1. `throw` accepts any
instance of an error class (or subclass).

## 10. Modules — `require()`

```c
lib  = require("./util.pyh");      // relative path (to the requiring file)
pkg  = require("mathx");           // vendor/ then registry package
```

- `require` runs the module file **once** in a fresh, isolated scope and returns
  its **export table**: a dict of everything the module defines at top level —
  named functions, top-level lambdas (`double = lambda n: ...`), and classes
  (as factory callables: `m.Circle(5)`).
- Modules are cached: two `require`s of the same file return the same table.
- The export table supports dot-access for calls and reads: `m.fn(x)`, `m.value`.
  Elements are also reachable by index: `m["fn"](x)`.
- Circular requires raise `circular module dependency`.
- Module classes cannot extend classes from other files (keep inheritance inside
  one module); they can extend built-in error classes.
- Recommendation: keep modules **stateless** (pure functions). Module-level
  variables are visible to module functions in the interpreter but not in
  compiled mode.

Resolution order for a package name `name`:
1. `./vendor/name/` (manifest `main`, then `src/name.pyh`, `main.pyh`, `name.pyh`)
2. `$PYHP_REGISTRY/name/`
3. `<PyHP install>/registry/name/`

## 11. Packages — `pyhp pkg`

A project is a folder with `pyhp.json`:

```json
{
  "name": "my-app",
  "version": "0.1.0",
  "description": "",
  "main": "src/my-app.pyh",
  "author": "",
  "license": "MIT",
  "dependencies": {
    "mathx": "1.0.0"
  }
}
```

| Command | Effect |
| --- | --- |
| `pyhp pkg init [name]` | create `pyhp.json` + `src/<name>.pyh` stub |
| `pyhp pkg add mathx` | copy the package from the registry into `vendor/`, record it in `dependencies` |
| `pyhp pkg add mathx@1.0.0` | same, with a version check |
| `pyhp pkg remove mathx` | remove from `dependencies` and delete `vendor/mathx` |
| `pyhp pkg list` | show project, dependencies, install state, registry catalog |

**Publishing**: a package is any folder with `pyhp.json` and an entry `.pyh`
file whose top-level functions/lambdas/classes are its public API. Add it to a
registry (the bundled `registry/` folder, a shared folder via `PYHP_REGISTRY`,
or a fork of this repo) — that's the whole process.

## 12. Built-in functions (all 62)

Arity is `min..max` (`n` = unlimited).

### Output & values
| Function | Arity | Description |
| --- | --- | --- |
| `print(...)` | 0..n | print args space-separated + newline |
| `println(...)` | 0..n | alias of print |
| `echo` (statement) | — | print args with no separator, no newline |
| `repr(v)` | 1..1 | Python-style repr: `'str'`, `[1, 2]`, `{'a': 1}` |
| `len(v)` | 1..1 | length of string/list/dict/buffer |
| `type(v)` | 1..1 | type name string: `"int"`, `"list"`, class name, ... |
| `isinstance(v, "Name")` | 2..2 | true if v is an instance of user class or error class `Name` (walks parents); for primitives use `type(v) == "list"` |
| `exit(code = 0)` | 0..1 | stop the program with exit code |

### Collections & iteration
| Function | Arity | Description |
| --- | --- | --- |
| `range(end)` / `range(start, end)` / `range(s, e, step)` | 1..3 | list of ints; step may be negative |
| `map(f, iter, ...)` | 2..n | apply f to items of one or more iterables (shortest wins) |
| `filter(f, iter)` | 2..2 | keep items where truthy(f(item)) |
| `zip(it, ...)` | 1..n | list of `[a, b, ...]` tuples, shortest wins |
| `enumerate(iter, start = 0)` | 1..2 | list of `[index, item]` |
| `sorted(iter)` | 1..1 | new sorted list (no key function yet) |
| `reversed(iter)` | 1..1 | new reversed list |
| `sum(iter, start = 0)` | 1..2 | sum of numbers |
| `any(iter)` / `all(iter)` | 1..1 | Python truthiness semantics |
| `keys(d)` / `values(d)` / `items(d)` | 1..1 | dict views as lists |
| `list(iter = [])` | 0..1 | copy/convert to list |
| `dict(dOrPairs = {})` | 0..1 | copy dict, or build from `[k, v]` pairs |

### Math
| Function | Arity | Description |
| --- | --- | --- |
| `abs(x)` | 1..1 | absolute value |
| `min(...)` / `max(...)` | 1..n | of several args or one iterable |
| `round(x, digits = 0)` | 1..2 | round half away; with digits returns float |
| `floor(x)` / `ceil(x)` | 1..1 | round down/up (returns int) |
| `sqrt(x)` | 1..1 | square root; ValueError for negative |
| `pow(a, b)` | 2..2 | a ** b |
| `rand()` | 0..0 | float in [0, 1) |
| `rand(n)` | 1..1 | int in [0, n) |
| `rand(lo, hi)` | 2..2 | int in [lo, hi] inclusive |

### Conversion
| Function | Arity | Description |
| --- | --- | --- |
| `int(v = 0)` | 0..1 | truncate to int (from float/string/bool) |
| `float(v = 0.0)` | 0..1 | convert to float; ValueError on bad strings |
| `str(v = "")` | 0..1 | to string |
| `bool(v = false)` | 0..1 | Python truthiness |
| `chr(n)` | 1..1 | Unicode code point → char |
| `ord(c)` | 1..1 | single char → code point |
| `hex(n)` | 1..1 | int → `"0x2a"` (negative: `"-0x2a"`) |
| `bin(n)` | 1..1 | int → `"0b1010"` |

### System & I/O ("C DNA")
| Function | Arity | Description |
| --- | --- | --- |
| `input(prompt = "")` | 0..1 | read a line from stdin |
| `env(name)` | 1..1 | environment variable or null |
| `argv()` | 0..0 | script arguments as a list |
| `file_read(path)` | 1..1 | whole file as a string |
| `file_write(path, text)` | 2..2 | write (truncate) |
| `file_append(path, text)` | 2..2 | append |
| `file_exists(path)` | 1..1 | bool |
| `file_delete(path)` | 1..1 | delete file |
| `mkdir(path)` | 1..1 | create directory (recursive) |
| `exec(cmd)` | 1..1 | run command → `{"stdout": "...", "code": n}` |
| `system(cmd)` | 1..1 | run command, stream output → exit code |
| `time()` | 0..0 | Unix time as float seconds |
| `sleep(ms)` | 1..1 | sleep milliseconds |

### JSON, hashing & encoding (AI / security / IoT toolkit)
| Function | Arity | Description |
| --- | --- | --- |
| `json_parse(s)` | 1..1 | JSON text -> PyHP value (lists/dicts); invalid JSON -> `ValueError` |
| `json_string(v)` | 1..1 | PyHP value -> JSON text (deterministic key order) |
| `md5(s)` | 1..1 | MD5 hex digest |
| `sha256(s)` | 1..1 | SHA-256 hex digest (also in the native backend) |
| `crc32(s)` | 1..1 | CRC-32 (IEEE) hex digest |
| `base64e(s)` / `base64d(s)` | 1..1 | base64 encode / strict decode |
| `urlenc(s)` / `urldec(s)` | 1..1 | RFC 3986 percent-encode / decode |

### Memory arena ("C DNA")
| Function | Arity | Description |
| --- | --- | --- |
| `malloc_arena()` | 0..0 | fresh arena object |

Arena API: `a.alloc(n)` → buffer of n bytes · `a.free()` (double free →
`MemError: double free`) · `a.used()` · `a.buffers()`. Freed arenas refuse
further allocations; freed buffers refuse access (use-after-free detection).

Buffer API: `b[i]` get, `b[i] = v` set (0..255 checked), `b.size()`,
`b.write(str, at = 0)`, `b.fill(byte)`.

## 13. String methods (15)

`upper lower capitalize strip lstrip rstrip split replace startswith endswith
contains find count join chars`

```c
s = "Hello, World";
s.upper(); s.lower(); s.capitalize();
s.strip(); s.lstrip(); s.rstrip();
s.split(", ");            // ["Hello", "World"]  (no arg: split on whitespace)
s.replace("World", "PyHP");
s.startswith("Hello"); s.endswith("World"); s.contains("lo, ");
s.find("World");          // 7, or -1
s.count("l");
"-".join(["a", "b"]);     // join takes any iterable
s.chars();                // ["H","e","l","l","o", ...]
```

## 14. List methods (11)

`append pop extend insert remove index count sort reverse clear copy`

```c
xs = [3, 1];
xs.append(5); xs.insert(0, 9); xs.extend([7, 8]);
xs.pop(); xs.pop(0);
xs.remove(3); xs.index(5); xs.count(1);
xs.sort(); xs.reverse(); xs.clear();
ys = xs.copy();
```

## 15. Dict methods (9)

`keys values items get has remove clear copy merge`

```c
d = {"a": 1};
d.get("b", "dflt"); d.has("a");
d.remove("a");          // KeyError if missing
d.merge({"b": 2});      // right side wins
d.keys(); d.values(); d.items(); d.clear(); d.copy();
```

Callables stored in a dict are also dot-callable: `ops = {"twice": lambda s: s + s}; ops.twice("x")`.

## 16. Static typing ("C DNA")

- Annotations: `int float string bool list dict var|any` on variables, params,
  returns, class properties.
- Enforcement: runtime, on every assignment (`TypeError` on mismatch).
- `float x` widens `int` values automatically.
- Compiled output carries PHP type hints where safe; all checks still run.

## 17. Engine parity

The test suite (`php tests/run_tests.php`, 35 cases) executes every program on
both PHP engines (plus the native backend where g++ exists) and requires
byte-identical output. The only known divergences:

| Case | Interpreter | Compiled |
| --- | --- | --- |
| `require()` with a computed (non-literal) spec | works | compile-time error — use a literal |
| module functions reading module-level variables | works | not supported — keep modules stateless |
| `catch` of a class defined in another module | matches by name | falls back to generic catch |

## 18. C++ native blocks — `cpp { ... }`

Embed real C++ inside a `.pyh` file. Functions declared with a
`// pyhp-export:` directive become callable from PyHP. The bridge speaks the
classic C ABI: `int64_t`, `double`, `const char*` and `void` in any position.

```c
cpp {
    // pyhp-export: double pyh_hypot(double a, double b);
    double pyh_hypot(double a, double b) { return sqrt(a*a + b*b); }
}

echo pyh_hypot(3, 4), "\n";     // 5.0 — raw machine code
```

Rules:

- The block is C++17. Common headers (`<cstdint> <cmath> <cstdio> <cstring>
  <cstdlib> <string>`) are pre-included; add your own `#include` lines inside
  the block.
- Export names are global across the whole program — prefix them, e.g.
  `pyh_mymod_func`.
- Argument/return kinds are fixed by the `pyhp-export` declaration; passing a
  float where `int64_t` is declared raises `TypeError`.
- Compilation is lazy (first call), cached in `./.pyhp_native/` (override with
  `PYHP_NATIVE_CACHE`), and needs a C++ compiler (`g++`, `clang++` or `c++`;
  override with `CXX`).
- Bridge mode is picked automatically: **FFI** (shared object loaded with PHP
  ext/ffi, when available) or **exec** (a standalone helper binary driven over
  a tiny line protocol — works on every PHP build).
- Works in ALL engines: interpreter, PHP transpiler, and the native C++ backend
  (where the block is pasted straight into the generated program).

## 19. Native C++ backend — `pyhp build --target cpp|native`

PyHP compiles to a single self-contained C++17 file implementing the whole
value model (aliasing lists/dicts, Python semantics, f-strings,
comprehensions, try/catch, modules, native cpp blocks) with no PHP involved:

```
pyhp build app.pyh --target cpp    -o app.cpp   # inspect/edit the C++ source
g++ -O2 -std=c++17 -o app app.cpp               # or let PyHP do it:
pyhp build app.pyh --target native -o app       # emit + compile
./app arg1 arg2                                 # argv() works
```

Supported: variables, all operators, strings + 15 methods, lists/dicts +
methods, slicing, f-strings, comprehensions, top-level functions (recursion,
defaults, variadics), top-level function references as values (`map(len, xs)`),
`foreach`/`while`/`do`/`for`, `try`/`catch`/`throw`, `require()` of modules and
stdlib, `cpp { ... }` blocks, and most builtins (`print len range map filter
sorted sum abs min max sqrt round chr ord hex bin json_parse json_string
base64e/base64d urlenc/urldec sha256 file_read/file_write argv exit time ...`).

Not yet in the native backend (clear compile errors — use the PHP engines):
classes & `super`, lambdas/function expressions, `try/finally`, `md5`/`crc32`,
`exec`/`system`/`env`/`sleep`/`input`/`malloc_arena`, `isinstance` of classes.
Dict keys are stored with an internal marker (int and string keys stay
distinct); string keys beginning with `\x01` are reserved.

## 20. Standard library — `ai`, `sec`, `iot`

Modules ship with the language in `lib/` and load with
`m = require("ai");` — no package install needed. All functions are pure and
deterministic (seeded LCGs, no wall-clock), so output is byte-identical on
every engine.

### `ai` — machine learning
| Function | Purpose |
| --- | --- |
| `dot` `euclid` `manhattan` `cosine` | vector algebra & distances |
| `mean` `variance` `stdev` `median` | statistics |
| `minmax` `standardize` | feature scaling |
| `train_test_split(xs, ratio, seed)` | deterministic split |
| `rng_next(state)` `rand_list(seed, n)` | seeded pseudo-random floats |
| `linear_fit(xs, ys, lr, epochs)` / `linear_predict` | gradient-descent regression |
| `sigmoid` `relu` | activations |
| `accuracy` `mse` `mae` `r2` | metrics |
| `kmeans(points, k, iters)` / `nearest_centroid` | clustering |
| `matmul` `transpose` | linear algebra |

### `sec` — security research (CTF / authorized pentest labs)
| Function | Purpose |
| --- | --- |
| `xor_cipher` `caesar` `rot13` `vigenere` `vigenere_dec` | classical ciphers |
| `crack_caesar(cipher)` | brute force + English chi-square scoring |
| `english_score` `char_freq` `entropy` `log2` | cryptanalysis helpers |
| `hexdump` | classic 16-byte dump with ASCII gutter |
| `ip_to_int` `int_to_ip` `subnet(cidr)` `host_range` | network math |
| `password_gen(len, seed)` `hash_file(path)` | generators & file hashing |

### `iot` — sensors, telemetry & framing
| Function | Purpose |
| --- | --- |
| `clamp` `moving_avg` `ema` `median` `deadband` `rolling_stats` | signal conditioning |
| `threshold_flags` | alarm bands |
| `sensor_sim(seed, n, base, noise)` | deterministic sensor streams |
| `crc16` | CRC-16/Modbus |
| `telemetry` `parse_telemetry` `telemetry_batch` | JSON / NDJSON telemetry |
| `mqtt_frame` `mqtt_parse` `mqtt_publish` `mqtt_consume` | MQTT-style frames with CRC trailers |

Domain walkthroughs live in [`guides/AI-DEVELOPMENT.md`](guides/AI-DEVELOPMENT.md),
[`guides/SECURITY-RESEARCH.md`](guides/SECURITY-RESEARCH.md) and
[`guides/IOT.md`](guides/IOT.md).

---

*This reference describes PyHP 0.4.0. Machine-readable versions for AI
tooling live in [`ai/pyhp-spec.json`](../ai/pyhp-spec.json) and
[`ai/llms.txt`](../ai/llms.txt).*
