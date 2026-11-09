# PyHP 0.1.0

**PHP syntax. Python brains. C muscles.**

PyHP is a small dynamically-typed scripting language that fuses three bloodlines:

| DNA | What it brings |
| --- | --- |
| **PHP** | familiar syntax — braces, semicolons, `echo`, `foreach`, `$`-free variables |
| **Python** | lists & dicts that alias like objects, slicing with steps, comprehensions, lambdas & closures, rich builtins, string/list/dict methods, classes with `super`, Python-style truthiness |
| **C** | hex/binary literals, bitwise operators, optional static type annotations, a deterministic `malloc`/`free` byte-buffer arena, file I/O and shell escape hatches |

PyHP ships with **two execution paths** built from the same front-end:

1. **Our own interpreter** — a hand-written lexer → recursive-descent parser → AST → tree-walking evaluator (`pyhp run`, `pyhp repl`).
2. **A transpiler** — PyHP → clean, standalone PHP 8 source (`pyhp build` / `pyhp compile`) that runs natively and requires only `src/runtime.php`.

Both paths share the exact same data model, so the 26-case test suite passes every program through *both* engines and asserts byte-identical output.

---

## Quick start

Requirements: PHP 8.0+ (CLI). No extensions needed.

> **Windows?** One line in PowerShell — no admin rights:
>
> ```powershell
> irm https://raw.githubusercontent.com/Stevem100/pyhp/main/install.ps1 | iex
> ```
>
> The installer sets up PyHP *and* PHP 8.3 (only if you don't already have PHP),
> adds `pyhp` to your PATH, and runs a smoke test. Full details, parameters and
> a manual route: [INSTALL-WINDOWS.md](INSTALL-WINDOWS.md).

```bash
# run with the built-in interpreter
php bin/pyhp run examples/01_hello.pyh

# run with the transpiled fast path
php bin/pyhp compile examples/01_hello.pyh

# transpile to a standalone PHP file you can deploy anywhere
php bin/pyhp build examples/01_hello.pyh -o hello.php
php hello.php extra cli args   # -> available as argv()

# interactive shell
php bin/pyhp repl

# run the test suite (26 cases x 2 engines)
php tests/run_tests.php
```

### REPL in 60 seconds

```
$ php bin/pyhp repl
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
exit(0);
```

---

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

`print println echo` · `len range map filter zip enumerate sorted reversed sum min max any all abs floor ceil round sqrt pow rand` · `int float str bool list dict type repr isinstance` · `chr ord hex bin` · `keys values items` · `file_* exec system env argv exit time sleep input` · `malloc_arena`.

### Error hierarchy

`Exception` ← `TypeError ValueError KeyError IndexError ZeroDivisionError MemError RuntimeError`. `catch` with no type catches everything; user classes can `extends Exception`; `throw` accepts instances, builtin error classes, or plain strings (wrapped in `RuntimeError`).

---

## Transpiler notes & semantics parity

The transpiler generates readable PHP (closures, `parent::`, typed wraps) and preserves interpreter semantics, with these documented deltas:

| Behaviour | Interpreter | Compiled |
| --- | --- | --- |
| Classes inside functions | allowed | error (top level only) |
| Calling a top-level function before its declaration line | error | works (PHP hoists) |
| `obj.method` used as a bound-method value | works | not supported |
| Type check message source | runtime | identical runtime checks |

Both engines enforce argument arity and type errors themselves (no reliance on PHP's native argument rules), so error messages match.

## Architecture

```
bin/pyhp          CLI: run | build | compile | repl | test entry points
src/Lexer.php     source -> tokens (f-strings lexed into parts)
src/Parser.php    tokens -> AST (recursive descent, ~35 node kinds)
src/Interpreter.php  AST -> PyHPEnv scopes, signals, classes (our own interpreter)
src/Transpiler.php   AST -> PHP 8 source
src/runtime.php   shared data model: PyList/PyDict, errors, arena/buffers,
                  builtin registry, value helpers (str/repr/compare/slice...)
examples/         five runnable tours of the language
tests/run_tests.php  26 cases, each asserted in interpreter AND compiled mode
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

* keyword arguments in calls, `sorted(xs, key=f)`
* lazy generators (`range`, `map`) and iterator protocol
* dict dot-access, bound-method values in compiled mode
* modules/imports, string interpolation format specs (`{x:.2f}`)
* bytecode VM for the interpreter path, big-int arithmetic

## License

MIT — see `LICENSE`.
