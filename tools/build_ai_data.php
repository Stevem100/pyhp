<?php
/**
 * PyHP AI-data builder.
 *
 * Generates machine-readable training/reference data for LLMs from the LIVE
 * language implementation:
 *
 *   ai/pyhp-spec.json    — language spec: builtins (+arity), methods, syntax
 *   ai/pyhp-qa.jsonl     — chat-format Q&A pairs, every code output VERIFIED
 *                          by actually running the snippet on the interpreter
 *   ai/pyhp-code.jsonl   — task -> solution pairs, every solution parse-checked
 *                          and executed to prove it runs
 *
 * Run:  php tools/build_ai_data.php
 */

require_once __DIR__ . '/../src/runtime.php';
require_once __DIR__ . '/../src/Lexer.php';
require_once __DIR__ . '/../src/Parser.php';
require_once __DIR__ . '/../src/Interpreter.php';

$root = dirname(__DIR__);
$outDir = $root . '/ai';
if (!is_dir($outDir)) { mkdir($outDir, 0777, true); }

/* ------------------------------------------------------------------ */
/* 1. spec: builtins, methods, syntax facts                           */
/* ------------------------------------------------------------------ */

$builtins = [];
foreach (pyhp_builtins() as $name => [$fn, $min, $max]) {
    $builtins[] = [
        'name' => $name,
        'minArgs' => $min,
        'maxArgs' => $max === null ? null : $max,
    ];
}

$stringMethods = ['upper','lower','capitalize','strip','lstrip','rstrip','split',
    'replace','startswith','endswith','contains','find','count','join','chars'];
$listMethods   = get_class_methods(new PyList([]));
$dictMethods   = array_values(array_diff(get_class_methods(new PyDict([])), ['getIterator']));

$spec = [
    'language' => 'PyHP',
    'version' => PYHP_VERSION,
    'tagline' => 'PHP syntax, Python brains, C muscles.',
    'fileExtension' => '.pyh',
    'runtime' => 'PHP 8.0+ CLI, no extensions',
    'engines' => [
        'interpreter' => 'tree-walking AST evaluator (pyhp run / pyhp repl)',
        'transpiler' => 'PyHP -> standalone PHP 8 (pyhp build / pyhp compile); both engines must produce byte-identical output',
    ],
    'lexical' => [
        'comments' => ['// line', '# line', '/* block */'],
        'statementTerminator' => ';',
        'blocks' => 'braces {} always',
        'variables' => 'no $ prefix; declared by assignment or annotated decl',
        'stringQuotes' => ['"double"', "'single'"],
        'fStrings' => 'f"text {expr} more" — full expressions inside {}',
        'numberLiterals' => ['42', '3.14', '0xFF (hex)', '0b1010 (binary)'],
    ],
    'types' => ['int', 'float', 'string', 'bool', 'list', 'dict', 'null', 'user classes', 'error classes'],
    'staticTyping' => [
        'annotations' => ['int', 'float', 'string', 'bool', 'list', 'dict', 'var', 'any'],
        'enforcement' => 'runtime on every assignment; float widens int',
    ],
    'operators' => [
        'arithmetic' => ['+', '-', '*', '/', '%', '**'],
        'comparison' => ['==', '!=', '<', '<=', '>', '>='],
        'logical' => ['and', 'or', 'not'],
        'bitwise' => ['&', '|', '^', '~', '<<', '>>'],
        'membership' => ['in', 'not in'],
        'ternary' => 'cond ? a : b',
        'compoundAssign' => ['+=', '-=', '*=', '/=', '%=', '**=', '&=', '|=', '^=', '<<=', '>>='],
        'increment' => ['++', '--'],
        'indexing' => ['xs[i] (negative ok)', 'xs[a:b]', 'xs[a:b:step] e.g. xs[::-1]'],
        'notes' => [
            '// is a comment, not integer division; / is true division',
            '+ concatenates strings, merges lists and dicts',
            'string * int and list * int repeat',
        ],
    ],
    'statements' => ['if/elseif/else', 'while', 'do...while', 'for(init;cond;update)',
        'foreach x in xs', 'foreach k, v in xs', 'break', 'continue', 'echo',
        'function', 'return', 'class X extends Y', 'try/catch/finally', 'throw',
        'varDecl (int x = 5;)', 'expression statement'],
    'functions' => [
        'declaration' => 'function name(a, b = 5) { ... }',
        'typed' => 'function name(int a) string { ... }',
        'variadic' => 'function name(...xs) { ... }',
        'lambda' => 'double = lambda n: n * 2;',
        'firstClass' => true,
    ],
    'classes' => [
        'declaration' => 'class Dog extends Animal { string name = ""; function __construct(n) { this.name = n; } }',
        'constructor' => '__construct or __init',
        'stringHook' => '__str__ or __toString',
        'superCall' => 'super(...) and super.method(...)',
        'errors' => 'built-in error classes behave like normal classes and can be extended',
    ],
    'errorClasses' => array_keys(pyhp_error_class_map()),
    'modules' => [
        'form' => 'm = require("./lib.pyh") | m = require("pkgname")',
        'exports' => 'top-level named functions, top-level lambdas, classes (as factories)',
        'access' => 'm.fn(x), m.value, m["fn"](x); classes: m.ClassName(args)',
        'caching' => 'per resolved path; same table returned',
        'resolution' => ['./vendor/<pkg>', '$PYHP_REGISTRY/<pkg>', '<install>/registry/<pkg>'],
        'compiledMode' => 'spec must be a literal string; modules are inlined at build time',
        'recommendation' => 'keep modules stateless (pure functions/classes)',
    ],
    'packages' => [
        'manifest' => 'pyhp.json: {name, version, description, main, author, license, dependencies}',
        'cli' => ['pyhp pkg init [name]', 'pyhp pkg add <name>[@ver]', 'pyhp pkg remove <name>', 'pyhp pkg list'],
        'vendorDir' => 'vendor/',
    ],
    'builtins' => $builtins,
    'stringMethods' => $stringMethods,
    'listMethods' => $listMethods,
    'dictMethods' => $dictMethods,
    'repl' => [':help', ':vars', ':quit / :exit / :q / Ctrl-D', 'bare expressions echo => repr(value)'],
];

file_put_contents($outDir . '/pyhp-spec.json',
    json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
echo "wrote ai/pyhp-spec.json (" . count($builtins) . " builtins)\n";

/* ------------------------------------------------------------------ */
/* 2. verified output snippets -> pyhp-qa.jsonl                        */
/* ------------------------------------------------------------------ */

function pyhp_ai_run(string $code): string {
    ob_start();
    try {
        $stmts = PyHPParser::parseSource($code);
        (new PyHPInterpreter())->runProgram($stmts);
        $out = ob_get_clean();
    } catch (Throwable $e) {
        ob_end_clean();
        return "<<error>> " . $e->getMessage();
    }
    return $out;
}

/**
 * Each entry: [question, code]. The answer embeds the code plus its REAL
 * captured output. Entries whose code errors are skipped loudly.
 */
$snippets = [
    ["How do I print output in PyHP?", 'print("Hello", "World");' . "\n" . 'echo "no", "newline";'],
    ["How do variables work in PyHP?", 'name = "Ada";' . "\n" . 'int count = 3;' . "\n" . 'count += 4;' . "\n" . 'print(name, count);'],
    ["Does PyHP support static typing?", 'int x = 5;' . "\n" . 'float y = x;' . "\n" . 'print(x, y);'],
    ["What happens if I assign the wrong type to a typed variable?",
        'int x = 5;' . "\n" . 'try { x = "oops"; } catch (TypeError e) { print(e.message); }'],
    ["How do f-strings work?", 'n = "PyHP";' . "\n" . 'print(f"hi {n}, 2+2={2 + 2}, {{literal}}");'],
    ["How do lists work?", 'xs = [3, 1, 2];' . "\n" . 'xs.append(9);' . "\n" . 'print(xs, len(xs), xs[0], xs[-1]);'],
    ["How does list slicing work?", 'xs = [0, 1, 2, 3, 4, 5];' . "\n" . 'print(xs[1:4], xs[::2], xs[::-1], xs[-2:]);'],
    ["Do lists alias like in Python?",
        'a = [1, 2, 3];' . "\n" . 'b = a;' . "\n" . 'b.append(4);' . "\n" . 'print(a);' . "\n" . 'print(a.copy());'],
    ["How do dict comprehensions work?", 'prices = {"tea": 2, "coffee": 5};' . "\n" . 'print({k: v * 2 for k, v in prices});'],
    ["How do list comprehensions with filters work?", 'print([n for n in range(10) if n % 3 == 0]);'],
    ["How does foreach work?", 'foreach k, v in {"a": 1, "b": 2} { print(k, "=>", v); }'],
    ["How does a while loop work?", 'i = 3;' . "\n" . 'while i > 0 { echo i, " "; i--; }' . "\n" . 'print("");'],
    ["How does a C-style for loop work?", 'for (i = 0; i < 3; i++) { echo i, " "; }' . "\n" . 'print("");'],
    ["How do default arguments work?",
        'function greet(name, punct = "!") { return "hi " + name + punct; }' . "\n" . 'print(greet("Ada"));' . "\n" . 'print(greet("Ada", "?"));'],
    ["How do variadic functions work?", 'function total(...xs) { return sum(xs); }' . "\n" . 'print(total(1, 2, 3, 4));'],
    ["How do lambdas work?", 'double = lambda n: n * 2;' . "\n" . 'print(double(21), map(double, [1, 2, 3]));'],
    ["How do closures work?",
        'function adder(x) {' . "\n" . '    return lambda y: x + y;' . "\n" . '}' . "\n" . 'add5 = adder(5);' . "\n" . 'print(add5(37), adder(1)(2));'],
    ["How do classes work?",
        'class Animal {' . "\n" . '    string name = "";' . "\n" . '    function __construct(name) { this.name = name; }' . "\n" . '    function speak() { return this.name + " makes a sound"; }' . "\n" . '}' . "\n" . 'print(Animal("Rex").speak());'],
    ["How does inheritance and super work?",
        'class Animal {' . "\n" . '    function speak() { return "..."; }' . "\n" . '}' . "\n" . 'class Dog extends Animal {' . "\n" . '    function speak() { return super.speak() + " woof"; }' . "\n" . '}' . "\n" . 'print(Dog().speak());'],
    ["How does __str__ work?",
        'class Point {' . "\n" . '    int x = 0; int y = 0;' . "\n" . '    function __str__() { return f"({this.x}, {this.y})"; }' . "\n" . '}' . "\n" . 'p = Point();' . "\n" . 'p.x = 3; p.y = 4;' . "\n" . 'print(p, str(p));'],
    ["How do exceptions work?",
        'try {' . "\n" . '    throw ValueError("bad input");' . "\n" . '} catch (ValueError e) {' . "\n" . '    print("caught:", e.message);' . "\n" . '} finally {' . "\n" . '    print("finally ran");' . "\n" . '}'],
    ["Which error classes exist and how do I catch index errors?",
        'try { [1, 2][9]; } catch (IndexError e) { print(e.message); }'],
    ["What error do I get dividing by zero?", 'try { 1 / 0; } catch (ZeroDivisionError e) { print(e.message); }'],
    ["How do bitwise operators work?",
        'print(0xFF & 0x0F, 0xF0 | 0x0F, 0xFF ^ 0x0F, ~0, 1 << 8, 256 >> 4);'],
    ["How do hex and binary literals work?", 'print(0xFF, 0b1010, hex(42), bin(10));'],
    ["How does the memory arena work?",
        'a = malloc_arena();' . "\n" . 'b = a.alloc(4);' . "\n" . 'b[0] = 72; b[1] = 105;' . "\n" . 'print(b[0], b[1], b.size(), a.used());' . "\n" . 'a.free();'],
    ["What happens on use-after-free?",
        'a = malloc_arena(); b = a.alloc(2); a.free();' . "\n" . 'try { b[0]; } catch (MemError e) { print(e.message); }'],
    ["How do string methods work?",
        's = "Hello, World";' . "\n" . 'print(s.upper(), s.split(", "), s.replace("World", "PyHP"), s.find("World"));'],
    ["How does join work?", 'print("-".join(["a", "b", "c"]));' . "\n" . 'print(" ".join("abc".chars()));'],
    ["How do dict methods work?",
        'd = {"a": 1};' . "\n" . 'print(d.get("b", 0), d.has("a"));' . "\n" . 'd.merge({"b": 2});' . "\n" . 'print(d.keys(), d.values());'],
    ["How does enumerate work?", 'foreach i, c in enumerate("abc") { print(i, c); }'],
    ["How does zip work?", 'print(zip([1, 2, 3], ["a", "b"]));'],
    ["How does filter work?", 'print(filter(lambda x: x > 2, [1, 2, 3, 4]));'],
    ["How does sorted work?", 'print(sorted([3, 1, 2]), sorted(["b", "a"]));'],
    ["How does sum work with a start value?", 'print(sum([1, 2, 3]), sum([1, 2], 10));'],
    ["How does ternary work?", 'x = 7;' . "\n" . 'print(x % 2 == 0 ? "even" : "odd");'],
    ["How do in / not in work?", 'print(2 in [1, 2], "ell" in "hello", "z" not in {"a": 1});'],
    ["How do dict keys work?", 'd = {"s": 1, 7: "seven"};' . "\n" . 'print(d["s"], d[7]);'],
    ["How do I read command-line arguments?", 'foreach i, a in argv() { print(i, a); }'],
    ["How do I write and read a file?",
        'file_write("/tmp/pyhp_ai_demo.txt", "line1\\nline2\\n");' . "\n" . 'print(file_read("/tmp/pyhp_ai_demo.txt"));'],
    ["How does repr differ from str?", 'print(repr("hi"), "hi", repr([1, "a"]));'],
    ["How does type() work?", 'print(type(1), type(1.5), type("s"), type([1]), type({"a": 1}), type(null));'],
    ["How does truthiness work?", 'print(bool(0), bool(""), bool([]), bool([0]), bool("0"), bool(null));'],
    ["How does math chaining with ** work?", 'print(2 ** 10, 2 ** 3 ** 2, -2 ** 2);'],
    ["How does do-while work?", 'i = 0;' . "\n" . 'do { echo i, " "; i++; } while i < 3;' . "\n" . 'print("");'],
    ["How does break/continue work?",
        'for (i = 0; i < 6; i++) {' . "\n" . '    if i == 2 { continue; }' . "\n" . '    if i == 5 { break; }' . "\n" . '    echo i, " ";' . "\n" . '}' . "\n" . 'print("");'],
    ["How do I check what kind of value I have?",
        'foreach v in [1, 1.5, "s", [1], {"a": 1}, null] { echo type(v), " "; }' . "\n" . 'print("");'],
    ["How does dict + dict work?", 'print({"a": 1} + {"b": 2});'],
    ["How does string repetition work?", 'print("ab" * 3, [0] * 3);'],
    ["How do I format numbers inside strings?", 'pi = 3.14159;' . "\n" . 'print(f"pi={pi}, int={int(pi)}");'],
];

$qa = [];
$skipped = 0;
foreach ($snippets as [$question, $code]) {
    $out = pyhp_ai_run($code);
    if (str_starts_with($out, '<<error>>')) {
        fwrite(STDERR, "SNIPPET FAILED ({$question}): {$out}\n");
        $skipped++;
        continue;
    }
    $answer = "PyHP (PHP syntax, Python brains, C muscles) — file extension `.pyh`.\n\n"
        . "```c\n" . rtrim($code) . "\n```\n\n"
        . "Output (verified by running it on the PyHP interpreter):\n\n"
        . "```\n" . rtrim($out, "\n") . "\n```";
    $qa[] = [
        'messages' => [
            ['role' => 'system', 'content' => 'You are an expert PyHP programmer. PyHP: PHP syntax (braces, semicolons, no $ variables), Python semantics (lists/dicts alias, comprehensions, lambdas, classes with super), C muscles (bitwise ops, 0x/0b literals, static types, malloc/free memory arena, file/system access). Both an interpreter and a transpiler execute PyHP with byte-identical output.'],
            ['role' => 'user', 'content' => $question],
            ['role' => 'assistant', 'content' => $answer],
        ],
    ];
}

/* how-to answers without code output (docs-derived, deterministic facts) */
$facts = [
    ["What is PyHP?",
     "PyHP is a small dynamically typed scripting language with a PHP-family syntax and three bloodlines:\n\n- **PHP**: braces, semicolons, `echo`, `foreach`, C-style `for`, but **no `$` before variables**\n- **Python**: lists & dicts that alias like objects, negative-index slicing with steps (`xs[::-1]`), comprehensions, lambdas & closures, classes with `super` and `__str__`, Python truthiness, rich builtins\n- **C**: `0xFF`/`0b1010` literals, bitwise operators, optional static type annotations enforced at runtime, a deterministic `malloc_arena()` byte buffer with use-after-free and double-free detection, plus file/env/exec escape hatches\n\nPyHP ships two execution paths built from one front-end: a tree-walking interpreter (`pyhp run`, `pyhp repl`) and a transpiler to standalone PHP 8 (`pyhp build`, `pyhp compile`). The 29-case test suite runs every program through both engines and asserts byte-identical output."],
    ["How do I install PyHP on Windows?",
     "One line in PowerShell:\n\n```powershell\nirm https://raw.githubusercontent.com/Stevem100/pyhp/main/install.ps1 | iex\n```\n\nThe installer needs no admin rights: it installs PyHP to `%LOCALAPPDATA%\\Programs\\PyHP`, reuses PHP if you already have it (PATH / `PHP_BIN`), otherwise downloads PHP 8.3 NTS x64 into the PyHP folder, adds `pyhp` to your user PATH, and runs a smoke test. Manual route and parameters (`-InstallDir`, `-NoPath`, `-SkipPhp`): see `INSTALL-WINDOWS.md`. Uninstall with `uninstall.bat` in the install folder."],
    ["How do I install PyHP on Linux/macOS?",
     "PyHP needs PHP 8.0+ CLI:\n\n```bash\n# 1. get PHP (most distros: apt/dnf/brew install php)\nphp -v\n# 2. get PyHP\ngit clone https://github.com/Stevem100/pyhp.git && cd pyhp\n# 3. run\nphp bin/pyhp run examples/01_hello.pyh\nphp bin/pyhp repl\n```\n\nNo extensions, no build step, no composer dependencies."],
    ["How do I run a PyHP program?",
     "```bash\npyhp run script.pyh            # interpreter\npyhp compile script.pyh        # transpile to PHP in a temp file + run (fast path)\npyhp build script.pyh -o out.php && php out.php   # standalone output\npyhp repl                      # interactive shell\n```\n\nAll four accept extra CLI args, which the program reads with `argv()`. Without the launcher, use `php bin/pyhp <command>`."],
    ["How do I create and use a PyHP package?",
     "Packages are folders with a `pyhp.json` manifest and an entry `.pyh` file:\n\n```bash\nmkdir mylib && cd mylib\npyhp pkg init                 # creates pyhp.json + src/mylib.pyh stub\n# ... write top-level functions/classes in src/mylib.pyh ...\npyhp pkg list                 # shows registry packages you can depend on\n```\n\nConsume it from another project:\n\n```bash\npyhp pkg add mylib            # copies into vendor/, records in pyhp.json\n```\n\n```c\nmylib = require(\"mylib\");\necho mylib.hello(\"world\");\n```\n\nThe registry search order is `./vendor/`, then `\$PYHP_REGISTRY`, then the `<PyHP install>/registry/` folder that ships with `strings`, `mathx` and `texttable`."],
    ["What does a PyHP module export?",
     "Everything defined at the **top level** of the module file:\n\n- named functions (`function f() {...}`)\n- top-level lambdas (`double = lambda n: n * 2;`)\n- classes, exposed as factories you call like functions (`m.Circle(5)`)\n\n`require(\"./lib.pyh\")` runs the file once in an isolated scope, caches the result per path, and returns the export table. Access members with dot syntax or indexing: `m.fn(x)`, `m.value`, `m[\"fn\"](x)`. Modules are isolated: their top-level variables do not leak into your program."],
    ["What are the differences between the interpreter and the transpiler?",
     "Both engines share the lexer, parser and value model, and the test suite asserts byte-identical output for every program. Practical differences:\n\n- `require()` must take a **literal string** in compiled mode (modules are inlined at build time); the interpreter also accepts computed specs.\n- Keep modules stateless: module-level variables are readable by module functions in the interpreter but not in compiled code.\n- The transpiler produces real PHP 8 you can deploy anywhere (it only needs `src/runtime.php`)."],
    ["How does the REPL work?",
     "`pyhp repl` starts an interactive shell. Bare expressions (no semicolon needed) echo their value:\n\n```\npyhp> [n ** 2 for n in range(5)]\n=> [0, 1, 4, 9, 16]\n```\n\nMulti-line statements buffer until braces balance. Commands: `:help`, `:vars` (dump globals), `:quit` / `:exit` / `:q` / Ctrl-D. Expressions and definitions persist across lines."],
];

foreach ($facts as [$q, $a]) {
    $qa[] = ['messages' => [
        ['role' => 'system', 'content' => 'You are an expert PyHP programmer. PyHP: PHP syntax (braces, semicolons, no $ variables), Python semantics, C muscles. Interpreter and transpiler produce byte-identical output.'],
        ['role' => 'user', 'content' => $q],
        ['role' => 'assistant', 'content' => $a],
    ]];
}

file_put_contents($outDir . '/pyhp-qa.jsonl',
    implode("\n", array_map(fn($r) => json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $qa)) . "\n");
echo "wrote ai/pyhp-qa.jsonl (" . count($qa) . " pairs, {$skipped} snippets skipped)\n";
if ($skipped > 0) { exit(1); }

/* ------------------------------------------------------------------ */
/* 3. code-generation tasks -> pyhp-code.jsonl                         */
/* ------------------------------------------------------------------ */

$tasks = [
    ["Write a PyHP function that returns the factorial of n.", 'function fact(n) {' . "\n" . '    if n <= 1 { return 1; }' . "\n" . '    return n * fact(n - 1);' . "\n" . '}' . "\n" . 'print(fact(6));', "720\n"],
    ["Write a PyHP function fizzbuzz(n) that prints numbers 1..n, replacing multiples of 3 with Fizz, of 5 with Buzz, of 15 with FizzBuzz.",
        'function fizzbuzz(n) {' . "\n" . '    for (i = 1; i <= n; i++) {' . "\n" . '        if i % 15 == 0 { echo "FizzBuzz"; }' . "\n" . '        elseif i % 3 == 0 { echo "Fizz"; }' . "\n" . '        elseif i % 5 == 0 { echo "Buzz"; }' . "\n" . '        else { echo i; }' . "\n" . '        echo " ";' . "\n" . '    }' . "\n" . '    print("");' . "\n" . '}' . "\n" . 'fizzbuzz(15);', "1 2 Fizz 4 Buzz Fizz 7 8 Fizz Buzz 11 Fizz 13 14 FizzBuzz \n"],
    ["Write a PyHP function that reverses a string without using slicing.",
        'function rev(s) {' . "\n" . '    out = "";' . "\n" . '    foreach c in s { out = c + out; }' . "\n" . '    return out;' . "\n" . '}' . "\n" . 'print(rev("PyHP"));', "PHyP\n"],
    ["Write a PyHP function isPrime(n) and use it to print primes below 30.",
        'function isPrime(n) {' . "\n" . '    if n < 2 { return false; }' . "\n" . '    d = 2;' . "\n" . '    while d * d <= n {' . "\n" . '        if n % d == 0 { return false; }' . "\n" . '        d++;' . "\n" . '    }' . "\n" . '    return true;' . "\n" . '}' . "\n" . 'print([n for n in range(30) if isPrime(n)]);', "[2, 3, 5, 7, 11, 13, 17, 19, 23, 29]\n"],
    ["Write a PyHP class Stack with push, pop and a size method.",
        'class Stack {' . "\n" . '    list items = [];' . "\n" . '    function push(x) { this.items.append(x); return this; }' . "\n" . '    function pop() { return this.items.pop(); }' . "\n" . '    function size() { return len(this.items); }' . "\n" . '}' . "\n" . 's = Stack();' . "\n" . 's.push(1); s.push(2); s.push(3);' . "\n" . 'print(s.pop(), s.size());', "3 2\n"],
    ["Write a PyHP function that counts word frequencies and returns them as a dict.",
        'function freq(s) {' . "\n" . '    counts = {};' . "\n" . '    foreach w in s.split() { counts[w] = counts.get(w, 0) + 1; }' . "\n" . '    return counts;' . "\n" . '}' . "\n" . 'print(freq("a b a c a b"));', "{'a': 3, 'b': 2, 'c': 1}\n"],
    ["Write a PyHP function that flattens a nested list (one level deep).",
        'function flatten(xs) {' . "\n" . '    out = [];' . "\n" . '    foreach x in xs {' . "\n" . '        if type(x) == "list" { out.extend(x); }' . "\n" . '        else { out.append(x); }' . "\n" . '    }' . "\n" . '    return out;' . "\n" . '}' . "\n" . 'print(flatten([1, [2, 3], 4, [5]]));', "[1, 2, 3, 4, 5]\n"],
    ["Use the C DNA: compute the parity bit (XOR of all bits) of a byte given as 0..255.",
        'function parity(byte) {' . "\n" . '    p = 0;' . "\n" . '    for (i = 0; i < 8; i++) {' . "\n" . '        p ^= (byte >> i) & 1;' . "\n" . '    }' . "\n" . '    return p;' . "\n" . '}' . "\n" . 'print(parity(0b1011), parity(0b1110));', "1 1\n"],
    ["Write a PyHP function toBase(n, b) converting a positive int to any base 2..16.",
        'function toBase(n, b) {' . "\n" . '    digits = "0123456789abcdef";' . "\n" . '    out = "";' . "\n" . '    while n > 0 {' . "\n" . '        out = digits[n % b] + out;' . "\n" . '        n = int(n / b);' . "\n" . '    }' . "\n" . '    return out == "" ? "0" : out;' . "\n" . '}' . "\n" . 'print(toBase(255, 16), toBase(10, 2), toBase(0, 8));', "ff 1010 0\n"],
    ["Write a PyHP function matrixMultiply for 2x2 matrices represented as lists.",
        'function mat2mul(a, b) {' . "\n" . '    return [' . "\n" . '        [a[0][0] * b[0][0] + a[0][1] * b[1][0], a[0][0] * b[0][1] + a[0][1] * b[1][1]],' . "\n" . '        [a[1][0] * b[0][0] + a[1][1] * b[1][0], a[1][0] * b[0][1] + a[1][1] * b[1][1]],' . "\n" . '    ];' . "\n" . '}' . "\n" . 'print(mat2mul([[1, 2], [3, 4]], [[5, 6], [7, 8]]));', "[[19, 22], [43, 50]]\n"],
    ["Write a PyHP function that merges two dicts, summing values for shared keys.",
        'function mergeSum(a, b) {' . "\n" . '    out = a.copy();' . "\n" . '    foreach k, v in b { out[k] = out.get(k, 0) + v; }' . "\n" . '    return out;' . "\n" . '}' . "\n" . 'print(mergeSum({"x": 1, "y": 2}, {"y": 3, "z": 4}));', "{'x': 1, 'y': 5, 'z': 4}\n"],
    ["Write a PyHP function chunk(xs, n) splitting a list into chunks of size n.",
        'function chunk(xs, n) {' . "\n" . '    out = [];' . "\n" . '    i = 0;' . "\n" . '    while i < len(xs) {' . "\n" . '        out.append(xs[i:i + n]);' . "\n" . '        i += n;' . "\n" . '    }' . "\n" . '    return out;' . "\n" . '}' . "\n" . 'print(chunk([1, 2, 3, 4, 5], 2));', "[[1, 2], [3, 4], [5]]\n"],
    ["Write a PyHP one-liner that builds a lookup dict from a list of names to their lengths.",
        'names = ["ada", "grace", "linus"];' . "\n" . 'print({n: len(n) for n in names});', "{'ada': 3, 'grace': 5, 'linus': 5}\n"],
    ["Write a PyHP function debounce simulation: keep only the last of consecutive duplicate values.",
        'function dedupAdjacent(xs) {' . "\n" . '    out = [];' . "\n" . '    foreach x in xs {' . "\n" . '        if len(out) == 0 or out[-1] != x { out.append(x); }' . "\n" . '    }' . "\n" . '    return out;' . "\n" . '}' . "\n" . 'print(dedupAdjacent([1, 1, 2, 2, 2, 3, 1, 1]));', "[1, 2, 3, 1]\n"],
    ["Write a PyHP function readInt(prompt) that keeps asking until the input is a valid integer.",
        'function readInt(prompt) {' . "\n" . '    while true {' . "\n" . '        line = input(prompt);' . "\n" . '        v = int(line);' . "\n" . '        if str(v) == line.strip() { return v; }' . "\n" . '        print("not an integer, try again");' . "\n" . '    }' . "\n" . '}' . "\n" . 'print("would prompt here");', "would prompt here\n"],
    ["Write a PyHP function that uses the arena to build a byte buffer holding a string, then reads it back.",
        'a = malloc_arena();' . "\n" . 'b = a.alloc(5);' . "\n" . 'b.write("PyHP!");' . "\n" . 'out = "";' . "\n" . 'for (i = 0; i < b.size(); i++) { out += chr(b[i]); }' . "\n" . 'print(out);' . "\n" . 'a.free();', "PyHP!\n"],
    ["Write a PyHP generator-style function using a closure that yields fibonacci numbers on each call.",
        'function fibNext() {' . "\n" . '    a = 0; b = 1;' . "\n" . '    return lambda: (t = a + b);' . "\n" . '}' . "\n" . '// closures capture by name; use explicit state instead:',
        null],
    ["Write a PyHP fibonacci closure pair that returns [next, reset] operating on shared state.",
        'function makeFib() {' . "\n" . '    state = {"a": 0, "b": 1};' . "\n" . '    next = lambda: (state["a"]);' . "\n" . '    return next;' . "\n" . '}' . "\n" . 'print("see simpler version below");', null],
    ["Write a simple PyHP fibonacci that prints the first 10 numbers.",
        'a = 0;' . "\n" . 'b = 1;' . "\n" . 'for (i = 0; i < 10; i++) {' . "\n" . '    echo a, " ";' . "\n" . '    t = a + b;' . "\n" . '    a = b;' . "\n" . '    b = t;' . "\n" . '}' . "\n" . 'print("");', "0 1 1 2 3 5 8 13 21 34 \n"],
    ["Write a PyHP function sortByField(rows, field) that selection-sorts a list of dicts by a field.",
        'function sortByField(rows, field) {' . "\n" . '    out = rows.copy();' . "\n" . '    i = 0;' . "\n" . '    while i < len(out) {' . "\n" . '        best = i;' . "\n" . '        j = i + 1;' . "\n" . '        while j < len(out) {' . "\n" . '            if out[j][field] < out[best][field] { best = j; }' . "\n" . '            j++;' . "\n" . '        }' . "\n" . '        if best != i { t = out[i]; out[i] = out[best]; out[best] = t; }' . "\n" . '        i++;' . "\n" . '    }' . "\n" . '    return out;' . "\n" . '}' . "\n" . 'rows = [{"n": "a", "score": 3}, {"n": "b", "score": 1}, {"n": "c", "score": 2}];' . "\n" . 'print([r["n"] for r in sortByField(rows, "score")]);', "['b', 'c', 'a']\n"],
    ["Write a PyHP function piglatin(word): move the first letter to the end and add 'ay'.",
        'function piglatin(w) {' . "\n" . '    if len(w) == 0 { return w; }' . "\n" . '    return w[1:] + w[0] + "ay";' . "\n" . '}' . "\n" . 'print(piglatin("hello"), piglatin("pyhp"));', "ellohay yhppay\n"],
    ["Write a PyHP function that validates a password: at least 8 chars, contains a digit and a letter.",
        'function validPassword(p) {' . "\n" . '    if len(p) < 8 { return false; }' . "\n" . '    hasDigit = false; hasAlpha = false;' . "\n" . '    foreach c in p {' . "\n" . '        if c >= "0" and c <= "9" { hasDigit = true; }' . "\n" . '        elseif c.lower() != c.upper() { hasAlpha = true; }' . "\n" . '    }' . "\n" . '    return hasDigit and hasAlpha;' . "\n" . '}' . "\n" . 'print(validPassword("abc12345"), validPassword("abcdefgh"), validPassword("12345678"));', "true false false\n"],
    ["Write a PyHP function roman(n) converting 1..3999 to Roman numerals.",
        'function roman(n) {' . "\n" . '    vals = [1000, 900, 500, 400, 100, 90, 50, 40, 10, 9, 5, 4, 1];' . "\n" . '    syms = ["M", "CM", "D", "CD", "C", "XC", "L", "XL", "X", "IX", "V", "IV", "I"];' . "\n" . '    out = "";' . "\n" . '    i = 0;' . "\n" . '    while n > 0 {' . "\n" . '        while n >= vals[i] { out += syms[i]; n -= vals[i]; }' . "\n" . '        i++;' . "\n" . '    }' . "\n" . '    return out;' . "\n" . '}' . "\n" . 'print(roman(2026), roman(9), roman(44));', "MMXXVI IX XLIV\n"],
    ["Write a PyHP function transpose(matrix) using comprehensions.",
        'function transpose(m) {' . "\n" . '    cols = len(m[0]);' . "\n" . '    return [[row[c] for row in m] for c in range(cols)];' . "\n" . '}' . "\n" . 'print(transpose([[1, 2, 3], [4, 5, 6]]));', "[[1, 4], [2, 5], [3, 6]]\n"],
    ["Write a PyHP function caesar(text, shift) applying a Caesar cipher to letters only.",
        'function caesar(text, shift) {' . "\n" . '    out = "";' . "\n" . '    foreach c in text {' . "\n" . '        if c.lower() != c.upper() {' . "\n" . '            base = c == c.upper() ? 65 : 97;' . "\n" . '            out += chr((ord(c) - base + shift) % 26 + base);' . "\n" . '        } else {' . "\n" . '            out += c;' . "\n" . '        }' . "\n" . '    }' . "\n" . '    return out;' . "\n" . '}' . "\n" . 'print(caesar("Hello, PyHP!", 3));', "Khoor, SbKS!\n"],
    ["Write a PyHP function memoAdd using a dict cache to memoize an expensive add(a, b).",
        'cache = {};' . "\n" . 'function memoAdd(a, b) {' . "\n" . '    key = str(a) + ":" + str(b);' . "\n" . '    if !cache.has(key) { cache[key] = a + b; }' . "\n" . '    return cache[key];' . "\n" . '}' . "\n" . 'print(memoAdd(2, 3), memoAdd(2, 3), len(cache));', "5 5 1\n"],
    ["Write a PyHP function mode(xs) returning the most frequent value.",
        'function mode(xs) {' . "\n" . '    counts = {};' . "\n" . '    foreach x in xs { counts[x] = counts.get(x, 0) + 1; }' . "\n" . '    best = null; bestN = 0;' . "\n" . '    foreach k, v in counts {' . "\n" . '        if v > bestN { best = k; bestN = v; }' . "\n" . '    }' . "\n" . '    return best;' . "\n" . '}' . "\n" . 'print(mode([1, 2, 2, 3, 2]), mode(["a", "b", "a"]));', "2 a\n"],
    ["Write a PyHP program that reads a file of numbers (one per line) and prints their sum.",
        'file_write("/tmp/pyhp_nums.txt", "1\\n2\\n3\\n4\\n");' . "\n" . 'total = sum([int(line) for line in file_read("/tmp/pyhp_nums.txt").strip().split("\\n")]);' . "\n" . 'print(total);', "10\n"],
];

$code = [];
foreach ($tasks as [$instruction, $solution, $expectedOut]) {
    if ($expectedOut === null) { continue; }   // skipped drafts
    $out = pyhp_ai_run($solution);
    if ($out !== $expectedOut) {
        fwrite(STDERR, "TASK OUTPUT MISMATCH: {$instruction}\n  got:      " . json_encode($out) . "\n  expected: " . json_encode($expectedOut) . "\n");
        exit(1);
    }
    $code[] = [
        'instruction' => $instruction,
        'output' => $solution,
        'verifiedOutput' => rtrim($expectedOut, "\n"),
    ];
}

file_put_contents($outDir . '/pyhp-code.jsonl',
    implode("\n", array_map(fn($r) => json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $code)) . "\n");
echo "wrote ai/pyhp-code.jsonl (" . count($code) . " tasks, all executed & verified)\n";
