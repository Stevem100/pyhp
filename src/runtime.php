<?php
/**
 * PyHP runtime — shared value-level foundation.
 *
 * This file is required by:
 *   1. the PyHP tree-walking interpreter (bin/pyhp run / repl), and
 *   2. every PHP file produced by the PyHP -> PHP transpiler (bin/pyhp build).
 *
 * It defines PyHP's data model (PyList / PyDict objects so lists and dicts
 * alias like Python), PyHP's error classes, the deterministic memory arena
 * (the "C DNA"), all builtin functions, and the value helpers that both
 * execution paths share.
 */

if (defined('PYHP_RUNTIME')) { return; }
define('PYHP_RUNTIME', '1');

const PYHP_VERSION = '0.1.0';

/* =========================================================================
 * 1. Errors
 * ========================================================================= */

class PyHPError extends Exception {
    public $message = '';
    public string $pyhpType = 'Exception';
    public function __construct($m = '') {
        parent::__construct((string)$m);
        $this->message = (string)$m;
    }
}
class PyHPTypeError extends PyHPError        { public string $pyhpType = 'TypeError'; }
class PyHPValueError extends PyHPError       { public string $pyhpType = 'ValueError'; }
class PyHPKeyError extends PyHPError         { public string $pyhpType = 'KeyError'; }
class PyHPIndexError extends PyHPError       { public string $pyhpType = 'IndexError'; }
class PyHPZeroDivisionError extends PyHPError{ public string $pyhpType = 'ZeroDivisionError'; }
class PyHPMemError extends PyHPError         { public string $pyhpType = 'MemError'; }
class PyHPRuntimeError extends PyHPError     { public string $pyhpType = 'RuntimeError'; }
class PyHPSyntaxError extends PyHPError      { public string $pyhpType = 'SyntaxError'; }

/** PyHP builtin error name -> PHP class (used by transpiler + isinstance). */
function pyhp_error_class_map(): array {
    return [
        'Exception'         => 'PyHPError',
        'TypeError'         => 'PyHPTypeError',
        'ValueError'        => 'PyHPValueError',
        'KeyError'          => 'PyHPKeyError',
        'IndexError'        => 'PyHPIndexError',
        'ZeroDivisionError' => 'PyHPZeroDivisionError',
        'MemError'          => 'PyHPMemError',
        'RuntimeError'      => 'PyHPRuntimeError',
    ];
}

/* =========================================================================
 * 2. Data model: lists & dicts alias like Python (objects, not PHP arrays)
 * ========================================================================= */

class PyList implements IteratorAggregate {
    public array $a = [];
    public function __construct(array $a = []) { $this->a = array_values($a); }
    public function getIterator(): Traversable { return new ArrayIterator($this->a); }
    /* list methods (Python DNA) */
    public function append($x): void { $this->a[] = $x; }
    public function pop($i = -1) {
        $n = count($this->a);
        if ($n === 0) throw new PyHPIndexError('pop from empty list');
        $i = (int)$i;
        if ($i < 0) $i += $n;
        if ($i < 0 || $i >= $n) throw new PyHPIndexError('pop index out of range');
        $v = $this->a[$i];
        array_splice($this->a, $i, 1);
        return $v;
    }
    public function extend($other): void {
        foreach (pyhp_iter_values($other) as $v) { $this->a[] = $v; }
    }
    public function insert($i, $x): void {
        $n = count($this->a);
        $i = (int)$i;
        if ($i < 0) $i = max(0, $n + $i);
        if ($i > $n) $i = $n;
        array_splice($this->a, $i, 0, [$x]);
    }
    public function remove($v): void {
        foreach ($this->a as $k => $x) {
            if (pyhp_eq($x, $v)) { array_splice($this->a, $k, 1); return; }
        }
        throw new PyHPValueError('list.remove(x): x not in list');
    }
    public function index($v) {
        foreach ($this->a as $k => $x) { if (pyhp_eq($x, $v)) return $k; }
        throw new PyHPValueError('value not in list');
    }
    public function count($v): int {
        $c = 0;
        foreach ($this->a as $x) { if (pyhp_eq($x, $v)) $c++; }
        return $c;
    }
    public function sort(): void { usort($this->a, fn($x, $y) => pyhp_compare($x, $y)); }
    public function reverse(): void { $this->a = array_reverse($this->a); }
    public function clear(): void { $this->a = []; }
    public function copy(): PyList { return new PyList($this->a); }
}

class PyDict implements IteratorAggregate {
    public array $d = [];
    public function __construct(array $d = []) { $this->d = $d; }
    public function getIterator(): Traversable { return new ArrayIterator($this->d); }
    /* dict methods (Python DNA) */
    public function keys(): PyList { return new PyList(array_keys($this->d)); }
    public function values(): PyList { return new PyList(array_values($this->d)); }
    public function items(): PyList {
        $out = [];
        foreach ($this->d as $k => $v) { $out[] = new PyList([$k, $v]); }
        return new PyList($out);
    }
    public function get($k, $def = null) { return array_key_exists($k, $this->d) ? $this->d[$k] : $def; }
    public function has($k): bool { return array_key_exists($k, $this->d); }
    public function remove($k) {
        if (!array_key_exists($k, $this->d)) {
            throw new PyHPKeyError('key not found: ' . pyhp_repr($k));
        }
        $v = $this->d[$k];
        unset($this->d[$k]);
        return $v;
    }
    public function clear(): void { $this->d = []; }
    public function copy(): PyDict { return new PyDict($this->d); }
    public function merge($other): void {
        if (!($other instanceof PyDict)) {
            throw new PyHPTypeError('dict.merge() expects a dict, got ' . pyhp_type_name($other));
        }
        $this->d = array_replace($this->d, $other->d);
    }
}

/* =========================================================================
 * 3. Interpreter runtime shells (used only by the tree-walking interpreter)
 * ========================================================================= */

class PyHPEnv {
    public array $vars = [];
    public array $types = [];
    public ?PyHPEnv $parent;
    public function __construct(?PyHPEnv $parent = null) { $this->parent = $parent; }
    public function has(string $n): bool {
        for ($e = $this; $e !== null; $e = $e->parent) { if (array_key_exists($n, $e->vars)) return true; }
        return false;
    }
    public function get(string $n) {
        for ($e = $this; $e !== null; $e = $e->parent) {
            if (array_key_exists($n, $e->vars)) return $e->vars[$n];
        }
        throw new PyHPError("undefined variable '$n'");
    }
    public function declare(string $n, $v, ?string $type = null, bool $raw = false): void {
        if ($type !== null && !$raw) $v = pyhp_check_type($v, $type, $n);
        $this->vars[$n] = $v;
        if ($type !== null && $type !== 'var') $this->types[$n] = $type; else unset($this->types[$n]);
    }
    /** Assign with Python-like semantics: updates nearest scope holding $n,
     *  otherwise declares locally. Enforces declared types. */
    public function assign(string $n, $v): void {
        for ($e = $this; $e !== null; $e = $e->parent) {
            if (array_key_exists($n, $e->vars)) {
                if (isset($e->types[$n])) $v = pyhp_check_type($v, $e->types[$n], $n);
                $e->vars[$n] = $v;
                return;
            }
        }
        $this->vars[$n] = $v;
    }
}

class InterpFunc {
    public string $name = '<lambda>';
    public array $params = [];
    public array $body = [];
    public ?PyHPEnv $env = null;
    public $interp = null;
    public ?ClassDef $cls = null;
    public ?string $ret = null;
    public $native = null; // closure($env, $args) for builtin methods (e.g. Exception ctor)
    public function __invoke(...$args) {
        return $this->interp->invokeFunc($this, $args);
    }
}

class ClassDef {
    public string $name = '';
    public ?ClassDef $parent = null;
    public array $methods = [];    // name => InterpFunc
    public array $propStmts = [];  // AST VarDecl statements for instance property defaults
    public bool $isBuiltinError = false;
}

class InterpInstance {
    public ClassDef $cls;
    public array $props = [];
    public array $propTypes = [];
    public function __construct(ClassDef $cls) { $this->cls = $cls; }
}

/* Signals used to unwind the interpreter */
class PyHPReturn extends Exception    { public $value; public function __construct($v) { $this->value = $v; } }
class PyHPBreak extends Exception     {}
class PyHPContinue extends Exception  {}
class PyHPThrow extends Exception     { public $value; public function __construct($v) { $this->value = $v; } }
class PyHPExit extends Exception      { public int $exitCode; public function __construct(int $c) { $this->exitCode = $c; } }

/* =========================================================================
 * 4. The "C DNA": deterministic memory arena + byte buffers
 * ========================================================================= */

class PyHPBuffer {
    private string $bytes = '';
    private int $size = 0;
    private bool $freed = false;
    private ?PyHPArena $arena = null;
    public function __construct(int $n, PyHPArena $arena) {
        $this->size = $n;
        $this->bytes = str_repeat("\x00", $n);
        $this->arena = $arena;
    }
    private function guard(): void {
        if ($this->freed) throw new PyHPMemError('use after free: buffer was released by arena.free()');
    }
    public function get(int $i): int {
        $this->guard();
        if ($i < 0) $i += $this->size;
        if ($i < 0 || $i >= $this->size) throw new PyHPMemError("buffer index {$i} out of bounds (size {$this->size})");
        return ord($this->bytes[$i]);
    }
    public function set(int $i, int $v): void {
        $this->guard();
        if ($v < 0 || $v > 255) throw new PyHPValueError('buffer byte value must be 0..255, got ' . $v);
        if ($i < 0) $i += $this->size;
        if ($i < 0 || $i >= $this->size) throw new PyHPMemError("buffer index {$i} out of bounds (size {$this->size})");
        $this->bytes[$i] = chr($v);
    }
    public function write(string $s, int $at = 0): void {
        $this->guard();
        if ($at + strlen($s) > $this->size) {
            throw new PyHPMemError('buffer write of ' . strlen($s) . " bytes at offset {$at} overflows size {$this->size}");
        }
        for ($k = 0; $k < strlen($s); $k++) { $this->bytes[$at + $k] = $s[$k]; }
    }
    public function fill(int $v): void {
        $this->guard();
        if ($v < 0 || $v > 255) throw new PyHPValueError('buffer byte value must be 0..255, got ' . $v);
        $this->bytes = str_repeat(chr($v), $this->size);
    }
    public function to_string(): string { $this->guard(); return $this->bytes; }
    public function size(): int { $this->guard(); return $this->size; }
    public function markFreed(): void { $this->freed = true; }
    public function isFreed(): bool { return $this->freed; }
}

class PyHPArena {
    private array $buffers = [];
    private bool $freed = false;
    private int $used = 0;
    public function alloc(int $n): PyHPBuffer {
        if ($this->freed) throw new PyHPMemError('alloc from freed arena');
        if ($n < 0) throw new PyHPValueError('cannot allocate a negative number of bytes: ' . $n);
        $b = new PyHPBuffer($n, $this);
        $this->buffers[] = $b;
        $this->used += $n;
        return $b;
    }
    public function free(): void {
        if ($this->freed) throw new PyHPMemError('double free: arena.free() called twice');
        foreach ($this->buffers as $b) { $b->markFreed(); }
        $this->buffers = [];
        $this->freed = true;
    }
    public function used(): int { return $this->used; }
}

/* =========================================================================
 * 5. Type / truthiness / str / repr
 * ========================================================================= */

function pyhp_type_name($v): string {
    if ($v === null) return 'null';
    if (is_bool($v)) return 'bool';
    if (is_int($v)) return 'int';
    if (is_float($v)) return 'float';
    if (is_string($v)) return 'string';
    if ($v instanceof PyList) return 'list';
    if ($v instanceof PyDict) return 'dict';
    if ($v instanceof PyHPBuffer) return 'buffer';
    if ($v instanceof PyHPArena) return 'arena';
    if ($v instanceof InterpFunc) return 'function';
    if ($v instanceof Closure) return 'function';
    if ($v instanceof PyHPError) return $v->pyhpType;
    if ($v instanceof InterpInstance) return $v->cls->name;
    if (is_object($v)) {
        $c = get_class($v);
        if (str_starts_with($c, 'PyHP_')) return substr($c, 5);
        return $c;
    }
    return 'unknown';
}

function pyhp_truthy($v): bool {
    if ($v === null) return false;
    if (is_bool($v)) return $v;
    if (is_int($v) || is_float($v)) return $v != 0;
    if (is_string($v)) return $v !== '';
    if ($v instanceof PyList) return count($v->a) > 0;
    if ($v instanceof PyDict) return count($v->d) > 0;
    return true;
}

function pyhp_num_str($v): string {
    if (is_int($v)) return (string)$v;
    if (is_infinite($v)) return $v > 0 ? 'inf' : '-inf';
    if (is_nan($v)) return 'nan';
    $r = (string)$v;                       // PHP: 2.5 -> "2.5", 2.0 -> "2"
    if (strpos($r, '.') === false && stripos($r, 'E') === false) return $r . '.0';
    return $r;
}

function pyhp_repr_str(string $s): string {
    $out = "'";
    $map = ["\\" => '\\\\', "'" => "\\'", "\n" => '\\n', "\t" => '\\t', "\r" => '\\r', "\0" => '\\0'];
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $ch = $s[$i];
        $out .= $map[$ch] ?? $ch;
    }
    return $out . "'";
}

function pyhp_repr($v): string {
    if (is_string($v)) return pyhp_repr_str($v);
    return pyhp_tostr($v);
}

function pyhp_tostr($v): string {
    if ($v === null) return 'null';
    if (is_bool($v)) return $v ? 'true' : 'false';
    if (is_int($v)) return (string)$v;
    if (is_float($v)) return pyhp_num_str($v);
    if (is_string($v)) return $v;
    if ($v instanceof PyList) {
        $parts = array_map(fn($x) => pyhp_repr($x), $v->a);
        return '[' . implode(', ', $parts) . ']';
    }
    if ($v instanceof PyDict) {
        $parts = [];
        foreach ($v->d as $k => $x) { $parts[] = pyhp_repr($k) . ': ' . pyhp_repr($x); }
        return '{' . implode(', ', $parts) . '}';
    }
    if ($v instanceof PyHPBuffer) return "<buffer {$v->size()}b>";
    if ($v instanceof PyHPArena) return '<arena>';
    if ($v instanceof PyHPError) return $v->message;
    if ($v instanceof InterpFunc) return "<function {$v->name}>";
    if ($v instanceof InterpInstance) {
        $hook = $GLOBALS['__pyhp_str_hook'] ?? null;
        if ($hook !== null) {
            $s = $hook($v);
            if ($s !== null) return $s;
        }
        return "<{$v->cls->name} object>";
    }
    if ($v instanceof Closure) return '<function>';
    if (is_object($v)) {
        if (method_exists($v, '__toString')) return (string)$v;
        return '<' . pyhp_type_name($v) . ' object>';
    }
    return (string)$v;
}

/* =========================================================================
 * 6. Arithmetic / comparison operators
 * ========================================================================= */

function pyhp_is_num($v): bool { return is_int($v) || is_float($v) || is_bool($v); }

function pyhp_num($v) { return is_bool($v) ? (int)$v : $v; }

function pyhp_add($a, $b) {
    if (pyhp_is_num($a) && pyhp_is_num($b)) return pyhp_num($a) + pyhp_num($b);
    if (is_string($a) || is_string($b)) return pyhp_tostr($a) . pyhp_tostr($b);
    if ($a instanceof PyList && $b instanceof PyList) return new PyList(array_merge($a->a, $b->a));
    if ($a instanceof PyDict && $b instanceof PyDict) return new PyDict(array_replace($a->d, $b->d));
    throw new PyHPTypeError("unsupported operand type(s) for +: '" . pyhp_type_name($a) . "' and '" . pyhp_type_name($b) . "'");
}
function pyhp_sub($a, $b) {
    if (pyhp_is_num($a) && pyhp_is_num($b)) return pyhp_num($a) - pyhp_num($b);
    throw new PyHPTypeError("unsupported operand type(s) for -: '" . pyhp_type_name($a) . "' and '" . pyhp_type_name($b) . "'");
}
function pyhp_mul($a, $b) {
    if (pyhp_is_num($a) && pyhp_is_num($b)) return pyhp_num($a) * pyhp_num($b);
    if (is_string($a) && pyhp_is_num($b)) return str_repeat($a, (int)pyhp_num($b));
    if (pyhp_is_num($a) && is_string($b)) return str_repeat($b, (int)pyhp_num($a));
    if ($a instanceof PyList && pyhp_is_num($b)) {
        $n = (int)pyhp_num($b); $out = [];
        for ($i = 0; $i < $n; $i++) $out = array_merge($out, $a->a);
        return new PyList($out);
    }
    if (pyhp_is_num($a) && $b instanceof PyList) return pyhp_mul($b, $a);
    throw new PyHPTypeError("unsupported operand type(s) for *: '" . pyhp_type_name($a) . "' and '" . pyhp_type_name($b) . "'");
}
function pyhp_div($a, $b) {
    if (pyhp_is_num($a) && pyhp_is_num($b)) {
        $x = pyhp_num($a); $y = pyhp_num($b);
        if ($y == 0) throw new PyHPZeroDivisionError('division by zero');
        return $x / $y;
    }
    throw new PyHPTypeError("unsupported operand type(s) for /: '" . pyhp_type_name($a) . "' and '" . pyhp_type_name($b) . "'");
}
function pyhp_mod($a, $b) {
    if (pyhp_is_num($a) && pyhp_is_num($b)) {
        $x = pyhp_num($a); $y = pyhp_num($b);
        if ($y == 0) throw new PyHPZeroDivisionError('modulo by zero');
        $r = $x - $y * floor($x / $y);           // Python-style sign semantics
        if (is_int($x) && is_int($y)) return (int)$r;
        return $r;
    }
    throw new PyHPTypeError("unsupported operand type(s) for %: '" . pyhp_type_name($a) . "' and '" . pyhp_type_name($b) . "'");
}
function pyhp_pow($a, $b) {
    if (pyhp_is_num($a) && pyhp_is_num($b)) return pyhp_num($a) ** pyhp_num($b);
    throw new PyHPTypeError("unsupported operand type(s) for **: '" . pyhp_type_name($a) . "' and '" . pyhp_type_name($b) . "'");
}
function pyhp_bitcheck($a, $op) {
    $a = pyhp_num($a);
    if (!is_int($a)) {
        throw new PyHPTypeError("bitwise {$op} requires int operands, got '" . pyhp_type_name($a) . "'");
    }
    return $a;
}
function pyhp_bitand($a, $b) { return pyhp_bitcheck($a, 'AND (&)') & pyhp_bitcheck($b, 'AND (&)'); }
function pyhp_bitor($a, $b)  { return pyhp_bitcheck($a, 'OR (|)') | pyhp_bitcheck($b, 'OR (|)'); }
function pyhp_bitxor($a, $b) { return pyhp_bitcheck($a, 'XOR (^)') ^ pyhp_bitcheck($b, 'XOR (^)'); }
function pyhp_lshift($a, $b) {
    $x = pyhp_bitcheck($a, 'shift (<<)'); $n = pyhp_bitcheck($b, 'shift (<<)');
    if ($n < 0) throw new PyHPValueError('negative shift count');
    return $x << $n;
}
function pyhp_rshift($a, $b) {
    $x = pyhp_bitcheck($a, 'shift (>>)'); $n = pyhp_bitcheck($b, 'shift (>>)');
    if ($n < 0) throw new PyHPValueError('negative shift count');
    return $x >> $n;
}
function pyhp_bitnot($a) { return ~pyhp_bitcheck($a, 'NOT (~)'); }
function pyhp_neg($a) {
    if (pyhp_is_num($a)) return -pyhp_num($a);
    throw new PyHPTypeError("bad operand type for unary -: '" . pyhp_type_name($a) . "'");
}
function pyhp_not($a): bool { return !pyhp_truthy($a); }

function pyhp_eq($a, $b): bool {
    if ($a instanceof PyList && $b instanceof PyList) {
        if (count($a->a) !== count($b->a)) return false;
        foreach ($a->a as $i => $x) { if (!pyhp_eq($x, $b->a[$i])) return false; }
        return true;
    }
    if ($a instanceof PyDict && $b instanceof PyDict) {
        if (count($a->d) !== count($b->d)) return false;
        foreach ($a->d as $k => $x) {
            if (!array_key_exists($k, $b->d) || !pyhp_eq($x, $b->d[$k])) return false;
        }
        return true;
    }
    if (is_object($a) || is_object($b)) return $a === $b;
    if (is_bool($a) || is_bool($b)) {
        if (pyhp_is_num($a) && pyhp_is_num($b)) return pyhp_num($a) == pyhp_num($b);
        return false;
    }
    if (pyhp_is_num($a) && pyhp_is_num($b)) return pyhp_num($a) == pyhp_num($b);
    if (is_string($a) && is_string($b)) return $a === $b;
    if ($a === null && $b === null) return true;
    return false;
}

function pyhp_compare($a, $b): int {
    if (is_bool($a)) $a = (int)$a;
    if (is_bool($b)) $b = (int)$b;
    if (pyhp_is_num($a) && pyhp_is_num($b)) return pyhp_num($a) <=> pyhp_num($b);
    if (is_string($a) && is_string($b)) return strcmp($a, $b);
    if ($a instanceof PyList && $b instanceof PyList) {
        $n = min(count($a->a), count($b->a));
        for ($i = 0; $i < $n; $i++) {
            $c = pyhp_compare($a->a[$i], $b->a[$i]);
            if ($c !== 0) return $c;
        }
        return count($a->a) <=> count($b->a);
    }
    throw new PyHPTypeError("'<' not supported between instances of '" . pyhp_type_name($a) . "' and '" . pyhp_type_name($b) . "'");
}

function pyhp_in($needle, $hay): bool {
    if ($hay instanceof PyList) {
        foreach ($hay->a as $x) { if (pyhp_eq($x, $needle)) return true; }
        return false;
    }
    if ($hay instanceof PyDict) {
        foreach ($hay->d as $k => $_) { if (pyhp_eq($k, $needle)) return true; }
        return false;
    }
    if (is_string($hay)) {
        if (!is_string($needle)) throw new PyHPTypeError("'in <string>' requires a string needle, got " . pyhp_type_name($needle));
        return $needle === '' || mb_strpos($hay, $needle) !== false;
    }
    throw new PyHPTypeError("'in' requires a list, dict or string on the right side, got " . pyhp_type_name($hay));
}

/* =========================================================================
 * 7. Indexing, slicing, iteration
 * ========================================================================= */

function pyhp_chars(string $s): array {
    if (function_exists('mb_str_split')) {
        $r = mb_str_split($s);
        return $r === false ? str_split($s) : $r;
    }
    return str_split($s);
}

function pyhp_iter_values($v): array {
    if ($v instanceof PyList) return $v->a;
    if ($v instanceof PyDict) return array_keys($v->d);
    if (is_string($v)) return pyhp_chars($v);
    throw new PyHPTypeError("'" . pyhp_type_name($v) . "' object is not iterable");
}

/** Normalize a value for foreach: returns [key => value] map. */
function pyhp_foreach($v): array {
    if ($v instanceof PyList) return array_values($v->a);
    if ($v instanceof PyDict) return $v->d;
    if (is_string($v)) return pyhp_chars($v);
    throw new PyHPTypeError("'" . pyhp_type_name($v) . "' object is not iterable");
}

function pyhp_guard_key($k) {
    if (is_int($k) || is_string($k)) return $k;
    throw new PyHPTypeError('dict keys must be int or string, got ' . pyhp_type_name($k));
}

function pyhp_get($c, $i) {
    if ($c instanceof PyList) {
        $n = count($c->a);
        if (!is_int($i)) throw new PyHPTypeError('list indices must be int, got ' . pyhp_type_name($i));
        $j = $i < 0 ? $n + $i : $i;
        if ($j < 0 || $j >= $n) throw new PyHPIndexError("list index {$i} out of range (length {$n})");
        return $c->a[$j];
    }
    if ($c instanceof PyDict) {
        $i = pyhp_guard_key($i);
        if (!array_key_exists($i, $c->d)) throw new PyHPKeyError('key not found: ' . pyhp_repr($i));
        return $c->d[$i];
    }
    if ($c instanceof PyHPBuffer) {
        if (!is_int($i)) throw new PyHPTypeError('buffer indices must be int, got ' . pyhp_type_name($i));
        return $c->get($i);
    }
    if (is_string($c)) {
        $n = mb_strlen($c);
        if (!is_int($i)) throw new PyHPTypeError('string indices must be int, got ' . pyhp_type_name($i));
        $j = $i < 0 ? $n + $i : $i;
        if ($j < 0 || $j >= $n) throw new PyHPIndexError("string index {$i} out of range (length {$n})");
        return mb_substr($c, $j, 1);
    }
    throw new PyHPTypeError("'" . pyhp_type_name($c) . "' object is not subscriptable");
}

function pyhp_set($c, $i, $v): void {
    if ($c instanceof PyHPBuffer) {
        if (!is_int($i)) throw new PyHPTypeError('buffer indices must be int, got ' . pyhp_type_name($i));
        if (!is_int($v)) throw new PyHPTypeError('buffer byte values must be int, got ' . pyhp_type_name($v));
        $c->set($i, $v);
        return;
    }
    if ($c instanceof PyList) {
        $n = count($c->a);
        if (!is_int($i)) throw new PyHPTypeError('list indices must be int, got ' . pyhp_type_name($i));
        $j = $i < 0 ? $n + $i : $i;
        if ($j < 0 || $j >= $n) throw new PyHPIndexError("list assignment index {$i} out of range (length {$n})");
        $c->a[$j] = $v;
        return;
    }
    if ($c instanceof PyDict) {
        $i = pyhp_guard_key($i);
        $c->d[$i] = $v;
        return;
    }
    if (is_string($c)) throw new PyHPTypeError('strings are immutable; build a new string instead');
    throw new PyHPTypeError("'" . pyhp_type_name($c) . "' object does not support item assignment");
}

/** Python-style slicing with optional step (list or string). */
function pyhp_slice($c, $start, $stop, $step) {
    if ($c instanceof PyList)      { return new PyList(pyhp_slice_arr($c->a, $start, $stop, $step)); }
    if (is_string($c))             { return implode('', pyhp_slice_arr(pyhp_chars($c), $start, $stop, $step)); }
    throw new PyHPTypeError("'" . pyhp_type_name($c) . "' object is not sliceable");
}

function pyhp_slice_arr(array $a, $start, $stop, $step): array {
    $n = count($a);
    $step = $step === null ? 1 : (int)$step;
    if ($step === 0) throw new PyHPValueError('slice step cannot be zero');
    if ($step > 0) {
        $s = $start === null ? 0 : ($start < 0 ? max(0, $n + $start) : min($start, $n));
        $e = $stop === null ? $n : ($stop < 0 ? max(0, $n + $stop) : min($stop, $n));
        $out = [];
        for ($i = $s; $i < $e; $i += $step) $out[] = $a[$i];
        return $out;
    }
    // negative step: walk backwards
    $s = $start === null ? $n - 1 : ($start < 0 ? $n + $start : min($start, $n - 1));
    $e = $stop === null ? -1 : ($stop < 0 ? $n + $stop : $stop);
    $out = [];
    for ($i = $s; $i > $e; $i += $step) { if ($i >= 0 && $i < $n) $out[] = $a[$i]; }
    return $out;
}

/* =========================================================================
 * 8. Method dispatch (used by transpiled code for obj.method(...))
 * ========================================================================= */

function pyhp_call_method($obj, string $name, array $args) {
    try {
        if (is_object($obj) && method_exists($obj, $name)) {
            return $obj->$name(...$args);
        }
        if (is_string($obj)) {
            return pyhp_str_method($obj, $name, $args);
        }
    } catch (ArgumentCountError $e) {
        throw new PyHPTypeError("{$name}(): wrong number of arguments — " . pyhp_first_line($e->getMessage()));
    } catch (TypeError $e) {
        throw new PyHPTypeError("{$name}(): " . pyhp_first_line($e->getMessage()));
    }
    throw new PyHPTypeError("'" . pyhp_type_name($obj) . "' object has no method '{$name}'");
}

function pyhp_first_line(string $s): string {
    $p = strpos($s, "\n");
    return $p === false ? $s : substr($s, 0, $p);
}

function pyhp_callfunc($c, array $args, string $name) {
    if (!is_callable($c)) {
        throw new PyHPTypeError("'" . $name . "' is not callable (" . pyhp_type_name($c) . ")");
    }
    return $c(...$args);
}

/* string methods (Python DNA) */
function pyhp_str_method(string $s, string $name, array $args) {
    $need = function (int $min, int $max) use ($args, $name) {
        $n = count($args);
        if ($n < $min || ($max !== -1 && $n > $max)) {
            throw new PyHPTypeError("str.{$name}() expects {$min}" . ($max === -1 ? '+' : "..{$max}") . " argument(s), got {$n}");
        }
    };
    switch ($name) {
        case 'upper':      $need(0, 0); return mb_strtoupper($s);
        case 'lower':      $need(0, 0); return mb_strtolower($s);
        case 'capitalize': $need(0, 0); $l = mb_strtolower($s); return mb_strtoupper(mb_substr($l, 0, 1)) . mb_substr($l, 1);
        case 'strip':      $need(0, 0); return trim($s);
        case 'lstrip':     $need(0, 0); return ltrim($s);
        case 'rstrip':     $need(0, 0); return rtrim($s);
        case 'split':
            $need(0, 1);
            if (count($args) === 0) {
                $parts = preg_split('/\s+/', trim($s));
                return new PyList($parts === false ? [] : array_values(array_filter($parts, fn($p) => $p !== '')));
            }
            $sep = $args[0];
            if (!is_string($sep)) throw new PyHPTypeError('str.split() separator must be a string');
            if ($sep === '') throw new PyHPValueError('empty separator');
            return new PyList(explode($sep, $s));
        case 'replace':
            $need(2, 2);
            if (!is_string($args[0]) || !is_string($args[1])) throw new PyHPTypeError('str.replace() arguments must be strings');
            return str_replace($args[0], $args[1], $s);
        case 'startswith': $need(1, 1); return str_starts_with($s, pyhp_strcast_arg($args[0], $name));
        case 'endswith':   $need(1, 1); return str_ends_with($s, pyhp_strcast_arg($args[0], $name));
        case 'contains':   $need(1, 1); return str_contains($s, pyhp_strcast_arg($args[0], $name));
        case 'find':       $need(1, 1);
            $p = mb_strpos($s, pyhp_strcast_arg($args[0], $name));
            return $p === false ? -1 : $p;
        case 'count':      $need(1, 1);
            return function_exists('mb_substr_count')
                ? mb_substr_count($s, pyhp_strcast_arg($args[0], $name))
                : substr_count($s, pyhp_strcast_arg($args[0], $name));
        case 'join':
            $need(1, 1);
            $items = pyhp_iter_values($args[0]);
            return implode($s, array_map(fn($x) => pyhp_tostr($x), $items));
        case 'chars':      $need(0, 0); return new PyList(pyhp_chars($s));
        default:
            throw new PyHPTypeError("str object has no method '{$name}'");
    }
}
function pyhp_strcast_arg($v, string $who): string {
    if (!is_string($v)) throw new PyHPTypeError("{$who}() argument must be a string, got " . pyhp_type_name($v));
    return $v;
}

/* =========================================================================
 * 9. Static typing enforcement ("C DNA")
 * ========================================================================= */

function pyhp_check_type($v, string $t, string $name) {
    switch ($t) {
        case 'int':    if (!is_int($v))  throw new PyHPTypeError("type mismatch: cannot assign " . pyhp_type_name($v) . " to int variable '{$name}'"); break;
        case 'float':
            if (is_int($v)) return (float)$v;   // C-style widening int -> float
            if (!is_float($v)) throw new PyHPTypeError("type mismatch: cannot assign " . pyhp_type_name($v) . " to float variable '{$name}'");
            break;
        case 'string': if (!is_string($v)) throw new PyHPTypeError("type mismatch: cannot assign " . pyhp_type_name($v) . " to string variable '{$name}'"); break;
        case 'bool':   if (!is_bool($v)) throw new PyHPTypeError("type mismatch: cannot assign " . pyhp_type_name($v) . " to bool variable '{$name}'"); break;
        case 'list':   if (!($v instanceof PyList)) throw new PyHPTypeError("type mismatch: cannot assign " . pyhp_type_name($v) . " to list variable '{$name}'"); break;
        case 'dict':   if (!($v instanceof PyDict)) throw new PyHPTypeError("type mismatch: cannot assign " . pyhp_type_name($v) . " to dict variable '{$name}'"); break;
        case 'var': case 'any': break;
        default:
            throw new PyHPSyntaxError("unknown type annotation '{$t}' for variable '{$name}'");
    }
    return $v;
}

function pyhp_php_type(string $t): string {
    switch ($t) {
        case 'int': return 'int';
        case 'float': return 'float';
        case 'string': return 'string';
        case 'bool': return 'bool';
        case 'list': case 'dict': return 'array';
        default: return 'mixed';
    }
}

/* =========================================================================
 * 10. Builtin functions
 * ========================================================================= */

function pyhp_set_argv(array $argv): void { $GLOBALS['__pyhp_argv'] = $argv; }

function pyhp_reg_errcheck(array $args, string $name, int $min, $max): void {
    $n = count($args);
    if ($n < $min || ($max !== null && $n > $max)) {
        $sig = $min . ($max === null ? '+' : ($max === $min ? '' : "..{$max}"));
        throw new PyHPTypeError("{$name}() takes {$sig} argument(s), got {$n}");
    }
}

function pyhp_builtins(): array {
    static $reg = null;
    if ($reg !== null) return $reg;

    $reg = [
        /* output */
        'print' => [function (...$a) { echo implode(' ', array_map(fn($x) => pyhp_tostr($x), $a)), "\n"; return null; }, 0, null],
        'println' => [function (...$a) { echo implode(' ', array_map(fn($x) => pyhp_tostr($x), $a)), "\n"; return null; }, 0, null],
        'repr' => [fn(...$a) => (pyhp_reg_errcheck($a, 'repr', 1, 1) ?: pyhp_repr($a[0])), 1, 1],

        /* collections */
        'len' => [function (...$a) {
            pyhp_reg_errcheck($a, 'len', 1, 1);
            $v = $a[0];
            if (is_string($v)) return mb_strlen($v);
            if ($v instanceof PyList || $v instanceof PyDict) return count($v instanceof PyList ? $v->a : $v->d);
            if ($v instanceof PyHPBuffer) return $v->size();
            throw new PyHPTypeError("len() of unsupported type '" . pyhp_type_name($v) . "'");
        }, 1, 1],
        'range' => [function (...$a) {
            pyhp_reg_errcheck($a, 'range', 1, 3);
            $vals = array_map(fn($x) => pyhp_range_int($x, 'range'), $a);
            if (count($vals) === 1) { $s = 0; $e = $vals[0]; $st = 1; }
            elseif (count($vals) === 2) { $s = $vals[0]; $e = $vals[1]; $st = 1; }
            else { $s = $vals[0]; $e = $vals[1]; $st = $vals[2]; }
            if ($st === 0) throw new PyHPValueError('range() step cannot be zero');
            $out = [];
            if ($st > 0) { for ($i = $s; $i < $e; $i += $st) $out[] = $i; }
            else { for ($i = $s; $i > $e; $i += $st) $out[] = $i; }
            return new PyList($out);
        }, 1, 3],
        'map' => [function (...$a) {
            pyhp_reg_errcheck($a, 'map', 2, null);
            $f = $a[0]; $iters = array_slice($a, 1);
            $cols = array_map(fn($it) => pyhp_iter_values($it), $iters);
            $n = min(array_map(fn($c) => count($c), $cols));
            $out = [];
            for ($i = 0; $i < $n; $i++) {
                $row = array_map(fn($c) => $c[$i], $cols);
                $out[] = pyhp_callfunc($f, $row, 'map callback');
            }
            return new PyList($out);
        }, 2, null],
        'filter' => [function (...$a) {
            pyhp_reg_errcheck($a, 'filter', 2, 2);
            $out = [];
            foreach (pyhp_iter_values($a[1]) as $v) { if (pyhp_truthy(pyhp_callfunc($a[0], [$v], 'filter callback'))) $out[] = $v; }
            return new PyList($out);
        }, 2, 2],
        'zip' => [function (...$a) {
            pyhp_reg_errcheck($a, 'zip', 1, null);
            $cols = array_map(fn($it) => pyhp_iter_values($it), $a);
            $n = min(array_map(fn($c) => count($c), $cols));
            $out = [];
            for ($i = 0; $i < $n; $i++) $out[] = new PyList(array_map(fn($c) => $c[$i], $cols));
            return new PyList($out);
        }, 1, null],
        'enumerate' => [function (...$a) {
            pyhp_reg_errcheck($a, 'enumerate', 1, 2);
            $start = isset($a[1]) ? pyhp_range_int($a[1], 'enumerate') : 0;
            $out = [];
            foreach (pyhp_iter_values($a[0]) as $v) { $out[] = new PyList([$start++, $v]); }
            return new PyList($out);
        }, 1, 2],
        'sorted' => [function (...$a) {
            pyhp_reg_errcheck($a, 'sorted', 1, 1);
            $vals = pyhp_iter_values($a[0]);
            usort($vals, fn($x, $y) => pyhp_compare($x, $y));
            return new PyList($vals);
        }, 1, 1],
        'reversed' => [function (...$a) {
            pyhp_reg_errcheck($a, 'reversed', 1, 1);
            return new PyList(array_reverse(pyhp_iter_values($a[0])));
        }, 1, 1],
        'sum' => [function (...$a) {
            pyhp_reg_errcheck($a, 'sum', 1, 2);
            $acc = $a[1] ?? 0;
            if (!pyhp_is_num($acc)) throw new PyHPTypeError('sum() start must be a number');
            foreach (pyhp_iter_values($a[0]) as $v) {
                if (!pyhp_is_num($v)) throw new PyHPTypeError('sum() requires numbers, got ' . pyhp_type_name($v));
                $acc = pyhp_add($acc, $v);
            }
            return $acc;
        }, 1, 2],
        'any' => [function (...$a) {
            pyhp_reg_errcheck($a, 'any', 1, 1);
            foreach (pyhp_iter_values($a[0]) as $v) { if (pyhp_truthy($v)) return true; }
            return false;
        }, 1, 1],
        'all' => [function (...$a) {
            pyhp_reg_errcheck($a, 'all', 1, 1);
            foreach (pyhp_iter_values($a[0]) as $v) { if (!pyhp_truthy($v)) return false; }
            return true;
        }, 1, 1],
        'keys' => [fn(...$a) => (pyhp_reg_errcheck($a, 'keys', 1, 1) ?: pyhp_dictof($a[0], 'keys')->keys()), 1, 1],
        'values' => [fn(...$a) => (pyhp_reg_errcheck($a, 'values', 1, 1) ?: pyhp_dictof($a[0], 'values')->values()), 1, 1],
        'items' => [fn(...$a) => (pyhp_reg_errcheck($a, 'items', 1, 1) ?: pyhp_dictof($a[0], 'items')->items()), 1, 1],
        'list' => [function (...$a) {
            pyhp_reg_errcheck($a, 'list', 0, 1);
            if (count($a) === 0) return new PyList([]);
            return new PyList(pyhp_iter_values($a[0]));
        }, 0, 1],
        'dict' => [function (...$a) {
            pyhp_reg_errcheck($a, 'dict', 0, 1);
            if (count($a) === 0) return new PyDict([]);
            $v = $a[0];
            if ($v instanceof PyDict) return new PyDict($v->d);
            if ($v instanceof PyList) {
                $d = [];
                foreach ($v->a as $pair) {
                    if (!($pair instanceof PyList) || count($pair->a) !== 2) {
                        throw new PyHPValueError('dict() expects a list of [key, value] pairs');
                    }
                    $d[pyhp_guard_key($pair->a[0])] = $pair->a[1];
                }
                return new PyDict($d);
            }
            throw new PyHPTypeError('dict() expects a dict or a list of pairs, got ' . pyhp_type_name($v));
        }, 0, 1],

        /* math */
        'abs' => [function (...$a) {
            pyhp_reg_errcheck($a, 'abs', 1, 1);
            if (!pyhp_is_num($a[0])) throw new PyHPTypeError('abs() requires a number');
            return abs(pyhp_num($a[0]));
        }, 1, 1],
        'min' => [function (...$a) {
            pyhp_reg_errcheck($a, 'min', 1, null);
            $vals = count($a) === 1 ? pyhp_iter_values($a[0]) : $a;
            if (count($vals) === 0) throw new PyHPValueError('min() of empty sequence');
            $best = $vals[0];
            foreach ($vals as $v) { if (pyhp_compare($v, $best) < 0) $best = $v; }
            return $best;
        }, 1, null],
        'max' => [function (...$a) {
            pyhp_reg_errcheck($a, 'max', 1, null);
            $vals = count($a) === 1 ? pyhp_iter_values($a[0]) : $a;
            if (count($vals) === 0) throw new PyHPValueError('max() of empty sequence');
            $best = $vals[0];
            foreach ($vals as $v) { if (pyhp_compare($v, $best) > 0) $best = $v; }
            return $best;
        }, 1, null],
        'floor' => [function (...$a) {
            pyhp_reg_errcheck($a, 'floor', 1, 1);
            return (int)floor((float)pyhp_num1($a[0], 'floor'));
        }, 1, 1],
        'ceil' => [fn(...$a) => (pyhp_reg_errcheck($a, 'ceil', 1, 1) ?: (int)ceil((float)pyhp_num1($a[0], 'ceil'))), 1, 1],
        'round' => [function (...$a) {
            pyhp_reg_errcheck($a, 'round', 1, 2);
            $x = pyhp_num1($a[0], 'round');
            $p = isset($a[1]) ? pyhp_range_int($a[1], 'round') : 0;
            return $p === 0 ? (is_int($x) ? $x : (int)round($x)) : round((float)$x, $p);
        }, 1, 2],
        'sqrt' => [function (...$a) {
            pyhp_reg_errcheck($a, 'sqrt', 1, 1);
            $x = pyhp_num1($a[0], 'sqrt');
            if ($x < 0) throw new PyHPValueError('sqrt() of negative number');
            return sqrt((float)$x);
        }, 1, 1],
        'pow' => [function (...$a) {
            pyhp_reg_errcheck($a, 'pow', 2, 2);
            return pyhp_pow($a[0], $a[1]);
        }, 2, 2],
        'rand' => [function (...$a) {
            pyhp_reg_errcheck($a, 'rand', 0, 2);
            if (count($a) === 0) return random_int(0, PHP_INT_MAX) / (PHP_INT_MAX + 1.0);
            if (count($a) === 1) { $n = pyhp_range_int($a[0], 'rand'); if ($n <= 0) throw new PyHPValueError('rand(n) requires n > 0'); return random_int(0, $n - 1); }
            $lo = pyhp_range_int($a[0], 'rand'); $hi = pyhp_range_int($a[1], 'rand');
            if ($hi < $lo) throw new PyHPValueError('rand(a, b) requires b >= a');
            return random_int($lo, $hi);
        }, 0, 2],

        /* conversions */
        'int' => [function (...$a) {
            pyhp_reg_errcheck($a, 'int', 0, 1);
            if (count($a) === 0) return 0;
            return pyhp_int_conv($a[0]);
        }, 0, 1],
        'float' => [function (...$a) {
            pyhp_reg_errcheck($a, 'float', 0, 1);
            if (count($a) === 0) return 0.0;
            $v = $a[0];
            if (is_int($v)) return (float)$v;
            if (is_float($v)) return $v;
            if (is_bool($v)) return $v ? 1.0 : 0.0;
            if (is_string($v)) {
                $t = trim($v);
                if (is_numeric($t)) return (float)$t;
                throw new PyHPValueError("invalid literal for float(): '" . $v . "'");
            }
            throw new PyHPTypeError("float() expects a number or string, got " . pyhp_type_name($v));
        }, 0, 1],
        'str' => [function (...$a) {
            pyhp_reg_errcheck($a, 'str', 0, 1);
            return count($a) === 0 ? '' : pyhp_tostr($a[0]);
        }, 0, 1],
        'bool' => [function (...$a) {
            pyhp_reg_errcheck($a, 'bool', 0, 1);
            return count($a) === 0 ? false : pyhp_truthy($a[0]);
        }, 0, 1],
        'type' => [function (...$a) {
            pyhp_reg_errcheck($a, 'type', 1, 1);
            return pyhp_type_name($a[0]);
        }, 1, 1],
        'isinstance' => [function (...$a) {
            pyhp_reg_errcheck($a, 'isinstance', 2, 2);
            return pyhp_isinstance($a[0], $a[1]);
        }, 2, 2],
        'chr' => [function (...$a) {
            pyhp_reg_errcheck($a, 'chr', 1, 1);
            $n = pyhp_range_int($a[0], 'chr');
            if ($n < 0 || $n > 0x10FFFF) throw new PyHPValueError('chr() out of range: ' . $n);
            if ($n < 128) return chr($n);
            return mb_chr($n, 'UTF-8');
        }, 1, 1],
        'ord' => [function (...$a) {
            pyhp_reg_errcheck($a, 'ord', 1, 1);
            $v = $a[0];
            if (!is_string($v) || mb_strlen($v) !== 1) throw new PyHPTypeError('ord() expects a single-character string');
            $o = mb_ord($v, 'UTF-8');
            return $o === false ? ord($v[0]) : $o;
        }, 1, 1],
        'hex' => [function (...$a) {
            pyhp_reg_errcheck($a, 'hex', 1, 1);
            $n = pyhp_bitcheck($a[0], 'hex()');
            return ($n < 0 ? '-0x' : '0x') . strtolower(dechex(abs($n)));
        }, 1, 1],
        'bin' => [function (...$a) {
            pyhp_reg_errcheck($a, 'bin', 1, 1);
            $n = pyhp_bitcheck($a[0], 'bin()');
            return ($n < 0 ? '-0b' : '0b') . decbin(abs($n));
        }, 1, 1],

        /* system access ("C DNA") */
        'exec' => [function (...$a) {
            pyhp_reg_errcheck($a, 'exec', 1, 1);
            if (!is_string($a[0])) throw new PyHPTypeError('exec() expects a command string');
            $outLines = [];
            $code = 0;
            @exec($a[0] . ' 2>&1', $outLines, $code);
            return new PyDict(['stdout' => implode("\n", $outLines), 'code' => $code]);
        }, 1, 1],
        'system' => [function (...$a) {
            pyhp_reg_errcheck($a, 'system', 1, 1);
            if (!is_string($a[0])) throw new PyHPTypeError('system() expects a command string');
            $code = 0;
            @system($a[0], $code);
            return $code;
        }, 1, 1],
        'file_read' => [function (...$a) {
            pyhp_reg_errcheck($a, 'file_read', 1, 1);
            $c = @file_get_contents(pyhp_pathstr($a[0], 'file_read'));
            if ($c === false) throw new PyHPRuntimeError("cannot read file: " . pyhp_pathstr($a[0], 'file_read'));
            return $c;
        }, 1, 1],
        'file_write' => [function (...$a) {
            pyhp_reg_errcheck($a, 'file_write', 2, 2);
            $c = @file_put_contents(pyhp_pathstr($a[0], 'file_write'), pyhp_tostr($a[1]), LOCK_EX);
            if ($c === false) throw new PyHPRuntimeError("cannot write file: " . pyhp_pathstr($a[0], 'file_write'));
            return $c;
        }, 2, 2],
        'file_append' => [function (...$a) {
            pyhp_reg_errcheck($a, 'file_append', 2, 2);
            $c = @file_put_contents(pyhp_pathstr($a[0], 'file_append'), pyhp_tostr($a[1]), FILE_APPEND | LOCK_EX);
            if ($c === false) throw new PyHPRuntimeError("cannot append to file: " . pyhp_pathstr($a[0], 'file_append'));
            return $c;
        }, 2, 2],
        'file_exists' => [fn(...$a) => (pyhp_reg_errcheck($a, 'file_exists', 1, 1) ?: @file_exists(pyhp_pathstr($a[0], 'file_exists'))), 1, 1],
        'file_delete' => [function (...$a) {
            pyhp_reg_errcheck($a, 'file_delete', 1, 1);
            $p = pyhp_pathstr($a[0], 'file_delete');
            if (!@unlink($p)) throw new PyHPRuntimeError("cannot delete file: {$p}");
            return true;
        }, 1, 1],
        'mkdir' => [function (...$a) {
            pyhp_reg_errcheck($a, 'mkdir', 1, 1);
            $p = pyhp_pathstr($a[0], 'mkdir');
            if (is_dir($p)) return true;
            if (!@mkdir($p, 0777, true)) throw new PyHPRuntimeError("cannot create directory: {$p}");
            return true;
        }, 1, 1],
        'env' => [function (...$a) {
            pyhp_reg_errcheck($a, 'env', 1, 1);
            $v = getenv(pyhp_strcast_arg($a[0], 'env'));
            return $v === false ? null : $v;
        }, 1, 1],
        'argv' => [function () { return new PyList($GLOBALS['__pyhp_argv'] ?? []); }, 0, 0],
        'exit' => [function (...$a) {
            pyhp_reg_errcheck($a, 'exit', 0, 1);
            throw new PyHPExit(isset($a[0]) ? pyhp_range_int($a[0], 'exit') : 0);
        }, 0, 1],
        'time' => [function () { return microtime(true); }, 0, 0],
        'sleep' => [function (...$a) {
            pyhp_reg_errcheck($a, 'sleep', 1, 1);
            $ms = pyhp_range_int($a[0], 'sleep');
            if ($ms < 0) throw new PyHPValueError('sleep() expects a non-negative number of milliseconds');
            @usleep($ms * 1000);
            return null;
        }, 1, 1],
        'input' => [function (...$a) {
            pyhp_reg_errcheck($a, 'input', 0, 1);
            if (isset($a[0])) echo pyhp_tostr($a[0]);
            $line = fgets(STDIN);
            return $line === false ? '' : rtrim($line, "\r\n");
        }, 0, 1],

        /* memory arena ("C DNA") */
        'malloc_arena' => [function () { return new PyHPArena(); }, 0, 0],
    ];

    return $reg;
}

function pyhp_call_builtin(string $name, array $args) {
    $reg = pyhp_builtins();
    if (!isset($reg[$name])) throw new PyHPError("undefined function '{$name}'");
    [$fn, $min, $max] = $reg[$name];
    pyhp_reg_errcheck($args, $name, $min, $max);
    return $fn(...$args);
}

function pyhp_builtin_exists(string $name): bool {
    return isset(pyhp_builtins()[$name]);
}

/* internal helpers */
function pyhp_range_int($v, string $who): int {
    if (is_bool($v)) return (int)$v;
    if (is_int($v)) return $v;
    if (is_float($v) && floor($v) === $v) return (int)$v;
    throw new PyHPTypeError("{$who}() expects an int, got " . pyhp_type_name($v));
}
function pyhp_num1($v, string $who) {
    if (pyhp_is_num($v)) return pyhp_num($v);
    throw new PyHPTypeError("{$who}() requires a number, got " . pyhp_type_name($v));
}
function pyhp_dictof($v, string $who): PyDict {
    if ($v instanceof PyDict) return $v;
    throw new PyHPTypeError("{$who}() expects a dict, got " . pyhp_type_name($v));
}
function pyhp_pathstr($v, string $who): string {
    if (!is_string($v)) throw new PyHPTypeError("{$who}() path must be a string, got " . pyhp_type_name($v));
    return $v;
}
function pyhp_int_conv($v) {
    if (is_bool($v)) return $v ? 1 : 0;
    if (is_int($v)) return $v;
    if (is_float($v)) return (int)$v;   // truncate toward zero, like Python int()
    if (is_string($v)) {
        $t = trim($v);
        if (preg_match('/^[+-]?\d+$/', $t)) return (int)$t;
        throw new PyHPValueError("invalid literal for int(): '" . $v . "'");
    }
    throw new PyHPTypeError("int() expects a number or string, got " . pyhp_type_name($v));
}

function pyhp_isinstance($v, $name): bool {
    if (!is_string($name)) throw new PyHPTypeError('isinstance() second argument must be a type name string');
    if ($v instanceof InterpInstance) {
        for ($c = $v->cls; $c !== null; $c = $c->parent) {
            if ($c->name === $name) return true;
        }
        return false;
    }
    if (!is_object($v)) return false;
    $map = pyhp_error_class_map();
    $candidates = [];
    if (isset($map[$name])) $candidates[] = $map[$name];
    $candidates[] = $name;
    $candidates[] = 'PyHP_' . $name;
    foreach ($candidates as $c) {
        if (class_exists($c) && $v instanceof $c) return true;
    }
    return false;
}

/* comprehensions (shared by interpreter + transpiled code) */
function pyhp_listcomp($mapFn, $condFn, $iter, bool $two = false): PyList {
    $out = [];
    foreach (pyhp_foreach($iter) as $k => $v) {
        if ($condFn !== null && !pyhp_truthy(pyhp_callfunc($condFn, $two ? [$k, $v] : [$v], 'listcomp condition'))) continue;
        $out[] = pyhp_callfunc($mapFn, $two ? [$k, $v] : [$v], 'listcomp expression');
    }
    return new PyList($out);
}
function pyhp_dictcomp($mapFn, $condFn, $iter, bool $two = false): PyDict {
    $d = [];
    foreach (pyhp_foreach($iter) as $k => $v) {
        if ($condFn !== null && !pyhp_truthy(pyhp_callfunc($condFn, $two ? [$k, $v] : [$v], 'dictcomp condition'))) continue;
        $pair = pyhp_callfunc($mapFn, $two ? [$k, $v] : [$v], 'dictcomp expression');
        if (!($pair instanceof PyList) || count($pair->a) !== 2) {
            throw new PyHPTypeError('dict comprehension must produce [key, value] pairs');
        }
        $d[pyhp_guard_key($pair->a[0])] = $pair->a[1];
    }
    return new PyDict($d);
}

/* Direct builtin-call entry point for transpiled code: pyhp_fn_call('len', $x) */
function pyhp_fn_call(string $name, ...$args) {
    return pyhp_call_builtin($name, $args);
}

/* PHP array polyfill guards for older runtimes */
if (!function_exists('str_starts_with')) { function str_starts_with($h, $n) { return strpos($h, $n) === 0; } }
if (!function_exists('str_ends_with'))   { function str_ends_with($h, $n)   { return $n === '' || substr($h, -strlen($n)) === $n; } }
if (!function_exists('str_contains'))    { function str_contains($h, $n)    { return $n === '' || strpos($h, $n) !== false; } }
