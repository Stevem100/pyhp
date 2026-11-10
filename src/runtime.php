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

const PYHP_VERSION = '0.7.0';

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
class PyHPOverflowError extends PyHPError    { public string $pyhpType = 'OverflowError'; }

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
        'OverflowError'     => 'PyHPOverflowError',
        'StopIteration'     => 'PyHPStopIteration',
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
    public ?string $fileDir = null; // dir of the file this function was defined in (require resolution)
    public bool $isGenerator = false;   // body contains `yield` at this level
    public string $genSrc = '';         // cached compiled PHP generator source
    public $vmOps = null;               // cached bytecode (VM engine)
    public function __invoke(...$args) {
        return $this->interp->invokeFunc($this, $args);
    }
}

/** Placeholder for "argument not given" in positional slots — emitted by the
 *  transpiler's kwarg calls and consumed by the prologue's default logic. */
final class PyHPMissing {
    private static ?PyHPMissing $inst = null;
    private function __construct() {}
    public static function get(): self { return self::$inst ??= new self(); }
    public static function is($v): bool { return $v instanceof self; }
}

/** Callable wrapper for a builtin registered in the interpreter's global env.
 *  Carries the builtin name so calls with keyword arguments can be routed to
 *  pyhp_call_builtin() (plain closures would drop the kwargs). Still callable
 *  like any first-class function value. */
final class PyHPBuiltin {
    public function __construct(public readonly string $name) {}
    public function __invoke(...$args) { return pyhp_call_builtin($this->name, $args); }
}

/** A class as a first-class value (module exports, `extends someVar`).
 *  Callable — invoking it instantiates the class. */
class InterpClassRef {
    public ClassDef $def;
    public $interp = null;
    public function __construct(ClassDef $def, $interp) { $this->def = $def; $this->interp = $interp; }
    public function __invoke(...$args) {
        return $this->interp->instantiate($this->def, $args);
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
class PyHPStopIteration extends PyHPError { public string $pyhpType = 'StopIteration'; }
class PyHPThrow extends Exception     { public $value; public function __construct($v) { $this->value = $v; } }
class PyHPExit extends Exception      { public int $exitCode; public function __construct(int $c) { $this->exitCode = $c; } }

/* =========================================================================
 * 3b. Lazy views & generators (the "lazy DNA")
 *
 * range/map/filter/zip/enumerate/reversed return PyHPView objects that
 * stream one item at a time (Python semantics) instead of materializing a
 * list. `yield` turns any function into a PyHPGenerator. Both implement
 * IteratorAggregate, so foreach streams lazily; list()/sorted()/
 * pyhp_iter_values() materialize on demand.
 * -------------------------------------------------------------------- */

class PyHPView implements Iterator {
    public string $kind;
    public array $args;
    private $stream = null;

    public function __construct(string $kind, array $args) {
        $this->kind = $kind;
        $this->args = $args;
    }

    /** The one underlying stream — views are single-use, like Python iterators. */
    public function getIterator(): Traversable {
        if ($this->stream === null) { $this->stream = $this->buildStream(); }
        return $this->stream;
    }

    public function __iter__() { return $this; }

    public function __next__() { return $this->streamNext(); }

    /* Iterator protocol (pyhp_iter hands views out as-is) */
    public function current(): mixed { $this->ensureStream(); return $this->stream->current(); }
    public function key(): mixed { $this->ensureStream(); return $this->stream->key(); }
    public function next(): void { $this->ensureStream(); $this->stream->next(); }
    public function rewind(): void { $this->ensureStream(); }
    public function valid(): bool { $this->ensureStream(); return $this->stream->valid(); }

    private function ensureStream(): void {
        if ($this->stream === null) { $this->stream = $this->buildStream(); }
    }

    private function streamNext() {
        $this->ensureStream();
        if (!$this->stream->valid()) throw new PyHPStopIteration();
        $v = $this->stream->current();
        $this->stream->next();
        return $v;
    }

    private function buildStream(): Generator {
        switch ($this->kind) {
            case 'range':
                yield from $this->rangeStream();
                return;
            case 'map': {
                $f = $this->args[0];
                $cols = [];
                foreach (array_slice($this->args, 1) as $it) { $cols[] = pyhp_iter($it); }
                while (true) {
                    $row = []; $ok = true;
                    foreach ($cols as $c) {
                        if (!$c->valid()) { $ok = false; break; }
                        $row[] = $c->current();
                    }
                    if (!$ok) return;
                    yield pyhp_callfunc($f, $row, 'map callback');
                    foreach ($cols as $c) { $c->next(); }
                }
                return;
            }
            case 'filter': {
                $f = $this->args[0];
                $src = pyhp_iter($this->args[1]);
                for (; $src->valid(); $src->next()) {
                    $v = $src->current();
                    if (pyhp_truthy(pyhp_callfunc($f, [$v], 'filter callback'))) { yield $v; }
                }
                return;
            }
            case 'zip': {
                $cols = [];
                foreach ($this->args as $it) { $cols[] = pyhp_iter($it); }
                while (true) {
                    $row = []; $ok = true;
                    foreach ($cols as $c) {
                        if (!$c->valid()) { $ok = false; break; }
                        $row[] = $c->current();
                    }
                    if (!$ok) return;
                    yield new PyList($row);
                    foreach ($cols as $c) { $c->next(); }
                }
                return;
            }
            case 'enumerate': {
                $src = pyhp_iter($this->args[0]);
                $i = $this->args[1];
                for (; $src->valid(); $src->next()) {
                    yield new PyList([$i, $src->current()]);
                    $i = pyhp_add($i, 1);
                }
                return;
            }
            case 'reversed': {
                $seq = $this->args[0];
                if (is_string($seq)) {
                    $chars = pyhp_chars($seq);
                    for ($i = count($chars) - 1; $i >= 0; $i--) { yield $chars[$i]; }
                    return;
                }
                if ($seq instanceof PyList) {
                    for ($i = count($seq->a) - 1; $i >= 0; $i--) { yield $seq->a[$i]; }
                    return;
                }
                // range view: walk indices backwards without materializing
                $n = $seq->rangeLen();
                for ($i = pyhp_sub($n, 1); pyhp_compare($i, -1) > 0; $i = pyhp_sub($i, 1)) {
                    yield $seq->rangeAt($i);
                }
                return;
            }
        }
        throw new PyHPRuntimeError("unknown view kind '{$this->kind}'");
    }

    /* ---- range-specific helpers (exact with big-int bounds) ---- */

    private function rangeStream(): Generator {
        [$s, $e, $st] = [$this->args[0], $this->args[1], $this->args[2]];
        $big = $s instanceof PyBigInt || $e instanceof PyBigInt || $st instanceof PyBigInt;
        if ($big) {
            $cur = pyhp_to_big($s);
            $step = pyhp_to_big($st);
            $end = pyhp_to_big($e);
            $neg = $step->dec[0] === '-';
            while ($neg ? pyhp_big_cmp($cur, $end) > 0 : pyhp_big_cmp($cur, $end) < 0) {
                yield $cur;
                $cur = pyhp_big_add($cur, $step);
            }
        } else {
            if ($st > 0) { for ($i = $s; $i < $e; $i += $st) { yield $i; } }
            else { for ($i = $s; $i > $e; $i += $st) { yield $i; } }
        }
    }

    /** len(range(...)) — exact, big included. */
    public function rangeLen() {
        $s = pyhp_to_big($this->args[0]);
        $e = pyhp_to_big($this->args[1]);
        $st = pyhp_to_big($this->args[2]);
        $diff = pyhp_big_sub($e, $s);
        $negStep = $st->dec[0] === '-';
        $fwd = $negStep ? pyhp_big_neg($diff) : $diff;      // distance travelled
        $zero = new PyBigInt('0');
        if (pyhp_big_cmp($fwd, $zero) <= 0) return 0;
        $astep = $negStep ? pyhp_big_neg($st) : $st;
        [$q, $r] = pyhp_big_divmod($fwd, $astep);
        $len = $r->dec === '0' ? $q : pyhp_big_add($q, new PyBigInt('1'));
        return pyhp_big_fits_int($len->dec) ? (int)$len->dec : $len;
    }

    /** range[i] with clamping and negative indices (Python semantics). */
    public function rangeAt($i) {
        $s = pyhp_to_big($this->args[0]);
        $st = pyhp_to_big($this->args[2]);
        $idx = pyhp_to_big($i);
        return pyhp_big_add($s, pyhp_big_mul($st, $idx));
    }

    public function rangeGet($i) {
        if (!pyhp_int_like($i) && !(is_float($i) && floor($i) === $i)) {
            throw new PyHPTypeError('range indices must be int, got ' . pyhp_type_name($i));
        }
        $n = pyhp_to_big($this->rangeLen());
        $j = pyhp_to_big(is_float($i) ? (int)$i : $i);
        if ($j->dec[0] === '-') { $j = pyhp_big_add($j, $n); }
        if (pyhp_big_cmp($j, new PyBigInt('0')) < 0 || pyhp_big_cmp($j, $n) >= 0) {
            throw new PyHPIndexError("range index {$i} out of range (length " . pyhp_tostr($this->rangeLen()) . ")");
        }
        return $this->rangeAt($j);
    }
}

class PyHPGenerator implements Iterator {
    public $gen;
    public string $fnName;
    public function __construct($gen, string $fnName) {
        $this->gen = $gen;
        $this->fnName = $fnName;
    }
    public function getIterator(): Traversable { return $this->gen; }
    public function __iter__() { return $this; }
    public function __next__() {
        if (!$this->gen->valid()) throw new PyHPStopIteration();
        $v = $this->gen->current();
        $this->gen->next();
        return $v;
    }
    public function current(): mixed { return $this->gen->current(); }
    public function key(): mixed { return $this->gen->key(); }
    public function next(): void { $this->gen->next(); }
    public function rewind(): void {}
    public function valid(): bool { return $this->gen->valid(); }
    /** Does the generator carry a `return` value (Python StopIteration.value)? */
    public function result() {
        if ($this->gen->valid()) throw new PyHPRuntimeError("generator is still running");
        return $this->gen->getReturn();
    }
}

/** Lazy iteration for foreach/comprehensions: streams views and generators,
 *  wraps everything else (user iterators via the lazy hook when present). */
function pyhp_iter($v): Traversable {
    if ($v instanceof PyHPView || $v instanceof PyHPGenerator) return $v;
    if ($v instanceof PyList) return new ArrayIterator(array_values($v->a));
    if ($v instanceof PyDict) return new ArrayIterator($v->d);
    if (is_string($v)) return new ArrayIterator(pyhp_chars($v));
    if (is_object($v) && !($v instanceof Closure)) {
        $hook = $GLOBALS['__pyhp_lazy_iter_hook'] ?? null;
        if ($hook !== null) {
            $t = $hook($v);
            if ($t !== null) return $t;
        }
        // transpiled user classes: __iter__()/__next__() protocol
        $it = $v;
        if (method_exists($v, '__iter__')) {
            $it = $v->__iter__();
            if ($it instanceof PyList) return new ArrayIterator(array_values($it->a));
        }
        if (is_object($it) && method_exists($it, '__next__')) {
            return (function () use ($it) {
                try {
                    while (true) { yield $it->__next__(); }
                } catch (PyHPStopIteration $e) {
                } catch (PyHPThrow $t) {
                    if (!pyhp_throw_is_stop($t)) throw $t;
                }
            })();
        }
    }
    return new ArrayIterator(pyhp_foreach($v));   // fallback: materialize
}

/** AST walk: does this function body contain `yield` at its own level?
 *  Nested functions/lambdas do not make the outer function a generator. */
function pyhp_ast_has_yield(array $stmts): bool {
    return pyhp_expr_has_yield($stmts);
}

/** Recursive walk over any AST shape (nodes, lists of nodes, pairs). */
function pyhp_expr_has_yield($node): bool {
    if (!is_array($node)) return false;
    if (isset($node['k']) && is_string($node['k'])) {
        if ($node['k'] === 'Yield') return true;
        if ($node['k'] === 'Func') return false;   // nested scope boundary
        foreach ($node as $key => $child) {
            if ($key === 'k' || $key === 'line') continue;
            if (pyhp_expr_has_yield($child)) return true;
        }
        return false;
    }
    foreach ($node as $child) {
        if (pyhp_expr_has_yield($child)) return true;
    }
    return false;
}

/* ---- helpers used by compiled generator bodies (GenCompiler) ---- */

function pyhp_env_post(PyHPEnv $env, string $n, int $dir) {
    $old = $env->get($n);
    if (!pyhp_is_num($old)) {
        $op = $dir > 0 ? "'++'" : "'--'";
        throw new PyHPTypeError("{$op} requires a numeric variable, got " . pyhp_type_name($old));
    }
    $env->assign($n, $dir > 0 ? pyhp_add($old, 1) : pyhp_sub($old, 1));
    return $old;
}

/** Build an interpreter function value from an embedded AST node. */
function pyhp_env_def_func($interp, PyHPEnv $env, array $ast) {
    $f = new InterpFunc();
    $f->name = $ast['name'] ?? '<lambda>';
    $f->params = $ast['params'];
    $f->ret = $ast['ret'] ?? null;
    $f->body = $ast['body']['stmts'];
    $f->env = $env;
    $f->interp = $interp;
    $f->isGenerator = pyhp_ast_has_yield($f->body);
    $f->fileDir = $interp->currentDirPub();
    return $f;
}

function pyhp_interp_eval($interp, PyHPEnv $env, array $ast) {
    return $interp->evalExpr($ast, $env);
}

function pyhp_interp_exec($interp, PyHPEnv $env, array $ast): void {
    $interp->execStmt($ast, $env);
}

/** Var-name call resolution for compiled generator bodies. */
function pyhp_call_name($interp, string $name, array $args, array $kws, int $line, PyHPEnv $env) {
    return $interp->callVarName($name, $args, $kws, $env, $line);
}

/** try/catch matching for compiled generator bodies. Returns
 *  [catchIndex, varName, instance] for the first matching catch or null. */
function pyhp_try_match($interp, $e, array $catches) {
    $inst = null;
    if ($e instanceof PyHPThrow) {
        $inst = $e->value instanceof InterpInstance ? $e->value : null;
        if ($inst === null) return null;
    } elseif ($e instanceof PyHPError) {
        $inst = $interp->errorInstanceFor($e);
    } else {
        return null;   // PyHPReturn / Break / Continue / Exit are not user errors
    }
    foreach ($catches as $i => $c) {
        if ($c['type'] === null || $interp->instanceMatches($inst, $c['type'])) {
            return [$i, $c['var'], $inst];
        }
    }
    return null;
}

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
    if ($v instanceof PyBigInt) return 'int';
    if ($v instanceof PyHPView) return $v->kind === 'range' ? 'range' : $v->kind;
    if ($v instanceof PyHPGenerator) return 'generator';
    if ($v instanceof PyHPBuffer) return 'buffer';
    if ($v instanceof PyHPArena) return 'arena';
    if ($v instanceof InterpFunc) return 'function';
    if ($v instanceof PyHPBuiltin) return 'function';
    if ($v instanceof Closure) return 'function';
    if ($v instanceof PyHPError) return $v->pyhpType;
    if ($v instanceof InterpInstance) return $v->cls->name;
    if (is_object($v)) {
        $c = get_class($v);
        if (str_starts_with($c, 'PyHP_')) {
            $n = substr($c, 5);
            // module-compiled classes carry a namespace prefix: PyHP_m0_Circle -> Circle
            if (preg_match('/^m\d+_(.+)$/', $n, $mm) === 1) return $mm[1];
            return $n;
        }
        return $c;
    }
    return 'unknown';
}

function pyhp_truthy($v): bool {
    if ($v === null) return false;
    if (is_bool($v)) return $v;
    if ($v instanceof PyBigInt) return $v->dec !== '0';
    if (is_int($v) || is_float($v)) return $v != 0;
    if (is_string($v)) return $v !== '';
    if ($v instanceof PyList) return count($v->a) > 0;
    if ($v instanceof PyDict) return count($v->d) > 0;
    if ($v instanceof PyHPView && $v->kind === 'range') return $v->rangeLen() != 0;   // like Python's range.__bool__
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

/* ----------------------------------------------------------------------
 * pyhp_format — the f-string format mini-language ({x:.2f}, {n:>8,}, ...)
 * Spec grammar (Python compatible):
 *   [[fill]align][sign][#][0][width][,][.precision][type]
 * Shared by the interpreter and the PHP transpiler, so both engines
 * produce byte-identical output. pyhp_rt_format (C++) mirrors this.
 * -------------------------------------------------------------------- */
function pyhp_format($v, string $spec): string {
    static $re = '/^(?:(?<fill>.)?(?<align>[<>^]))?(?<sign>[+\\- ]?)(?<alt>#)?(?<zero>0)?(?<width>\\d[\\d,]*|,)?(?:\\.(?<prec>\\d+))?(?<type>[bcdeEfFgGnosxX%])?$/';
    if (!preg_match($re, $spec, $m)) {
        throw new PyHPError("invalid format spec '{$spec}'");
    }
    $fill  = ($m['fill'] ?? '') !== '' ? $m['fill'] : null;
    $align = ($m['align'] ?? '') !== '' ? $m['align'] : null;
    $signC = ($m['sign'] ?? '') !== '' ? $m['sign'] : '-';
    $alt   = ($m['alt'] ?? '') === '#';
    $zero  = ($m['zero'] ?? '') === '0';
    $grp   = false; $width = 0;
    if (($m['width'] ?? '') !== '') {
        $grp = strpos($m['width'], ',') !== false;
        $width = (int)str_replace(',', '', $m['width']);
    }
    $prec = ($m['prec'] ?? '') !== '' ? (int)$m['prec'] : null;
    $type = $m['type'] ?? '';

    $isNum = is_int($v) || is_float($v) || is_bool($v) || $v instanceof PyBigInt;
    $body = ''; $neg = false; $prefix = ''; $signStr = ''; $zeroPad = false;
    if ($type === 's' || $type === 'c' || ($type === '' && !($isNum && !is_bool($v)))) {
        // string-ish presentation (str, char, null, bool, and type 's' on anything)
        if ($type === 'c') {
            if (is_int($v) || is_bool($v)) {
                $cp = (int)$v;
                if ($cp < 0 || $cp > 0x10FFFF || ($cp >= 0xD800 && $cp <= 0xDFFF)) {
                    throw new PyHPError('invalid code point for format type c');
                }
                $body = pyhp_utf8_chr($cp);
            } else {
                $s = pyhp_tostr($v);
                if ($s === '') throw new PyHPError('cannot format an empty string with type c');
                $body = mb_substr($s, 0, 1);
            }
        } elseif ($prec !== null && ($type === 's' || $type === '')) {
            $body = mb_substr(pyhp_tostr($v), 0, $prec);
        } else {
            $body = pyhp_tostr($v);
        }
        if ($align === null) $align = '<';
    } else {
        if (!$isNum) throw new PyHPTypeError("unsupported value for format spec '{$spec}'");
        $f = $v instanceof PyBigInt ? pyhp_big_to_float($v) : (float)$v;   // OverflowError past float range (like Python)
        $i = (int)round($f);
        $neg = $f < 0 || ($f == 0.0 && strpos((string)$v, '-') === 0);
        $af = abs($f); $ai = abs($i);
        $plainType = ($type === '' || $type === 'n');
        if ($type === 'd' || ($type === '' && (is_int($v) || $v instanceof PyBigInt))) {
            if (is_float($v)) {
                throw new PyHPTypeError("unknown format code 'd' for float — use :f or :g");
            }
            $body = $v instanceof PyBigInt ? ltrim($v->dec, '-') : (string)$ai;
        } elseif ($type === 'b' || $type === 'o' || $type === 'x' || $type === 'X') {
            if ($grp) throw new PyHPError("cannot use ',' with format type '{$type}'");
            if ($v instanceof PyBigInt) {
                $digits = PyBigMath::toBase(ltrim($v->dec, '-'), ['b' => 2, 'o' => 8, 'x' => 16, 'X' => 16][$type]);
                if ($type === 'X') $digits = strtoupper($digits);
            } else {
                $digits = $type === 'b' ? decbin($ai) : ($type === 'o' ? decoct($ai) : dechex($ai));
                if ($type === 'X') $digits = strtoupper($digits);
            }
            $body = $digits;
            if ($alt) $prefix = $type === 'b' ? '0b' : ($type === 'o' ? '0o' : ($type === 'X' ? '0X' : '0x'));
        } elseif ($type === 'f' || $type === 'F') {
            $body = sprintf('%.' . ($prec ?? 6) . ($type === 'F' ? 'F' : 'f'), $af);
            if ($type === 'f') $body = str_replace(['INF', 'NAN'], ['inf', 'nan'], $body);
        } elseif ($type === 'e' || $type === 'E') {
            $body = pyhp_fix_exp(sprintf('%.' . ($prec ?? 6) . $type, $af));
        } elseif ($type === 'g' || $type === 'G' || ($plainType && is_float($v) && $prec !== null)) {
            $body = pyhp_format_g($af, $prec === null ? 6 : max(1, $prec), $type !== 'G');
            if ($type === 'G') $body = strtoupper($body);
        } elseif ($type === '%') {
            $body = sprintf('%.' . ($prec ?? 6) . 'f', $af * 100) . '%';
        } else {
            // type '' on a float, no precision: keep the engine-wide float text
            $body = pyhp_num_str($af);
        }
        $signStr = $neg ? '-' : ($signC === '+' ? '+' : ($signC === ' ' ? ' ' : ''));
        if ($zero && $align === null) {
            $zeroPad = true;
            if ($width > 0) {
                $body = preg_replace_callback('/^(\\d+)(.*)$/s', function ($x) use ($width, $signStr, $prefix) {
                    $avail = $width - strlen($signStr) - strlen($prefix) - strlen($x[2]);
                    return str_pad($x[1], max(strlen($x[1]), $avail), '0', STR_PAD_LEFT) . $x[2];
                }, $body);
            }
        }
        if ($grp) {
            $body = preg_replace_callback('/^\\d+/', fn($x) => pyhp_group_digits($x[0]), $body);
        }
        $body = $signStr . $prefix . $body;
        if ($align === null) $align = '>';
    }
    if ($width > 0 && strlen($body) < $width) {
        if ($align === '>') $body = str_pad($body, $width, $fill ?? ' ', STR_PAD_LEFT);
        elseif ($align === '<') $body = str_pad($body, $width, $fill ?? ' ');
        else {
            $total = $width - strlen($body);
            $left = intdiv($total, 2);
            $body = str_pad('', $left, $fill ?? ' ') . $body . str_pad('', $total - $left, $fill ?? ' ');
        }
    }
    return $body;
}

/** Read a module-level variable from a compiled module unit's state bucket.
 *  Mirrors the interpreter's "undefined variable" error. */
function pyhp_unit_get(string $unit, string $name) {
    if (!isset($GLOBALS['__pyhp_unit_' . $unit][$name])) {
        throw new PyHPError("undefined variable '{$name}'");
    }
    return $GLOBALS['__pyhp_unit_' . $unit][$name];
}

function pyhp_group_digits(string $d): string {
    return preg_replace('/\\B(?=(\\d{3})+(?!\\d))/', ',', $d);
}

/** PHP prints e-notation as e+4; Python as e+04. Normalize to 2+ digits. */
function pyhp_fix_exp(string $s): string {
    return preg_replace('/([eE][+\\-])(\\d)(?!\\d)/', '${1}0${2}', $s);
}

/** Python-style %g: precision = significant digits, trailing zeros stripped. */
function pyhp_format_g(float $v, int $p, bool $lower): string {
    if (is_infinite($v) || is_nan($v)) {
        $s = $v > 0 ? 'inf' : (is_nan($v) ? 'nan' : '-inf');
        return $lower ? $s : strtoupper($s);
    }
    if ($v == 0.0) return '0';
    $exp = (int)floor(log10(abs($v)));
    if ($exp < -4 || $exp >= $p) {
        $body = pyhp_fix_exp(sprintf('%.' . ($p - 1) . ($lower ? 'e' : 'E'), $v));
        [$mant, $ex] = explode(($lower ? 'e' : 'E'), $body);
        $mant = rtrim(rtrim($mant, '0'), '.');
        if ($mant === '' || $mant === '-') $mant .= '0';
        return $mant . ($lower ? 'e' : 'E') . $ex;
    }
    $body = sprintf('%.' . max(0, $p - 1 - $exp) . 'f', $v);
    if (strpos($body, '.') !== false) $body = rtrim(rtrim($body, '0'), '.');
    return $body;
}

function pyhp_utf8_chr(int $cp): string {
    if ($cp < 0x80) return chr($cp);
    if ($cp < 0x800) return chr(0xC0 | ($cp >> 6)) . chr(0x80 | ($cp & 0x3F));
    if ($cp < 0x10000) return chr(0xE0 | ($cp >> 12)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
    return chr(0xF0 | ($cp >> 18)) . chr(0x80 | (($cp >> 12) & 0x3F)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
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
    if ($v instanceof PyBigInt) return $v->dec;
    if ($v instanceof PyHPView) {
        if ($v->kind === 'range') {
            [$s, $e, $st] = [$v->args[0], $v->args[1], $v->args[2]];
            $base = 'range(' . pyhp_tostr($s) . ', ' . pyhp_tostr($e);
            return ($st === 1 && !($st instanceof PyBigInt)) || ($st instanceof PyBigInt && $st->dec === '1')
                ? $base . ')'
                : $base . ', ' . pyhp_tostr($st) . ')';
        }
        return "<{$v->kind} object>";
    }
    if ($v instanceof PyHPGenerator) return "<generator object {$v->fnName}>";
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
    if ($v instanceof InterpClassRef) return "<class {$v->def->name}>";
    if ($v instanceof InterpInstance) {
        $hook = $GLOBALS['__pyhp_str_hook'] ?? null;
        if ($hook !== null) {
            $s = $hook($v);
            if ($s !== null) return $s;
        }
        return "<{$v->cls->name} object>";
    }
    if ($v instanceof Closure) return '<function>';
    if ($v instanceof PyHPBuiltin) return '<function>';
    if (is_object($v)) {
        if (method_exists($v, '__toString')) return (string)$v;
        return '<' . pyhp_type_name($v) . ' object>';
    }
    return (string)$v;
}

/* =========================================================================
 * 5b. Big integers — arbitrary-precision ints with Python promotion rules
 *
 * Any int operation that overflows 64-bit promotes to PyBigInt, so PyHP
 * behaves like Python: `2 ** 100` is exact, `int("999...9")` never rounds,
 * int literals beyond 64-bit parse exactly. ext/gmp is used when present
 * (fast path); otherwise a pure-PHP base-1e9 limb implementation runs with
 * identical results. Bitwise ops on big values need gmp (two's-complement
 * semantics for negatives); the fallback raises a clear error instead.
 * -------------------------------------------------------------------- */

final class PyBigInt {
    public string $dec;   // canonical form: "0" | -?[1-9][0-9]*
    public function __construct(string $dec) { $this->dec = pyhp_big_canon($dec); }
    public function __toString(): string { return $this->dec; }
    public static function __set_state(array $a): self { return new self($a['dec']); }
}

function pyhp_big_canon(string $s): string {
    $s = trim($s);
    if (!preg_match('/^[+-]?[0-9]+$/', $s)) {
        throw new PyHPValueError("invalid big-int literal '{$s}'");
    }
    $neg = $s[0] === '-';
    $s = ltrim($s, '+-');
    $s = ltrim($s, '0');
    if ($s === '') return '0';
    return ($neg ? '-' : '') . $s;
}

function pyhp_is_big($v): bool { return $v instanceof PyBigInt; }

/** Factory used by transpiled code for big-int literals: pyhp_big("12676506..."). */
function pyhp_big(string $dec): PyBigInt { return new PyBigInt($dec); }

/** Numeric without floats: PHP int, bool, or PyBigInt. */
function pyhp_int_like($v): bool { return is_int($v) || is_bool($v) || $v instanceof PyBigInt; }

function pyhp_to_big($v): PyBigInt {
    if ($v instanceof PyBigInt) return $v;
    if (is_bool($v)) return new PyBigInt($v ? '1' : '0');
    if (is_int($v)) return new PyBigInt((string)$v);
    throw new PyHPTypeError('expected an int, got ' . pyhp_type_name($v));
}

/** Exact float conversion; OverflowError past float range (like Python). */
function pyhp_big_to_float(PyBigInt $x): float {
    $f = (float)$x->dec;
    if (is_infinite($f)) throw new PyHPOverflowError('int too large to convert to float');
    return $f;
}

function pyhp_big_is_gmp(): bool { return function_exists('gmp_init'); }

/** Does this canonical decimal string fit in a PHP int? */
function pyhp_big_fits_int(string $dec): bool {
    if ($dec[0] === '-') {
        $m = substr($dec, 1);
        return strlen($m) < 19 || (strlen($m) === 19 && strcmp($m, '9223372036854775808') <= 0);
    }
    return strlen($dec) < 19 || (strlen($dec) === 19 && strcmp($dec, '9223372036854775807') <= 0);
}

/** Pure-PHP big-int core: base-1e9 little-endian limbs over magnitudes.
 *  Used whenever ext/gmp is unavailable. */
final class PyBigMath {
    const BASE = 1000000000;

    /** canonical dec -> [sign(-1|1), little-endian limbs (no leading zeros)] */
    public static function limbs(string $dec): array {
        $neg = $dec[0] === '-';
        if ($neg) $dec = substr($dec, 1);
        $out = [];
        for ($i = strlen($dec); $i > 0; $i -= 9) {
            $out[] = (int)substr($dec, max(0, $i - 9), $i - max(0, $i - 9));
        }
        while (count($out) > 1 && end($out) === 0) array_pop($out);
        return [$neg ? -1 : 1, $out];
    }

    public static function dec(array $limbs, int $sign): string {
        while (count($limbs) > 1 && end($limbs) === 0) array_pop($limbs);
        if (count($limbs) === 1 && $limbs[0] === 0) return '0';
        $s = (string)$limbs[count($limbs) - 1];
        for ($i = count($limbs) - 2; $i >= 0; $i--) { $s .= sprintf('%09d', $limbs[$i]); }
        return ($sign < 0 ? '-' : '') . $s;
    }

    public static function cmp(array $a, array $b): int {
        if (count($a) !== count($b)) return count($a) <=> count($b);
        for ($i = count($a) - 1; $i >= 0; $i--) {
            if ($a[$i] !== $b[$i]) return $a[$i] <=> $b[$i];
        }
        return 0;
    }

    public static function add(array $a, array $b): array {
        $n = max(count($a), count($b));
        $out = []; $carry = 0;
        for ($i = 0; $i < $n; $i++) {
            $s = ($a[$i] ?? 0) + ($b[$i] ?? 0) + $carry;
            $out[] = $s % self::BASE;
            $carry = intdiv($s, self::BASE);
        }
        if ($carry > 0) $out[] = $carry;
        return $out;
    }

    /** a - b on magnitudes; requires a >= b. */
    public static function sub(array $a, array $b): array {
        $out = []; $borrow = 0;
        for ($i = 0; $i < count($a); $i++) {
            $d = $a[$i] - ($b[$i] ?? 0) - $borrow;
            if ($d < 0) { $d += self::BASE; $borrow = 1; } else { $borrow = 0; }
            $out[] = $d;
        }
        while (count($out) > 1 && end($out) === 0) array_pop($out);
        return $out;
    }

    public static function mul(array $a, array $b): array {
        if ((count($a) === 1 && $a[0] === 0) || (count($b) === 1 && $b[0] === 0)) return [0];
        $n = count($a); $m = count($b);
        $out = array_fill(0, $n + $m, 0);
        for ($i = 0; $i < $n; $i++) {
            if ($a[$i] === 0) continue;
            $carry = 0;
            for ($j = 0; $j < $m; $j++) {
                $t = $out[$i + $j] + $a[$i] * $b[$j] + $carry;
                $out[$i + $j] = $t % self::BASE;
                $carry = intdiv($t, self::BASE);
            }
            $k = $i + $m;
            while ($carry > 0) {
                $t = $out[$k] + $carry;
                $out[$k] = $t % self::BASE;
                $carry = intdiv($t, self::BASE);
                $k++;
            }
        }
        while (count($out) > 1 && end($out) === 0) array_pop($out);
        return $out;
    }

    /** Schoolbook long division on magnitudes: a / b -> [quotient, remainder]. */
    public static function divmod(array $a, array $b): array {
        $c = self::cmp($a, $b);
        if ($c < 0) return [[0], $a];
        if ($c === 0) return [[1], [0]];
        if (count($b) === 1 && $b[0] === 0) throw new PyHPZeroDivisionError('big-int division by zero');
        $bt = $b[count($b) - 1];
        $rem = [0];
        $qout = [];
        for ($i = count($a) - 1; $i >= 0; $i--) {
            array_unshift($rem, $a[$i]); // rem = rem * BASE + a[i]
            while (count($rem) > 1 && end($rem) === 0) array_pop($rem);
            if (self::cmp($rem, $b) < 0) { $qout[] = 0; continue; }
            $rlen = count($rem);
            $hi = $rlen >= 2 ? $rem[$rlen - 1] * self::BASE + $rem[$rlen - 2] : $rem[$rlen - 1];
            $qhat = intdiv($hi, $bt);
            if ($qhat >= self::BASE) $qhat = self::BASE - 1;
            // correct the estimate in both directions (cheap: a few steps)
            while ($qhat > 0 && self::cmp(self::mul($b, [$qhat]), $rem) > 0) { $qhat--; }
            while (self::cmp(self::mul($b, [$qhat + 1]), $rem) <= 0) { $qhat++; }
            $qout[] = $qhat;
            $rem = self::sub($rem, self::mul($b, [$qhat]));
        }
        $q = array_reverse($qout);
        while (count($q) > 1 && end($q) === 0) array_pop($q);
        return [$q, $rem];
    }

    /** a ** e with a non-negative int exponent. */
    public static function powInt(array $a, int $e): array {
        $out = [1];
        $base = $a;
        while ($e > 0) {
            if ($e & 1) $out = self::mul($out, $base);
            $e >>= 1;
            if ($e > 0) $base = self::mul($base, $base);
        }
        return $out;
    }

    /** a ** e with a big exponent (halve the exponent step by step). */
    public static function powBig(array $a, array $e): array {
        $out = [1];
        $base = $a;
        $e2 = $e;
        while (!(count($e2) === 1 && $e2[0] === 0)) {
            [$e2, $bit] = self::divmod($e2, [2]);
            if ($bit[0] & 1) $out = self::mul($out, $base);
            if (!(count($e2) === 1 && $e2[0] === 0)) $base = self::mul($base, $base);
        }
        return $out;
    }

    /** Canonical dec -> digit string in base 2/8/16 (no sign, no prefix). */
    public static function toBase(string $dec, int $base): string {
        [$sign, $a] = self::limbs($dec);
        if (count($a) === 1 && $a[0] === 0) return '0';
        $digits = '0123456789abcdef';
        $out = '';
        while (!(count($a) === 1 && $a[0] === 0)) {
            [$a, $r] = self::divmod($a, [$base]);
            $out = $digits[$r[0]] . $out;
        }
        return $out;
    }

    /** Digit string in base 2..16 -> canonical dec string. */
    public static function fromBase(string $digits, int $base): string {
        $acc = [0];
        $B = [$base];
        foreach (str_split(strtolower(trim($digits))) as $ch) {
            $d = ctype_digit($ch) ? ord($ch) - 48 : ord($ch) - 87;
            if ($d < 0 || $d >= $base) {
                throw new PyHPValueError("invalid digit '{$ch}' for base {$base}");
            }
            $acc = self::add(self::mul($acc, $B), [$d]);
        }
        return self::dec($acc, 1);
    }
}

function pyhp_big_gmp(PyBigInt $x) { return gmp_init($x->dec); }
function pyhp_big_from_gmp($g): PyBigInt { return new PyBigInt(gmp_strval($g)); }

function pyhp_big_add(PyBigInt $a, PyBigInt $b): PyBigInt {
    if (pyhp_big_is_gmp()) return pyhp_big_from_gmp(gmp_add(pyhp_big_gmp($a), pyhp_big_gmp($b)));
    [$sa, $la] = PyBigMath::limbs($a->dec);
    [$sb, $lb] = PyBigMath::limbs($b->dec);
    if ($sa === $sb) return new PyBigInt(PyBigMath::dec(PyBigMath::add($la, $lb), $sa));
    $c = PyBigMath::cmp($la, $lb);
    if ($c === 0) return new PyBigInt('0');
    return $c > 0
        ? new PyBigInt(PyBigMath::dec(PyBigMath::sub($la, $lb), $sa))
        : new PyBigInt(PyBigMath::dec(PyBigMath::sub($lb, $la), $sb));
}

function pyhp_big_sub(PyBigInt $a, PyBigInt $b): PyBigInt {
    return pyhp_big_add($a, pyhp_big_neg($b));
}

function pyhp_big_neg(PyBigInt $a): PyBigInt {
    return new PyBigInt($a->dec === '0' ? '0' : ($a->dec[0] === '-' ? substr($a->dec, 1) : '-' . $a->dec));
}

function pyhp_big_mul(PyBigInt $a, PyBigInt $b): PyBigInt {
    if (pyhp_big_is_gmp()) return pyhp_big_from_gmp(gmp_mul(pyhp_big_gmp($a), pyhp_big_gmp($b)));
    [$sa, $la] = PyBigMath::limbs($a->dec);
    [$sb, $lb] = PyBigMath::limbs($b->dec);
    return new PyBigInt(PyBigMath::dec(PyBigMath::mul($la, $lb), $sa * $sb));
}

function pyhp_big_cmp(PyBigInt $a, PyBigInt $b): int {
    if (pyhp_big_is_gmp()) return gmp_cmp(pyhp_big_gmp($a), pyhp_big_gmp($b));
    [$sa, $la] = PyBigMath::limbs($a->dec);
    [$sb, $lb] = PyBigMath::limbs($b->dec);
    if ($sa !== $sb) return $sa <=> $sb;
    $c = PyBigMath::cmp($la, $lb);
    return $sa < 0 ? -$c : $c;
}

/** floor divmod: a = q*b + r with Python sign semantics -> [PyBigInt, PyBigInt] */
function pyhp_big_divmod(PyBigInt $a, PyBigInt $b): array {
    if ($b->dec === '0') throw new PyHPZeroDivisionError('division by zero');
    if (pyhp_big_is_gmp()) {
        $ga = pyhp_big_gmp($a); $gb = pyhp_big_gmp($b);
        $q = gmp_div_q($ga, $gb, GMP_ROUND_MINUSINF);
        $r = gmp_sub($ga, gmp_mul($q, $gb));
        return [pyhp_big_from_gmp($q), pyhp_big_from_gmp($r)];
    }
    [$sa, $la] = PyBigMath::limbs($a->dec);
    [$sb, $lb] = PyBigMath::limbs($b->dec);
    [$t, $rm] = PyBigMath::divmod($la, $lb);
    $rZero = count($rm) === 1 && $rm[0] === 0;
    if ($sa === $sb) {
        // same signs: floor == trunc; remainder carries a's (== b's) sign
        $q = new PyBigInt(PyBigMath::dec($t, 1));
        $r = $rZero ? new PyBigInt('0') : new PyBigInt(PyBigMath::dec($rm, $sb));
        return [$q, $r];
    }
    // different signs: floor = trunc + 1 unless exact; |r| = bmag - rmag
    $q = $rZero ? new PyBigInt('0') : new PyBigInt(PyBigMath::dec(PyBigMath::add($t, [1]), -1));
    $r = $rZero ? new PyBigInt('0') : new PyBigInt(PyBigMath::dec(PyBigMath::sub($lb, $rm), $sb));
    return [$q, $r];
}

function pyhp_big_mod(PyBigInt $a, PyBigInt $b): PyBigInt {
    return pyhp_big_divmod($a, $b)[1];
}

/** Big-int power. Exponent must be a non-negative int-ish value. */
function pyhp_big_pow(PyBigInt $a, $e): PyBigInt {
    if ($e instanceof PyBigInt) {
        if ($e->dec[0] === '-') throw new PyHPZeroDivisionError('0.0 cannot be raised to a negative power');
        if (pyhp_big_is_gmp()) {
            // exponentiate by squaring through gmp (exponent may exceed int range)
            $base = pyhp_big_gmp($a); $acc = gmp_init('1');
            $two = gmp_init('2');
            while (gmp_cmp($e, '0') > 0) {
                [$eq, $bit] = pyhp_big_divmod_e($e);
                if (gmp_strval($bit) === '1') $acc = gmp_mul($acc, $base);
                if (gmp_cmp($eq, '0') > 0) $base = gmp_mul($base, $base);
                $e = pyhp_big_from_gmp($eq);
            }
            return pyhp_big_from_gmp($acc);
        }
        [$sa, $la] = PyBigMath::limbs($a->dec);
        [$se, $le] = PyBigMath::limbs($e->dec);
        $neg = $sa < 0 && PyBigMath::divmod($le, [2])[1][0] === 1;
        return new PyBigInt(PyBigMath::dec(PyBigMath::powBig($la, $le), $neg ? -1 : 1));
    }
    $i = is_bool($e) ? (int)$e : (int)$e;
    if ($i < 0) throw new PyHPZeroDivisionError('0.0 cannot be raised to a negative power');
    if ($i > 100000000) throw new PyHPValueError('pow(): exponent too large');
    if (pyhp_big_is_gmp()) return pyhp_big_from_gmp(gmp_pow(pyhp_big_gmp($a), $i));
    [$sa, $la] = PyBigMath::limbs($a->dec);
    $neg = $sa < 0 && ($i & 1) === 1;
    return new PyBigInt(PyBigMath::dec(PyBigMath::powInt($la, $i), $neg ? -1 : 1));
}

/** helper: divmod a big by 2 for the gmp squaring loop */
function pyhp_big_divmod_e($e): array {
    $q = gmp_div_q($e, 2, GMP_ROUND_MINUSINF);
    $r = gmp_sub($e, gmp_mul($q, 2));
    return [$q, $r];
}

function pyhp_big_str(PyBigInt $x): string { return $x->dec; }

/* =========================================================================
 * 6. Arithmetic / comparison operators
 * ========================================================================= */

function pyhp_is_num($v): bool { return is_int($v) || is_float($v) || is_bool($v); }

function pyhp_num($v) { return is_bool($v) ? (int)$v : $v; }

function pyhp_big_or_num($v): bool { return pyhp_is_num($v) || $v instanceof PyBigInt; }

function pyhp_add($a, $b) {
    if ($a instanceof PyBigInt || $b instanceof PyBigInt) {
        if (pyhp_int_like($a) && pyhp_int_like($b)) return pyhp_big_add(pyhp_to_big($a), pyhp_to_big($b));
        if (pyhp_big_or_num($a) && pyhp_big_or_num($b)) {
            $fa = $a instanceof PyBigInt ? pyhp_big_to_float($a) : pyhp_num($a);
            $fb = $b instanceof PyBigInt ? pyhp_big_to_float($b) : pyhp_num($b);
            return $fa + $fb;
        }
    }
    if (pyhp_is_num($a) && pyhp_is_num($b)) {
        $x = pyhp_num($a); $y = pyhp_num($b);
        if (is_int($x) && is_int($y)) {
            $r = $x + $y;
            if (is_float($r)) {   // 64-bit overflow -> promote to big-int (Python style)
                return pyhp_big_add(new PyBigInt((string)$x), new PyBigInt((string)$y));
            }
            return $r;
        }
        return $x + $y;
    }
    if (is_string($a) || is_string($b)) return pyhp_tostr($a) . pyhp_tostr($b);
    if ($a instanceof PyList && $b instanceof PyList) return new PyList(array_merge($a->a, $b->a));
    if ($a instanceof PyDict && $b instanceof PyDict) return new PyDict(array_replace($a->d, $b->d));
    throw new PyHPTypeError("unsupported operand type(s) for +: '" . pyhp_type_name($a) . "' and '" . pyhp_type_name($b) . "'");
}
function pyhp_sub($a, $b) {
    if ($a instanceof PyBigInt || $b instanceof PyBigInt) {
        if (pyhp_int_like($a) && pyhp_int_like($b)) return pyhp_big_sub(pyhp_to_big($a), pyhp_to_big($b));
        if (pyhp_big_or_num($a) && pyhp_big_or_num($b)) {
            $fa = $a instanceof PyBigInt ? pyhp_big_to_float($a) : pyhp_num($a);
            $fb = $b instanceof PyBigInt ? pyhp_big_to_float($b) : pyhp_num($b);
            return $fa - $fb;
        }
    }
    if (pyhp_is_num($a) && pyhp_is_num($b)) {
        $x = pyhp_num($a); $y = pyhp_num($b);
        if (is_int($x) && is_int($y)) {
            $r = $x - $y;
            if (is_float($r)) return pyhp_big_sub(new PyBigInt((string)$x), new PyBigInt((string)$y));
            return $r;
        }
        return $x - $y;
    }
    throw new PyHPTypeError("unsupported operand type(s) for -: '" . pyhp_type_name($a) . "' and '" . pyhp_type_name($b) . "'");
}
function pyhp_mul($a, $b) {
    if ($a instanceof PyBigInt || $b instanceof PyBigInt) {
        if (pyhp_int_like($a) && pyhp_int_like($b)) return pyhp_big_mul(pyhp_to_big($a), pyhp_to_big($b));
        if (pyhp_big_or_num($a) && pyhp_big_or_num($b)) {
            $fa = $a instanceof PyBigInt ? pyhp_big_to_float($a) : pyhp_num($a);
            $fb = $b instanceof PyBigInt ? pyhp_big_to_float($b) : pyhp_num($b);
            return $fa * $fb;
        }
    }
    if (pyhp_is_num($a) && pyhp_is_num($b)) {
        $x = pyhp_num($a); $y = pyhp_num($b);
        if (is_int($x) && is_int($y)) {
            $r = $x * $y;
            if (is_float($r)) return pyhp_big_mul(new PyBigInt((string)$x), new PyBigInt((string)$y));
            return $r;
        }
        return $x * $y;
    }
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
    if ($a instanceof PyBigInt || $b instanceof PyBigInt) {
        if (!pyhp_big_or_num($a) || !pyhp_big_or_num($b)) {
            throw new PyHPTypeError("unsupported operand type(s) for /: '" . pyhp_type_name($a) . "' and '" . pyhp_type_name($b) . "'");
        }
        $fa = $a instanceof PyBigInt ? pyhp_big_to_float($a) : pyhp_num($a);
        $fb = $b instanceof PyBigInt ? pyhp_big_to_float($b) : pyhp_num($b);
        if ($fb == 0) throw new PyHPZeroDivisionError('division by zero');
        return $fa / $fb;
    }
    if (pyhp_is_num($a) && pyhp_is_num($b)) {
        $x = pyhp_num($a); $y = pyhp_num($b);
        if ($y == 0) throw new PyHPZeroDivisionError('division by zero');
        return $x / $y;
    }
    throw new PyHPTypeError("unsupported operand type(s) for /: '" . pyhp_type_name($a) . "' and '" . pyhp_type_name($b) . "'");
}
function pyhp_mod($a, $b) {
    if ($a instanceof PyBigInt || $b instanceof PyBigInt) {
        if (pyhp_int_like($a) && pyhp_int_like($b)) {
            $ba = pyhp_to_big($a); $bb = pyhp_to_big($b);
            if ($bb->dec === '0') throw new PyHPZeroDivisionError('modulo by zero');
            return pyhp_big_mod($ba, $bb);
        }
        if (pyhp_big_or_num($a) && pyhp_big_or_num($b)) {
            $x = $a instanceof PyBigInt ? pyhp_big_to_float($a) : pyhp_num($a);
            $y = $b instanceof PyBigInt ? pyhp_big_to_float($b) : pyhp_num($b);
            if ($y == 0) throw new PyHPZeroDivisionError('modulo by zero');
            return $x - $y * floor($x / $y);
        }
    }
    if (pyhp_is_num($a) && pyhp_is_num($b)) {
        $x = pyhp_num($a); $y = pyhp_num($b);
        if ($y == 0) throw new PyHPZeroDivisionError('modulo by zero');
        if (is_int($x) && is_int($y)) {
            // exact 64-bit path — the float fallback loses precision on big ints
            $r = $x % $y;
            if ($r !== 0 && (($r < 0) !== ($y < 0))) $r += $y;
            return $r;
        }
        $r = $x - $y * floor($x / $y);           // Python-style sign semantics
        return $r;
    }
    throw new PyHPTypeError("unsupported operand type(s) for %: '" . pyhp_type_name($a) . "' and '" . pyhp_type_name($b) . "'");
}
function pyhp_pow($a, $b) {
    $aBig = $a instanceof PyBigInt; $bBig = $b instanceof PyBigInt;
    if ($aBig || $bBig) {
        if (!pyhp_int_like($a) && !pyhp_is_num($a)) {
            throw new PyHPTypeError("unsupported operand type(s) for **: '" . pyhp_type_name($a) . "' and '" . pyhp_type_name($b) . "'");
        }
        if (pyhp_int_like($a) && pyhp_int_like($b)) {
            $bNeg = $bBig ? $b->dec[0] === '-' : pyhp_num($b) < 0;
            if ($bNeg) {
                $aZero = $aBig ? $a->dec === '0' : (pyhp_num($a) == 0);
                if ($aZero) {
                    throw new PyHPZeroDivisionError('0.0 cannot be raised to a negative power');
                }
                $fa = $aBig ? pyhp_big_to_float($a) : (float)pyhp_num($a);
                $fb = $bBig ? pyhp_big_to_float($b) : (float)pyhp_num($b);
                return $fa ** $fb;
            }
            return pyhp_big_pow(pyhp_to_big($a), pyhp_to_big($b));
        }
        $fa = $a instanceof PyBigInt ? pyhp_big_to_float($a) : pyhp_num($a);
        $fb = $b instanceof PyBigInt ? pyhp_big_to_float($b) : pyhp_num($b);
        return $fa ** $fb;
    }
    if (pyhp_is_num($a) && pyhp_is_num($b)) {
        $x = pyhp_num($a); $y = pyhp_num($b);
        if (is_int($x) && is_int($y)) {
            if ($y < 0) {
                if ($x === 0) throw new PyHPZeroDivisionError('0.0 cannot be raised to a negative power');
                return (float)$x ** $y;
            }
            $r = $x ** $y;
            if (is_float($r)) return pyhp_big_pow(new PyBigInt((string)$x), $y);
            return $r;
        }
        return pyhp_num($a) ** pyhp_num($b);
    }
    throw new PyHPTypeError("unsupported operand type(s) for **: '" . pyhp_type_name($a) . "' and '" . pyhp_type_name($b) . "'");
}
function pyhp_bitcheck($a, $op) {
    $a = pyhp_num($a);
    if (!is_int($a)) {
        throw new PyHPTypeError("bitwise {$op} requires int operands, got '" . pyhp_type_name($a) . "'");
    }
    return $a;
}

/** Big-int bitwise (two's-complement semantics like Python) — gmp only. */
function pyhp_big_bitargs($a, $b, string $op): array {
    if (!pyhp_big_is_gmp()) {
        throw new PyHPTypeError("big-int {$op} needs the gmp PHP extension (arithmetic on big ints works without it)");
    }
    if (!pyhp_int_like($a) || !pyhp_int_like($b)) {
        throw new PyHPTypeError("bitwise {$op} requires int operands, got '" . pyhp_type_name($a) . "' and '" . pyhp_type_name($b) . "'");
    }
    return [pyhp_big_gmp(pyhp_to_big($a)), pyhp_big_gmp(pyhp_to_big($b))];
}
function pyhp_bitand($a, $b) {
    if ($a instanceof PyBigInt || $b instanceof PyBigInt) {
        [$ga, $gb] = pyhp_big_bitargs($a, $b, 'AND (&)'); return pyhp_big_from_gmp(gmp_and($ga, $gb));
    }
    return pyhp_bitcheck($a, 'AND (&)') & pyhp_bitcheck($b, 'AND (&)');
}
function pyhp_bitor($a, $b) {
    if ($a instanceof PyBigInt || $b instanceof PyBigInt) {
        [$ga, $gb] = pyhp_big_bitargs($a, $b, 'OR (|)'); return pyhp_big_from_gmp(gmp_or($ga, $gb));
    }
    return pyhp_bitcheck($a, 'OR (|)') | pyhp_bitcheck($b, 'OR (|)');
}
function pyhp_bitxor($a, $b) {
    if ($a instanceof PyBigInt || $b instanceof PyBigInt) {
        [$ga, $gb] = pyhp_big_bitargs($a, $b, 'XOR (^)'); return pyhp_big_from_gmp(gmp_xor($ga, $gb));
    }
    return pyhp_bitcheck($a, 'XOR (^)') ^ pyhp_bitcheck($b, 'XOR (^)');
}
function pyhp_big_shiftcount($b, string $op): int {
    if ($b instanceof PyBigInt) {
        if (!pyhp_big_fits_int($b->dec)) throw new PyHPValueError('shift count too large');
        return (int)$b->dec;
    }
    return pyhp_bitcheck($b, "shift ({$op})");
}
function pyhp_lshift($a, $b) {
    if ($a instanceof PyBigInt || $b instanceof PyBigInt) {
        [$ga] = pyhp_big_bitargs($a, $a, 'shift (<<)');
        $n = pyhp_big_shiftcount($b, '<<');
        if ($n < 0) throw new PyHPValueError('negative shift count');
        return pyhp_big_from_gmp(gmp_shiftl($ga, $n));
    }
    $x = pyhp_bitcheck($a, 'shift (<<)'); $n = pyhp_bitcheck($b, 'shift (<<)');
    if ($n < 0) throw new PyHPValueError('negative shift count');
    return $x << $n;
}
function pyhp_rshift($a, $b) {
    if ($a instanceof PyBigInt || $b instanceof PyBigInt) {
        [$ga] = pyhp_big_bitargs($a, $a, 'shift (>>)');
        $n = pyhp_big_shiftcount($b, '>>');
        if ($n < 0) throw new PyHPValueError('negative shift count');
        return pyhp_big_from_gmp(gmp_shiftr($ga, $n));
    }
    $x = pyhp_bitcheck($a, 'shift (>>)'); $n = pyhp_bitcheck($b, 'shift (>>)');
    if ($n < 0) throw new PyHPValueError('negative shift count');
    return $x >> $n;
}
function pyhp_bitnot($a) {
    if ($a instanceof PyBigInt) {
        if (!pyhp_big_is_gmp()) {
            throw new PyHPTypeError('big-int bitwise NOT (~) needs the gmp PHP extension');
        }
        return pyhp_big_from_gmp(gmp_com(pyhp_big_gmp($a)));
    }
    return ~pyhp_bitcheck($a, 'NOT (~)');
}
function pyhp_neg($a) {
    if ($a instanceof PyBigInt) return pyhp_big_neg($a);
    if (is_int($a)) {
        if ($a === PHP_INT_MIN) return new PyBigInt('9223372036854775808');  // -PHP_INT_MIN overflows 64-bit
        return -$a;
    }
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
    if ($a instanceof PyBigInt && $b instanceof PyBigInt) return $a->dec === $b->dec;
    if ($a instanceof PyBigInt || $b instanceof PyBigInt) {
        $big = $a instanceof PyBigInt ? $a : $b;
        $other = $a instanceof PyBigInt ? $b : $a;
        if (pyhp_int_like($other)) return $big->dec === pyhp_to_big($other)->dec;
        if (is_float($other)) {
            $f = pyhp_big_to_float($big);   // OverflowError propagates like Python's ==
            return $f == $other;
        }
        return false;
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
    if ($a instanceof PyBigInt || $b instanceof PyBigInt) {
        if ($a instanceof PyBigInt && $b instanceof PyBigInt) return pyhp_big_cmp($a, $b);
        $big = $a instanceof PyBigInt ? $a : $b;
        $other = $a instanceof PyBigInt ? $b : $a;
        $c = 0;
        if (pyhp_int_like($other)) {
            $c = pyhp_big_cmp($big, pyhp_to_big($other));
        } elseif (is_float($other)) {
            if (is_infinite($other)) {
                $c = $other > 0 ? -1 : 1;             // any finite int < +inf, > -inf
            } elseif (is_nan($other)) {
                throw new PyHPTypeError("'<' not supported between instances of 'int' and 'float'");
            } else {
                $f = (float)$big->dec;
                if (is_infinite($f)) {
                    $c = $big->dec[0] === '-' ? -1 : 1;  // beyond float range: exact sign decides
                } else {
                    $c = $f <=> $other;
                }
            }
        } else {
            throw new PyHPTypeError("'<' not supported between instances of '" . pyhp_type_name($a) . "' and '" . pyhp_type_name($b) . "'");
        }
        return $a instanceof PyBigInt ? $c : -$c;
    }
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
    if ($hay instanceof PyHPView && $hay->kind === 'range') {
        if (!pyhp_int_like($needle)) {
            foreach ($hay->getIterator() as $x) { if (pyhp_eq($needle, $x)) return true; }
            return false;
        }
        [$s, $e, $st] = [$hay->args[0], $hay->args[1], $hay->args[2]];
        if ($s instanceof PyBigInt || $e instanceof PyBigInt || $st instanceof PyBigInt || $needle instanceof PyBigInt) {
            $n = pyhp_to_big($needle);
            $sb = pyhp_to_big($s);
            $stb = pyhp_to_big($st);
            $d = pyhp_big_sub($n, $sb);
            $zero = new PyBigInt('0');
            $negStep = $stb->dec[0] === '-';
            if ($negStep) {
                if (pyhp_big_cmp($n, $sb) > 0 || pyhp_big_cmp($n, pyhp_to_big($e)) <= 0) return false;
            } else {
                if (pyhp_big_cmp($n, $sb) < 0 || pyhp_big_cmp($n, pyhp_to_big($e)) >= 0) return false;
            }
            [$q, $r] = pyhp_big_divmod($d, $stb);
            return $r->dec === '0';
        }
        if ($st > 0) {
            if ($needle < $s || $needle >= $e) return false;
            return ($needle - $s) % $st === 0;
        }
        if ($needle > $s || $needle <= $e) return false;
        return ($s - $needle) % (-$st) === 0;
    }
    if ($hay instanceof PyHPView) {
        foreach ($hay->getIterator() as $x) { if (pyhp_eq($needle, $x)) return true; }
        return false;
    }
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
    if ($v instanceof PyHPView || $v instanceof PyHPGenerator) {
        $out = [];
        foreach ($v->getIterator() as $x) { $out[] = $x; }
        return $out;
    }
    // iterator protocol (transpiled classes, or interpreter instances via hook)
    if (is_object($v) && (method_exists($v, '__iter__') || method_exists($v, '__next__'))) {
        return pyhp_foreach($v);
    }
    $hook = $GLOBALS['__pyhp_iter_hook'] ?? null;
    if ($hook !== null) {
        $items = $hook($v);
        if ($items !== null) return $items;
    }
    throw new PyHPTypeError("'" . pyhp_type_name($v) . "' object is not iterable");
}

/** Normalize a value for foreach: returns [key => value] map. */
/** Did this thrown value signal the end of iteration? Covers the compiled
 *  engine (real PyHPStopIteration) and the interpreter (raise of a user
 *  StopIteration class wrapped in PyHPThrow). */
function pyhp_throw_is_stop($t): bool {
    if ($t instanceof PyHPStopIteration) return true;
    if ($t instanceof PyHPThrow && isset($t->value) && $t->value instanceof InterpInstance) {
        for ($c = $t->value->cls; $c !== null; $c = $c->parent) {
            if ($c->name === 'StopIteration') return true;
        }
    }
    return false;
}

function pyhp_foreach($v): array {
    if ($v instanceof PyList) return array_values($v->a);
    if ($v instanceof PyDict) return $v->d;
    if (is_string($v)) return pyhp_chars($v);
    if ($v instanceof PyHPView || $v instanceof PyHPGenerator) {
        $out = [];
        foreach ($v->getIterator() as $k => $x) { $out[$k] = $x; }
        return $out;
    }
    // iterator protocol for transpiled user classes: __iter__() -> __next__()
    if (is_object($v) && !($v instanceof Closure)) {
        $it = $v;
        if (method_exists($v, '__iter__')) {
            $it = $v->__iter__();
            if (!is_object($it) && !($it instanceof PyList)) {
                throw new PyHPTypeError('__iter__ must return an object or list');
            }
            if ($it instanceof PyList) return array_values($it->a);
        }
        if (is_object($it) && method_exists($it, '__next__')) {
            $out = [];
            try {
                while (true) { $out[] = $it->__next__(); }
            } catch (PyHPStopIteration $e) {
            } catch (PyHPThrow $t) {
                if (!pyhp_throw_is_stop($t)) throw $t;
            }
            return $out;
        }
    }
    throw new PyHPTypeError("'" . pyhp_type_name($v) . "' object is not iterable");
}

function pyhp_guard_key($k) {
    if (is_int($k) || is_string($k)) return $k;
    if ($k instanceof PyBigInt) return $k->dec;   // big-int dict keys round-trip as decimal strings
    throw new PyHPTypeError('dict keys must be int or string, got ' . pyhp_type_name($k));
}

/** Build a guarded PHP key => value map from a flat [k1, v1, k2, v2, ...] list. */
function pyhp_dict_pairs(array $flat): array {
    $out = [];
    for ($i = 0; $i + 1 < count($flat); $i += 2) {
        $out[pyhp_guard_key($flat[$i])] = $flat[$i + 1];
    }
    return $out;
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
    if ($c instanceof PyHPView && $c->kind === 'range') {
        return $c->rangeGet($i);
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
    if ($c instanceof PyHPView && $c->kind === 'range') { return pyhp_range_slice($c, $start, $stop, $step); }
    throw new PyHPTypeError("'" . pyhp_type_name($c) . "' object is not sliceable");
}

/** Slice a lazy range view into another lazy range view (like Python). */
function pyhp_range_slice(PyHPView $v, $start, $stop, $step): PyHPView {
    [$s, , $st] = [$v->args[0], $v->args[1], $v->args[2]];
    $stp = $step === null ? 1 : pyhp_range_int($step, 'range slice');
    if ($stp === 0) throw new PyHPValueError('slice step cannot be zero');
    $n = pyhp_to_big($v->rangeLen());
    $S = pyhp_to_big($s);
    $ST = pyhp_to_big($st);
    $zero = new PyBigInt('0');
    $one = new PyBigInt('1');
    $clampLo = function ($x) use ($n, $zero) {   // max(0, min(x, n)) after negative shift
        if (pyhp_big_cmp($x, $zero) < 0) $x = pyhp_big_add($n, $x);
        if (pyhp_big_cmp($x, $zero) < 0) $x = $zero;
        if (pyhp_big_cmp($x, $n) > 0) $x = $n;
        return $x;
    };
    $clampHi = function ($x) use ($n, $zero) {   // min(x, n) for positive-step stops
        if (pyhp_big_cmp($x, $n) > 0) $x = $n;
        return $x;
    };
    if ($stp > 0) {
        $si = $start === null ? $zero : $clampLo(pyhp_to_big($start));
        $ei = $stop === null ? $n : $clampLo(pyhp_to_big($stop));
    } else {
        $si = $start === null ? pyhp_big_sub($n, $one) : $clampLo(pyhp_to_big($start));
        if ($start !== null && pyhp_big_cmp(pyhp_to_big($start), pyhp_big_sub($n, $one)) > 0) {
            $si = pyhp_big_sub($n, $one);
        }
        $ei = $stop === null ? pyhp_big_neg($one) : $clampLo(pyhp_to_big($stop));
        if ($stop !== null && pyhp_big_cmp(pyhp_to_big($stop), pyhp_big_neg($one)) < 0) {
            $ei = pyhp_big_neg($one);
        }
    }
    $newS = pyhp_big_add($S, pyhp_big_mul($ST, $si));
    $newE = pyhp_big_add($S, pyhp_big_mul($ST, $ei));
    $newSt = pyhp_big_mul($ST, new PyBigInt((string)$stp));
    $mk = function ($x) {
        return pyhp_big_fits_int($x->dec) ? (int)$x->dec : $x;
    };
    return new PyHPView('range', [$mk($newS), $mk($newE), $mk($newSt)]);
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

function pyhp_call_method($obj, string $name, array $args, array $kwargs = []) {
    try {
        if (is_object($obj) && method_exists($obj, $name)) {
            if (count($kwargs) > 0) {
                // reflection-based binding: covers transpiled user classes and
                // builtin collection methods alike
                return pyhp_call_kw_method($obj, $name, $args, $kwargs, $name);
            }
            return $obj->$name(...$args);
        }
        // dict stored callables are reachable via dot-call (module tables etc.)
        if ($obj instanceof PyDict && array_key_exists($name, $obj->d) && is_callable($obj->d[$name])
            && !($obj->d[$name] instanceof PyList) && !($obj->d[$name] instanceof PyDict)) {
            return pyhp_callfunc($obj->d[$name], $args, $name, $kwargs);
        }
        if (is_string($obj)) {
            if (count($kwargs) > 0) {
                throw new PyHPTypeError("string methods do not accept keyword arguments yet ('{$name}()')");
            }
            return pyhp_str_method($obj, $name, $args);
        }
    } catch (ArgumentCountError $e) {
        throw new PyHPTypeError("{$name}(): wrong number of arguments — " . pyhp_first_line($e->getMessage()));
    } catch (TypeError $e) {
        throw new PyHPTypeError("{$name}(): " . pyhp_first_line($e->getMessage()));
    }
    throw new PyHPTypeError("'" . pyhp_type_name($obj) . "' object has no method '{$name}'");
}

/** Unified member READ used by transpiled code: instance props/methods,
 *  dict keys (module tables), then error — mirroring the interpreter. */
function pyhp_member($obj, string $name) {
    if (is_object($obj)) {
        if (property_exists($obj, $name)) return $obj->$name;
        if (method_exists($obj, $name)) return fn(...$a) => pyhp_call_method($obj, $name, $a);
        if ($obj instanceof PyDict && array_key_exists($name, $obj->d)) return $obj->d[$name];
    }
    throw new PyHPTypeError("'" . pyhp_type_name($obj) . "' object has no property '{$name}'");
}

function pyhp_first_line(string $s): string {
    $p = strpos($s, "\n");
    return $p === false ? $s : substr($s, 0, $p);
}

function pyhp_callfunc($c, array $args, string $name, array $kwargs = []) {
    if (!is_callable($c)) {
        throw new PyHPTypeError("'" . $name . "' is not callable (" . pyhp_type_name($c) . ")");
    }
    if (count($kwargs) > 0) {
        if ($c instanceof InterpFunc) {
            return $c->interp->invokeFunc($c, $args, $kwargs);
        }
        throw new PyHPTypeError("'{$name}' does not accept keyword arguments");
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
        case 'int':    if (!is_int($v) && !($v instanceof PyBigInt))  throw new PyHPTypeError("type mismatch: cannot assign " . pyhp_type_name($v) . " to int variable '{$name}'"); break;
        case 'float':
            if ($v instanceof PyBigInt) return pyhp_big_to_float($v);   // big-int widening, OverflowError past float range
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

/** Resolve a require() spec to a real .pyh file path.
 *  - "./x.pyh", "../x.pyh", "/abs/x.pyh" -> relative to $baseDir (caller file)
 *  - "name"                               -> ./vendor/name, then the registry
 *  Returns the canonical path or null. */
function pyhp_resolve_module(string $spec, string $baseDir): ?string {
    $norm = str_replace('\\', '/', $spec);
    $isPath = str_starts_with($norm, './') || str_starts_with($norm, '../') || str_starts_with($norm, '/')
        || preg_match('#^[A-Za-z]:/#', $norm) === 1
        || (str_contains($norm, '/') && str_ends_with($norm, '.pyh'));
    if ($isPath) {
        $p = $norm[0] === '/' ? $norm : rtrim($baseDir, '/\\') . '/' . $norm;
        $rp = realpath($p);
        return ($rp !== false && is_file($rp) && str_ends_with(strtolower($rp), '.pyh')) ? $rp : null;
    }
    if (!preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]*$/', $spec)) return null; // not a package name
    $roots = [ rtrim($baseDir, '/\\') . '/vendor/' . $spec ];
    $reg = getenv('PYHP_REGISTRY');
    if ($reg !== false && $reg !== '') $roots[] = rtrim($reg, '/\\') . '/' . $spec;
    // stdlib modules bundled with the language (lib/ai.pyh, lib/sec.pyh, lib/iot.pyh)
    $roots[] = dirname(__DIR__) . '/lib';
    $roots[] = dirname(__DIR__) . '/registry/' . $spec;
    foreach ($roots as $dir) {
        if (!is_dir($dir)) continue;
        $main = null;
        $man = $dir . '/pyhp.json';
        if (is_file($man)) {
            $j = json_decode((string)file_get_contents($man), true);
            if (is_array($j) && isset($j['main']) && is_string($j['main']) && $j['main'] !== '') $main = $j['main'];
        }
        $tries = [];
        if ($main !== null) $tries[] = $main;
        array_push($tries, "src/{$spec}.pyh", 'main.pyh', "{$spec}.pyh");
        foreach ($tries as $t) {
            if (str_contains($t, '..')) continue;
            $rp = realpath($dir . '/' . $t);
            if ($rp !== false && is_file($rp)) return $rp;
        }
        // the stdlib root holds bare files: lib/<name>.pyh
        if ($dir === dirname(__DIR__) . '/lib') {
            $rp = realpath($dir . '/' . $spec . '.pyh');
            if ($rp !== false && is_file($rp)) return $rp;
        }
    }
    return null;
}

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
            if ($v instanceof PyHPView && $v->kind === 'range') return $v->rangeLen();   // exact, big included
            throw new PyHPTypeError("len() of unsupported type '" . pyhp_type_name($v) . "'");
        }, 1, 1],
        'range' => [function (...$a) {
            pyhp_reg_errcheck($a, 'range', 1, 3);
            $vals = array_map(fn($x) => pyhp_range_bound($x, 'range'), $a);
            if (count($vals) === 1) { $s = 0; $e = $vals[0]; $st = 1; }
            elseif (count($vals) === 2) { $s = $vals[0]; $e = $vals[1]; $st = 1; }
            else { $s = $vals[0]; $e = $vals[1]; $st = $vals[2]; }
            $stZero = $st instanceof PyBigInt ? $st->dec === '0' : $st === 0;
            if ($stZero) throw new PyHPValueError('range() step cannot be zero');
            return new PyHPView('range', [$s, $e, $st]);   // lazy — streams, supports big bounds
        }, 1, 3],
        'map' => [function (...$a) {
            pyhp_reg_errcheck($a, 'map', 2, null);
            if (!is_callable($a[0])) throw new PyHPTypeError("map(): 'callback' must be callable, got " . pyhp_type_name($a[0]));
            return new PyHPView('map', $a);   // lazy view
        }, 2, null],
        'filter' => [function (...$a) {
            pyhp_reg_errcheck($a, 'filter', 2, 2);
            if (!is_callable($a[0])) throw new PyHPTypeError("filter(): 'callback' must be callable, got " . pyhp_type_name($a[0]));
            return new PyHPView('filter', $a);   // lazy view
        }, 2, 2],
        'zip' => [function (...$a) {
            pyhp_reg_errcheck($a, 'zip', 1, null);
            return new PyHPView('zip', $a);   // lazy view
        }, 1, null],
        'enumerate' => [function (...$a) {
            pyhp_reg_errcheck($a, 'enumerate', 1, 2);
            $start = isset($a[1]) ? pyhp_range_bound($a[1], 'enumerate') : 0;
            return new PyHPView('enumerate', [$a[0], $start]);   // lazy view
        }, 1, 2],
        'sorted' => [function (...$a) {
            pyhp_reg_errcheck($a, 'sorted', 1, 1);
            return pyhp_sorted_impl($a[0], null, false);
        }, 1, 1],
        'reversed' => [function (...$a) {
            pyhp_reg_errcheck($a, 'reversed', 1, 1);
            $v = $a[0];
            if ($v instanceof PyList || is_string($v)
                || ($v instanceof PyHPView && $v->kind === 'range')) {
                return new PyHPView('reversed', [$v]);   // lazy view over the sequence
            }
            throw new PyHPTypeError('reversed() requires a list, string or range, got ' . pyhp_type_name($v));
        }, 1, 1],
        'next' => [function (...$a) {
            pyhp_reg_errcheck($a, 'next', 1, 2);
            $it = pyhp_iter($a[0]);
            if (!$it->valid()) {
                if (count($a) === 2) return $a[1];
                throw new PyHPStopIteration();
            }
            $v = $it->current();
            $it->next();
            return $v;
        }, 1, 2],
        'sum' => [function (...$a) {
            pyhp_reg_errcheck($a, 'sum', 1, 2);
            $acc = $a[1] ?? 0;
            if (!pyhp_big_or_num($acc)) throw new PyHPTypeError('sum() start must be a number');
            foreach (pyhp_iter_values($a[0]) as $v) {
                if (!pyhp_big_or_num($v)) throw new PyHPTypeError('sum() requires numbers, got ' . pyhp_type_name($v));
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
            $v = $a[0];
            if ($v instanceof PyBigInt) return new PyBigInt(ltrim($v->dec, '-'));
            if (!pyhp_is_num($v)) throw new PyHPTypeError('abs() requires a number');
            $n = pyhp_num($v);
            if (is_int($n) && $n === PHP_INT_MIN) return new PyBigInt('9223372036854775808');
            return abs($n);
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
            $v = $a[0];
            if ($v instanceof PyBigInt) {
                $digits = pyhp_big_is_gmp() ? gmp_strval(pyhp_big_gmp($v), 16) : PyBigMath::toBase(ltrim($v->dec, '-'), 16);
                return ($v->dec[0] === '-' ? '-0x' : '0x') . strtolower(ltrim($digits, '-'));
            }
            $n = pyhp_bitcheck($v, 'hex()');
            return ($n < 0 ? '-0x' : '0x') . strtolower(dechex(abs($n)));
        }, 1, 1],
        'bin' => [function (...$a) {
            pyhp_reg_errcheck($a, 'bin', 1, 1);
            $v = $a[0];
            if ($v instanceof PyBigInt) {
                $digits = pyhp_big_is_gmp() ? gmp_strval(pyhp_big_gmp($v), 2) : PyBigMath::toBase(ltrim($v->dec, '-'), 2);
                return ($v->dec[0] === '-' ? '-0b' : '0b') . ltrim($digits, '-');
            }
            $n = pyhp_bitcheck($v, 'bin()');
            return ($n < 0 ? '-0b' : '0b') . decbin(abs($n));
        }, 1, 1],

        /* system access ("C DNA") */
        'exec' => [function (...$a) {
            pyhp_reg_errcheck($a, 'exec', 1, 1);
            if (!is_string($a[0])) throw new PyHPTypeError('exec() expects a command string');
            $outLines = [];
            $code = 0;
            @exec($a[0] . ' 2>&1', $outLines, $code);
            // Windows console output keeps a trailing \r per line — strip it
            $outLines = array_map(function ($l) { return rtrim($l, "\r"); }, $outLines);
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

        /* json + crypto + text encoding (AI / security / IoT toolkit) */
        'json_parse' => [function (...$a) {
            pyhp_reg_errcheck($a, 'json_parse', 1, 1);
            $v = json_decode((string)pyhp_strcast_arg($a[0], 'json_parse'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new PyHPValueError('json_parse(): invalid JSON — ' . json_last_error_msg());
            }
            return pyhp_json_to_val($v);
        }, 1, 1],
        'json_string' => [function (...$a) {
            pyhp_reg_errcheck($a, 'json_string', 1, 1);
            $s = json_encode(pyhp_val_to_json($a[0]),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            if ($s === false) throw new PyHPValueError('json_string(): value is not JSON-serializable');
            return $s;
        }, 1, 1],
        'md5' => [function (...$a) {
            pyhp_reg_errcheck($a, 'md5', 1, 1);
            return md5(pyhp_strcast_arg($a[0], 'md5'));
        }, 1, 1],
        'sha256' => [function (...$a) {
            pyhp_reg_errcheck($a, 'sha256', 1, 1);
            return hash('sha256', pyhp_strcast_arg($a[0], 'sha256'));
        }, 1, 1],
        'crc32' => [function (...$a) {
            pyhp_reg_errcheck($a, 'crc32', 1, 1);
            return hash('crc32b', pyhp_strcast_arg($a[0], 'crc32'));
        }, 1, 1],
        'base64e' => [function (...$a) {
            pyhp_reg_errcheck($a, 'base64e', 1, 1);
            return base64_encode(pyhp_strcast_arg($a[0], 'base64e'));
        }, 1, 1],
        'base64d' => [function (...$a) {
            pyhp_reg_errcheck($a, 'base64d', 1, 1);
            $d = base64_decode(pyhp_strcast_arg($a[0], 'base64d'), true);
            if ($d === false) throw new PyHPValueError('base64d(): invalid base64 input');
            return $d;
        }, 1, 1],
        'urlenc' => [fn(...$a) => (pyhp_reg_errcheck($a, 'urlenc', 1, 1) ?: rawurlencode(pyhp_strcast_arg($a[0], 'urlenc'))), 1, 1],
        'urldec' => [fn(...$a) => (pyhp_reg_errcheck($a, 'urldec', 1, 1) ?: rawurldecode(pyhp_strcast_arg($a[0], 'urldec'))), 1, 1],
    ];

    return $reg;
}

/** PHP json_decode(true) structure -> PyHP value (lists/dicts). */
function pyhp_json_to_val($v) {
    if (is_array($v)) {
        $isList = array_is_list($v);
        if ($isList) { return new PyList(array_map(fn($x) => pyhp_json_to_val($x), $v)); }
        $d = [];
        foreach ($v as $k => $x) { $d[$k] = pyhp_json_to_val($x); }
        return new PyDict($d);
    }
    return $v;
}

/** PyHP value -> PHP structure json_encode understands. */
function pyhp_val_to_json($v) {
    if ($v instanceof PyList) {
        return array_map(fn($x) => pyhp_val_to_json($x), $v->a);
    }
    if ($v instanceof PyDict) {
        $d = [];
        foreach ($v->d as $k => $x) { $d[(string)$k] = pyhp_val_to_json($x); }
        return $d;
    }
    if (is_object($v) || is_array($v)) {
        if ($v instanceof PyBigInt) {
            throw new PyHPValueError('json_string(): cannot serialize big-int (PHP JSON has no big number type) — convert with str() first');
        }
        throw new PyHPValueError('json_string(): cannot serialize ' . pyhp_type_name($v));
    }
    return $v;
}

function pyhp_call_builtin(string $name, array $args, array $kwargs = []) {
    if (count($kwargs) > 0) {
        if ($name !== 'sorted') {
            throw new PyHPTypeError("{$name}() does not accept keyword arguments");
        }
        if (count($args) !== 1) {
            throw new PyHPTypeError('sorted() takes exactly 1 positional argument (' . count($args) . ' given)');
        }
        $key = null; $reverse = false;
        foreach ($kwargs as $kw) {
            switch ($kw['name']) {
                case 'key':
                    if ($key !== null) throw new PyHPTypeError("sorted() got multiple values for argument 'key'");
                    $key = $kw['value'];
                    if ($key !== null && !is_callable($key)) {
                        throw new PyHPTypeError("sorted(): 'key' must be callable, got " . pyhp_type_name($key));
                    }
                    break;
                case 'reverse':
                    if ($reverse) throw new PyHPTypeError("sorted() got multiple values for argument 'reverse'");
                    $reverse = pyhp_truthy($kw['value']);
                    break;
                default:
                    throw new PyHPTypeError("sorted() got an unexpected keyword argument '{$kw['name']}'");
            }
        }
        return pyhp_sorted_impl($args[0], $key, $reverse);
    }
    $reg = pyhp_builtins();
    if (!isset($reg[$name])) throw new PyHPError("undefined function '{$name}'");
    [$fn, $min, $max] = $reg[$name];
    pyhp_reg_errcheck($args, $name, $min, $max);
    return $fn(...$args);
}

/** sorted() implementation shared by the plain and key=/reverse= forms. */
function pyhp_sorted_impl($iterable, $key, bool $reverse) {
    $vals = pyhp_iter_values($iterable);
    if ($key !== null) {
        // decorate-sort-undecorate (PHP 8 usort is stable, like Python's sort)
        $decorated = [];
        foreach ($vals as $v) { $decorated[] = [pyhp_callfunc($key, [$v], 'sorted(): key'), $v]; }
        usort($decorated, fn($x, $y) => $reverse ? pyhp_compare($y[0], $x[0]) : pyhp_compare($x[0], $y[0]));
        return new PyList(array_map(fn($d) => $d[1], $decorated));
    }
    usort($vals, fn($x, $y) => $reverse ? pyhp_compare($y, $x) : pyhp_compare($x, $y));
    return new PyList($vals);
}

/* ---------------- keyword-argument binding (shared by the transpiler's
   kwargs calls; mirrors the interpreter's bind order and error messages) ---- */

/** Merge positional + keyword args into a dense positional list. Slots the
 *  program skipped become PyHPMissing placeholders; the callee's prologue
 *  treats them as "not passed" (default or missing-argument error). */
function pyhp_kw_slots(array $paramNames, bool $hasVariadic, array $pos, array $kw, string $label): array {
    $n = count($paramNames);
    $slots = array_fill(0, $n, false);
    $values = array_fill(0, $n, null);
    $extra = [];
    $given = count($pos);
    for ($i = 0; $i < $given; $i++) {
        if ($i < $n) { $slots[$i] = true; $values[$i] = $pos[$i]; }
        elseif ($hasVariadic) { $extra[] = $pos[$i]; }
        else { throw new PyHPTypeError("{$label}() takes {$n} argument(s), {$given} given"); }
    }
    foreach ($kw as $entry) {
        $idx = array_search($entry['name'], $paramNames, true);
        if ($idx === false) {
            throw new PyHPTypeError("{$label}() got an unexpected keyword argument '{$entry['name']}'");
        }
        if ($slots[$idx]) {
            throw new PyHPTypeError("{$label}() got multiple values for argument '{$entry['name']}'");
        }
        $slots[$idx] = true;
        $values[$idx] = $entry['value'];
    }
    $last = $hasVariadic && $n > 0 ? $n - 1 : -1;
    for ($i = $n - 1; $i >= 0; $i--) { if ($slots[$i]) { $last = $i; break; } }
    $ordered = [];
    for ($i = 0; $i <= $last; $i++) { $ordered[] = $slots[$i] ? $values[$i] : PyHPMissing::get(); }
    foreach ($extra as $x) { $ordered[] = $x; }
    return $ordered;
}

function pyhp_call_kw($c, array $pos, array $kw, string $label) {
    if (!is_callable($c)) {
        throw new PyHPTypeError("'{$label}' is not callable (" . pyhp_type_name($c) . ")");
    }
    try { $ref = new ReflectionFunction($c); }
    catch (ReflectionException) { throw new PyHPTypeError("'{$label}' is not reflectable"); }
    $names = []; $variadic = false;
    foreach ($ref->getParameters() as $p) {
        if ($p->isVariadic()) { $variadic = true; break; }
        $names[] = $p->getName();
    }
    return $ref->invokeArgs(pyhp_kw_slots($names, $variadic, $pos, $kw, $label));
}

function pyhp_call_kw_method(object $obj, string $method, array $pos, array $kw, string $label) {
    try { $ref = new ReflectionMethod($obj, $method); }
    catch (ReflectionException) { throw new PyHPError("{$label}(): no method '{$method}'"); }
    $names = []; $variadic = false;
    foreach ($ref->getParameters() as $p) {
        if ($p->isVariadic()) { $variadic = true; break; }
        $names[] = $p->getName();
    }
    return $ref->invokeArgs($obj, pyhp_kw_slots($names, $variadic, $pos, $kw, $label));
}

function pyhp_call_kw_parent(object $obj, string $method, array $pos, array $kw, string $label) {
    $parent = get_parent_class($obj);
    if ($parent === false) throw new PyHPError("{$label}(): no parent class");
    try { $ref = new ReflectionMethod($parent, $method); }
    catch (ReflectionException) { throw new PyHPError("{$label}(): parent class has no method '{$method}'"); }
    $names = []; $variadic = false;
    foreach ($ref->getParameters() as $p) {
        if ($p->isVariadic()) { $variadic = true; break; }
        $names[] = $p->getName();
    }
    return $ref->invokeArgs($obj, pyhp_kw_slots($names, $variadic, $pos, $kw, $label));
}

function pyhp_new_kw(string $cls, array $pos, array $kw, string $label) {
    if (!class_exists($cls)) throw new PyHPError("unknown class '{$cls}'");
    $rc = new ReflectionClass($cls);
    $ctor = $rc->getConstructor();
    if ($ctor === null) {
        if (count($kw) > 0 || count($pos) > 0) throw new PyHPTypeError("{$label}() takes no arguments");
        return $rc->newInstance();
    }
    $names = []; $variadic = false;
    foreach ($ctor->getParameters() as $p) {
        if ($p->isVariadic()) { $variadic = true; break; }
        $names[] = $p->getName();
    }
    return $rc->newInstanceArgs(pyhp_kw_slots($names, $variadic, $pos, $kw, $label));
}

function pyhp_builtin_exists(string $name): bool {
    return isset(pyhp_builtins()[$name]);
}

/* internal helpers */
function pyhp_range_int($v, string $who): int {
    if (is_bool($v)) return (int)$v;
    if (is_int($v)) return $v;
    if ($v instanceof PyBigInt) {
        if (!pyhp_big_fits_int($v->dec)) {
            throw new PyHPOverflowError("{$who}(): Python int too large to convert to C int");
        }
        return (int)$v->dec;
    }
    if (is_float($v) && floor($v) === $v) return (int)$v;
    throw new PyHPTypeError("{$who}() expects an int, got " . pyhp_type_name($v));
}

/** range()/enumerate() bounds: like pyhp_range_int but big values stay big. */
function pyhp_range_bound($v, string $who) {
    if (is_bool($v)) return (int)$v;
    if (is_int($v)) return $v;
    if ($v instanceof PyBigInt) return $v;
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
    if ($v instanceof PyBigInt) return $v;   // int() on an int is exact, big included
    if (is_float($v)) {
        // truncate toward zero, like Python int(); out-of-range floats stay exact (big)
        $t = $v > 0 ? floor($v) : ceil($v);
        if ($t >= (float)PHP_INT_MAX || $t <= (float)PHP_INT_MIN) {
            return new PyBigInt(sprintf('%.0f', $t));
        }
        return (int)$t;
    }
    if (is_string($v)) {
        $t = trim($v);
        if (preg_match('/^[+-]?\d+$/', $t)) {
            if (pyhp_big_fits_int(pyhp_big_canon($t))) return (int)$t;
            return new PyBigInt($t);   // exact, like Python int("999...9")
        }
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
    // builtin type names (int covers big-ints, like Python's isinstance(x, int))
    $builtinType = match (true) {
        $name === 'int'    => is_int($v) || $v instanceof PyBigInt || is_bool($v),
        $name === 'float'  => is_float($v),
        $name === 'string' => is_string($v),
        $name === 'bool'   => is_bool($v),
        $name === 'list'   => $v instanceof PyList,
        $name === 'dict'   => $v instanceof PyDict,
        default            => null,
    };
    if ($builtinType !== null) return $builtinType;
    if (!is_object($v)) return false;
    $map = pyhp_error_class_map();
    if (isset($map[$name]) && $v instanceof $map[$name]) return true;
    // transpiled classes carry their PyHP-level name (and inherit parents
    // across module namespaces via the PHP class chain)
    for ($c = get_class($v); $c !== false; $c = get_parent_class($c)) {
        if ($c === $name || $c === 'PyHP_' . $name) return true;
        if (defined($c . '::PYHP_CLASS') && constant($c . '::PYHP_CLASS') === $name) return true;
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

/* =========================================================================
 * C++ native bridge — `cpp { ... }` blocks in .pyh files.
 *
 * A cpp block embeds C++ code. Exported functions are declared with a
 * `// pyhp-export:` directive giving the exact C signature:
 *
 *   cpp {
 *       // pyhp-export: double pyh_hypot(double a, double b);
 *       double pyh_hypot(double a, double b) { return sqrt(a*a + b*b); }
 *   }
 *
 * Signatures support int64_t, double, const char* and void — the same C ABI
 * PyHP uses for its C muscles everywhere.
 *
 * Two bridge modes, chosen automatically:
 *   ffi  — compile to a shared object, load with PHP ext/ffi (fast path)
 *   exec — compile to a standalone helper binary, call over a tiny
 *          line protocol (works on every PHP build, needs only g++)
 * ========================================================================= */

/** Parse `// pyhp-export: <decl>` lines out of a cpp block. Returns a list of
 *  ['name', 'ret', 'params', 'decl'] where decl is a complete C declaration. */
function pyhp_cpp_parse_exports(string $raw): array {
    $out = [];
    if (!preg_match_all('/pyhp-export:[ \t]*([^\r\n]+?)[ \t]*$/m', $raw, $m)) return $out;
    foreach ($m[1] as $declLine) {
        $decl = rtrim(trim($declLine), ';');
        if ($decl === '') continue;
        // <ret> <name> ( <params> )
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_ \t\*&]*?)\s*(\**)\s*([A-Za-z_][A-Za-z0-9_]*)\s*\((.*)\)$/', $decl, $d)) {
            throw new PyHPSyntaxError("pyhp-export: cannot parse declaration '{$decl}'");
        }
        $ret = trim($d[1] . $d[2]);
        $name = $d[3];
        $params = trim($d[4]);
        $out[] = ['name' => $name, 'ret' => $ret, 'params' => $params, 'decl' => $decl];
    }
    return $out;
}

/** Normalize one C parameter/return type to a bridge kind:
 *  i = int64_t, d = double, s = const char*, v = void. */
function pyhp_cpp_type_kind(string $t): string {
    $t = strtolower(trim($t));
    $t = preg_replace('/\s+/', ' ', $t);
    if ($t === 'void' || $t === '') return 'v';
    if (in_array($t, ['int64_t', 'long long', 'long', 'int', 'ssize_t', 'size_t', 'unsigned', 'int32_t', 'long long int'], true)) return 'i';
    if (in_array($t, ['double', 'float', 'long double'], true)) return 'd';
    if (preg_match('/^(const\s+)?(char|unsigned char|uint8_t|int8_t)\s*\**$/', $t) && str_contains($t, '*')) return 's';
    if ($t === 'pyhp_list' || $t === 'pyhp list') return 'l';
    if ($t === 'pyhp_dict' || $t === 'pyhp dict') return 'm';
    throw new PyHPSyntaxError("pyhp-export: unsupported type '{$t}' (supported: int64_t, double, const char*, pyhp_list, pyhp_dict, void)");
}

class PyHPCppNative
{
    /** @var array<string, string[]> module id -> raw block sources (in order) */
    private static array $blocks = [];
    /** @var array<string, array>|null export name -> ['decl','ret','params'] */
    private static ?array $exports = null;
    /** @var array<string, callable> compiled callables */
    private static array $callables = [];
    private static ?string $mode = null;      // 'ffi' | 'exec'
    private static ?string $bin = null;       // compiled helper path
    private static ?string $so = null;        // compiled shared object path
    private static $ffi = null;               // FFI instance
    private static ?string $builtHash = null; // fingerprint of the built code set

    public static function register(string $id, string $code): void {
        self::$blocks[$id][] = $code;
        self::$exports = null; // invalidate; rebuilt lazily
    }

    public static function has(string $name): bool {
        return isset(self::exportMap()[$name]);
    }

    /** @return array<string, array> */
    public static function exportMap(): array {
        if (self::$exports === null) {
            self::$exports = [];
            foreach (self::$blocks as $codes) {
                foreach ($codes as $raw) {
                    foreach (pyhp_cpp_parse_exports($raw) as $e) {
                        self::$exports[$e['name']] = $e;
                    }
                }
            }
        }
        return self::$exports;
    }

    public static function call(string $name, array $args, array $kwargs = []) {
        if (count($kwargs) > 0) {
            throw new PyHPTypeError("native cpp exports accept positional arguments only ('{$name}()')");
        }
        $f = self::fn($name);
        return $f(...$args);
    }

    public static function fn(string $name): callable {
        if (isset(self::$callables[$name])) return self::$callables[$name];
        $map = self::exportMap();
        if (!isset($map[$name])) {
            throw new PyHPError("native function '{$name}' is not exported by any cpp block");
        }
        self::ensureBuilt();
        if (self::$mode === 'ffi') {
            $cdef = self::$ffi;
            $sig = self::$exports[$name];
            $kinds = self::paramKinds($sig);
            if (in_array('l', $kinds, true) || in_array('m', $kinds, true)
                || pyhp_cpp_type_kind($sig['ret']) === 'l' || pyhp_cpp_type_kind($sig['ret']) === 'm') {
                throw new PyHPError("{$name}: pyhp_list/pyhp_dict across the bridge need the exec helper or the native backend — the FFI bridge cannot marshal structs");
            }
            self::$callables[$name] = function (...$args) use ($cdef, $name, $kinds) {
                if (count($args) !== count($kinds)) {
                    throw new PyHPTypeError("{$name}() takes " . count($kinds) . " argument(s), " . count($args) . " given");
                }
                $cargs = [];
                foreach ($args as $i => $v) { $cargs[] = self::toC($v, $kinds[$i], $name); }
                $r = $cdef->{$name}(...$cargs);
                if ($r instanceof \FFI\CData) return \FFI::string($r);
                if (is_bool($r)) return $r ? 1 : 0;
                return $r;
            };
        } else {
            $bin = self::$bin;
            $sig = self::$exports[$name];
            $kinds = self::paramKinds($sig);
            self::$callables[$name] = function (...$args) use ($bin, $name, $kinds) {
                if (count($args) !== count($kinds)) {
                    throw new PyHPTypeError("{$name}() takes " . count($kinds) . " argument(s), " . count($args) . " given");
                }
                return self::execCall($bin, $name, $args, $kinds);
            };
        }
        return self::$callables[$name];
    }

    /** @return string[] bridge kinds, one per parameter */
    private static function paramKinds(array $sig): array {
        $params = trim($sig['params']);
        if ($params === '' || strtolower($params) === 'void') return [];
        $kinds = [];
        foreach (explode(',', $params) as $p) {
            $t = trim($p);
            // drop the parameter name: "double x" -> "double", "const char* s" -> "const char*"
            $t = preg_replace('/\s+[A-Za-z_][A-Za-z0-9_]*$/', '', $t);
            $kinds[] = pyhp_cpp_type_kind($t);
        }
        return $kinds;
    }

    private static function toC($v, string $kind, string $fn) {
        switch ($kind) {
            case 'i':
                if (is_int($v)) return $v;
                if (is_float($v) && floor($v) === $v) return (int)$v;
                throw new PyHPTypeError("{$fn}() expects an int (int64_t) argument, got " . pyhp_type_name($v));
            case 'd':
                if (is_int($v) || is_float($v)) return (float)$v;
                throw new PyHPTypeError("{$fn}() expects a number (double) argument, got " . pyhp_type_name($v));
            case 's':
                if (is_string($v)) return $v;
                throw new PyHPTypeError("{$fn}() expects a string (const char*) argument, got " . pyhp_type_name($v));
            case 'l':
                throw new PyHPError("{$fn}(): pyhp_list arguments need the exec/native path — the FFI bridge cannot marshal structs");
            case 'm':
                throw new PyHPError("{$fn}(): pyhp_dict arguments need the exec/native path — the FFI bridge cannot marshal structs");
        }
        throw new PyHPTypeError("{$fn}(): unsupported bridge kind '{$kind}'");
    }

    /* ---------------- build & call ---------------- */

    private static function cacheDir(): string {
        $dir = getenv('PYHP_NATIVE_CACHE');
        if ($dir === false || $dir === '') $dir = (getcwd() ?: '.') . '/.pyhp_native';
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        return $dir;
    }

    private static function compiler(): string {
        $c = getenv('CXX');
        if ($c !== false && $c !== '') return $c;
        foreach (['g++', 'clang++', 'c++'] as $c) {
            $out = @shell_exec('command -v ' . escapeshellarg($c) . ' 2>/dev/null');
            if (is_string($out) && trim($out) !== '') return $c;
        }
        throw new PyHPRuntimeError('native cpp blocks need a C++ compiler (g++). Install g++ or set CXX.');
    }

    /** Compile all registered blocks once. Chooses ffi mode when ext/ffi is
     *  loaded and active, otherwise the portable exec mode. If the registered
     *  code set changes after a build (e.g. a second module registers more
     *  blocks), the build is redone for the new set. */
    private static function ensureBuilt(): void {
        $codes = [];
        foreach (self::$blocks as $list) { foreach ($list as $c) { $codes[] = $c; } }
        if (count($codes) === 0) throw new PyHPRuntimeError('no cpp blocks registered');
        // the fingerprint covers user code, the engine version and the generated
        // dispatcher, so protocol/runtime upgrades rebuild instead of reusing
        // a stale helper binary
        $dispatcher = self::genDispatcher(array_values(self::exportMap()));
        $hash = md5(implode("\n;;;\n", $codes) . '|' . PYHP_VERSION . '|' . md5($dispatcher));
        if (self::$mode !== null && self::$builtHash === $hash) return;
        if (self::$mode !== null) { // code set changed: rebuild from scratch
            self::$mode = null;
            self::$bin = null;
            self::$so = null;
            self::$ffi = null;
            self::$callables = [];
        }
        $dir = self::cacheDir();
        $cxx = self::compiler();

        // user code: hoist #include lines outside extern "C", wrap the rest
        $includes = [];
        $bodies = [];
        foreach ($codes as $code) {
            [$inc, $body] = self::splitIncludes($code);
            $includes[] = $inc;
            $bodies[] = $body;
        }

        $srcFile = $dir . '/pyhp_native_' . $hash . '.cpp';
        $src = "// C++ source generated by PyHP " . PYHP_VERSION . " — " . $hash . "\n"
             . "#include <cstdint>\n#include <cstdio>\n#include <cstring>\n#include <cstdlib>\n#include <cmath>\n#include <string>\n"
             . self::BRIDGE_TYPES . "\n"
             . implode("\n", array_filter($includes)) . "\n"
             . "#ifdef __cplusplus\nextern \"C\" {\n#endif\n"
             . implode("\n", $bodies) . "\n"
             . "#ifdef __cplusplus\n}\n#endif\n";
        file_put_contents($srcFile, $src);

        $useFfi = class_exists('\\FFI');
        if ($useFfi && function_exists('opcache_get_status')) {
            $st = @opcache_get_status();
            $useFfi = is_array($st); // ext/ffi requires opcache to be active
        }
        if ($useFfi) {
            $soFile = $dir . '/pyhp_native_' . $hash . '.so';
            $cmd = $cxx . ' -O2 -std=c++17 -shared -fPIC -o ' . escapeshellarg($soFile) . ' ' . escapeshellarg($srcFile) . ' 2>&1';
            exec($cmd, $outLines, $code);
            if ($code !== 0) {
                throw new PyHPRuntimeError("native build failed (g++):\n" . implode("\n", array_slice($outLines, 0, 30)));
            }
            $decls = implode("\n", array_map(fn($e) => $e['decl'] . ';', array_values(self::exportMap())));
            try {
                self::$ffi = \FFI::cdef($decls, $soFile);
            } catch (\Throwable $t) {
                throw new PyHPRuntimeError('native FFI load failed: ' . $t->getMessage());
            }
            self::$mode = 'ffi';
            self::$so = $soFile;
            self::$builtHash = $hash;
            return;
        }

        // exec mode: build a standalone helper with a generated dispatcher
        $mainFile = $dir . '/pyhp_native_' . $hash . '_main.cpp';
        $binFile = $dir . '/pyhp_native_' . $hash . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
        file_put_contents($mainFile, self::genDispatcher(array_values(self::exportMap())));
        $needBuild = !is_file($binFile) || filemtime($srcFile) > filemtime($binFile) || filemtime($mainFile) > filemtime($binFile);
        if ($needBuild) {
            $tmpBin = $binFile . '.tmp' . getmypid();
            $cmd = $cxx . ' -O2 -std=c++17 -o ' . escapeshellarg($tmpBin) . ' '
                 . escapeshellarg($srcFile) . ' ' . escapeshellarg($mainFile) . ' 2>&1';
            exec($cmd, $outLines, $code);
            if ($code !== 0) {
                @unlink($tmpBin);
                throw new PyHPRuntimeError("native build failed (g++):\n" . implode("\n", array_slice($outLines, 0, 30)));
            }
            rename($tmpBin, $binFile);
        }
        self::$mode = 'exec';
        self::$bin = $binFile;
        self::$builtHash = $hash;
    }

    /** Split #include lines (kept outside extern "C") from the body. */
    private static function splitIncludes(string $code): array {
        $inc = [];
        $body = [];
        foreach (preg_split('/\r?\n/', $code) as $line) {
            $t = ltrim($line);
            if (str_starts_with($t, '#include') || str_starts_with($t, '# include')) { $inc[] = $line; continue; }
            $body[] = $line;
        }
        return [implode("\n", $inc), implode("\n", $body)];
    }

    /** Bridge type declarations shared by the block source and the dispatcher. */
    private const BRIDGE_TYPES = "
enum PyHpVKind : unsigned char { PYHP_VN = 0, PYHP_VI = 1, PYHP_VF = 2, PYHP_VS = 3 };
struct pyhp_val { unsigned char kind; int64_t i; double f; const char* s; };
struct pyhp_list { int64_t len; pyhp_val* items; };
struct pyhp_dict { int64_t len; char** keys; pyhp_val* items; };
";

    /** Generated main() implementing the exec-mode line protocol. */
    private static function genDispatcher(array $exports): string {
        $protos = implode("\n", array_map(fn($e) => 'extern "C" ' . $e['decl'] . ';', $exports));
        $branches = '';
        foreach ($exports as $e) {
            $name = $e['name'];
            $kinds = self::paramKinds($e);
            $ret = pyhp_cpp_type_kind($e['ret']);
            $read = [];
            $call = [];
            for ($i = 0; $i < count($kinds); $i++) {
                $vn = 'a' . $i;
                switch ($kinds[$i]) {
                    case 'i': $read[] = "        int64_t {$vn} = pyhp_rt_read_i();"; break;
                    case 'd': $read[] = "        double {$vn} = pyhp_rt_read_d();"; break;
                    case 's': $read[] = "        std::string s{$i} = pyhp_rt_read_s();\n        const char* {$vn} = s{$i}.c_str();"; break;
                    case 'l': $read[] = "        pyhp_list a{$i} = pyhp_rt_read_list();"; break;
                    case 'm': $read[] = "        pyhp_dict a{$i} = pyhp_rt_read_dict();"; break;
                }
                $call[] = $vn;
            }
            $callStr = implode(', ', $call);
            $guard = count($kinds) > 0 ? "pyhp_rt_nargs == " . count($kinds) . " && " : '';
            switch ($ret) {
                case 'v': $body = "        {$name}({$callStr});\n        std::printf(\"ok v:\\n\");"; break;
                case 'i': $body = "        std::printf(\"ok i:%lld\\n\", (long long){$name}({$callStr}));"; break;
                case 'd': $body = "        std::printf(\"ok d:%s\\n\", pyhp_rt_fmt_d({$name}({$callStr})).c_str());"; break;
                case 's': $body = "        pyhp_rt_write_s({$name}({$callStr}));"; break;
                case 'l': $body = "        { pyhp_list r = {$name}({$callStr}); pyhp_rt_write_list(r); pyhp_list_free(&r); }"; break;
                case 'm': $body = "        { pyhp_dict r = {$name}({$callStr}); pyhp_rt_write_dict(r); pyhp_dict_free(&r); }"; break;
                default:  $body = "        std::printf(\"err unsupported return type\\n\");";
            }
            $branches .= "    if ({$guard}sym == \"{$name}\") {\n"
                . implode("\n", $read) . (count($read) > 0 ? "\n" : '')
                . $body . "\n"
                . "        return 0;\n    }\n";
        }
        return <<<CPP
// Generated by PyHP {PYHP_VERSION} — native dispatcher. Do not edit.
#include <cstdint>
#include <cstdio>
#include <cstring>
#include <cstdlib>
#include <string>

enum PyHpVKind : unsigned char { PYHP_VN = 0, PYHP_VI = 1, PYHP_VF = 2, PYHP_VS = 3 };
struct pyhp_val { unsigned char kind; int64_t i; double f; const char* s; };
struct pyhp_list { int64_t len; pyhp_val* items; };
struct pyhp_dict { int64_t len; char** keys; pyhp_val* items; };

{$protos}

static void pyhp_list_free(pyhp_list* L) {
    if (!L->items) return;
    for (int64_t i = 0; i < L->len; i++) {
        if (L->items[i].kind == PYHP_VS && L->items[i].s != nullptr) std::free((void*)L->items[i].s);
    }
    std::free(L->items);
    L->items = nullptr;
}

static pyhp_list pyhp_rt_read_list() {
    long count = 0;
    if (std::scanf(" l%ld:", &count) != 1) { std::printf("err bad list arg\\n"); std::exit(3); }
    pyhp_list L;
    L.len = (int64_t)count;
    L.items = (pyhp_val*)std::malloc(sizeof(pyhp_val) * (size_t)(count > 0 ? count : 1));
    for (long i = 0; i < count; i++) {
        char tag = 0;
        if (std::scanf(" %c", &tag) != 1) { std::printf("err bad list element\\n"); std::exit(3); }
        pyhp_val pv; pv.kind = PYHP_VN; pv.i = 0; pv.f = 0.0; pv.s = nullptr;
        if (tag == 'i') { long long v = 0; std::scanf(":%lld", &v); pv.kind = PYHP_VI; pv.i = (int64_t)v; }
        else if (tag == 'd') { char buf[64]; std::scanf(":%63s", buf); pv.kind = PYHP_VF; pv.f = std::strtod(buf, nullptr); }
        else if (tag == 's') {
            long len = 0;
            std::scanf(":%ld:", &len);
            std::string t((size_t)(len > 0 ? len : 0), '\\0');
            if (len > 0 && std::fread(&t[0], 1, (size_t)len, stdin) != (size_t)len) { std::printf("err truncated string arg\\n"); std::exit(3); }
            pv.kind = PYHP_VS; pv.s = strdup(t.c_str());
            std::getchar(); // newline after the bytes
        } else if (tag == 'n') { std::getchar(); }
        else { std::printf("err bad list element tag\\n"); std::exit(3); }
        L.items[i] = pv;
    }
    return L;
}

static pyhp_dict pyhp_rt_read_dict() {
    long count = 0;
    if (std::scanf(" d%ld:", &count) != 1) { std::printf("err bad dict arg\\n"); std::exit(3); }
    pyhp_dict D;
    D.len = (int64_t)count;
    D.keys = (char**)std::malloc(sizeof(char*) * (size_t)(count > 0 ? count : 1));
    D.items = (pyhp_val*)std::malloc(sizeof(pyhp_val) * (size_t)(count > 0 ? count : 1));
    for (long i = 0; i < count; i++) {
        long klen = 0;
        if (std::scanf(" k:%ld:", &klen) != 1) { std::printf("err bad dict key\\n"); std::exit(3); }
        std::string key((size_t)(klen > 0 ? klen : 0), '\\0');
        if (klen > 0 && std::fread(&key[0], 1, (size_t)klen, stdin) != (size_t)klen) { std::printf("err truncated dict key\\n"); std::exit(3); }
        std::getchar(); // newline after the key bytes
        char tag = 0;
        if (std::scanf(" %c", &tag) != 1) { std::printf("err bad dict element\\n"); std::exit(3); }
        pyhp_val pv; pv.kind = PYHP_VN; pv.i = 0; pv.f = 0.0; pv.s = nullptr;
        if (tag == 'i') { long long v = 0; std::scanf(":%lld", &v); pv.kind = PYHP_VI; pv.i = (int64_t)v; }
        else if (tag == 'd') { char buf[64]; std::scanf(":%63s", buf); pv.kind = PYHP_VF; pv.f = std::strtod(buf, nullptr); }
        else if (tag == 's') {
            long len = 0;
            std::scanf(":%ld:", &len);
            std::string t((size_t)(len > 0 ? len : 0), '\\0');
            if (len > 0 && std::fread(&t[0], 1, (size_t)len, stdin) != (size_t)len) { std::printf("err truncated string arg\\n"); std::exit(3); }
            pv.kind = PYHP_VS; pv.s = strdup(t.c_str());
            std::getchar();
        } else if (tag == 'n') { std::getchar(); }
        else { std::printf("err bad dict element tag\\n"); std::exit(3); }
        D.keys[i] = strdup(key.c_str());
        D.items[i] = pv;
    }
    return D;
}
static int64_t pyhp_rt_read_i() {
    long long v = 0;
    if (std::scanf("%lld", &v) != 1) { std::printf("err bad int arg\\n"); std::exit(3); }
    return (int64_t)v;
}
static double pyhp_rt_read_d() {
    char buf[64];
    if (std::scanf("%63s", buf) != 1) { std::printf("err bad double arg\\n"); std::exit(3); }
    return std::strtod(buf, nullptr);
}
static std::string pyhp_rt_read_s() {
    long len = 0;
    if (std::scanf(" s:%ld:", &len) != 1) { std::printf("err bad string arg\\n"); std::exit(3); }
    std::string out((size_t)len, '\\0');
    if (len > 0 && std::fread(&out[0], 1, (size_t)len, stdin) != (size_t)len) {
        std::printf("err truncated string arg\\n"); std::exit(3);
    }
    return out;
}
static void pyhp_rt_write_s(const char* s) {
    if (s == nullptr) { std::printf("ok s:0:\\n"); return; }
    std::printf("ok s:%lu:", (unsigned long)std::strlen(s));
    std::fwrite(s, 1, std::strlen(s), stdout);
    std::printf("\\n");
}
static std::string pyhp_rt_fmt_d(double d) {
    char buf[64];
    std::snprintf(buf, sizeof(buf), "%.17g", d);
    return std::string(buf);
}
static void pyhp_rt_write_list(const pyhp_list& L) {
    std::printf("ok l:%lld:", (long long)L.len);
    for (int64_t i = 0; i < L.len; i++) {
        const pyhp_val& p = L.items[i];
        if (p.kind == PYHP_VI) std::printf("i:%lld\\n", (long long)p.i);
        else if (p.kind == PYHP_VF) std::printf("d:%s\\n", pyhp_rt_fmt_d(p.f).c_str());
        else if (p.kind == PYHP_VS) {
            unsigned long sl = p.s ? std::strlen(p.s) : 0;
            std::printf("s:%lu:", sl);
            if (sl > 0) std::fwrite(p.s, 1, sl, stdout);
            std::printf("\\n");
        } else std::printf("n\\n");
    }
}
static void pyhp_rt_write_dict(const pyhp_dict& D) {
    std::printf("ok d:%lld:", (long long)D.len);
    for (int64_t i = 0; i < D.len; i++) {
        const char* k = D.keys[i] ? D.keys[i] : "";
        unsigned long kl = std::strlen(k);
        std::printf("k:%lu:", kl);
        if (kl > 0) std::fwrite(k, 1, kl, stdout);
        std::printf("\\n");
        const pyhp_val& p = D.items[i];
        if (p.kind == PYHP_VI) std::printf("i:%lld\\n", (long long)p.i);
        else if (p.kind == PYHP_VF) std::printf("d:%s\\n", pyhp_rt_fmt_d(p.f).c_str());
        else if (p.kind == PYHP_VS) {
            unsigned long sl = p.s ? std::strlen(p.s) : 0;
            std::printf("s:%lu:", sl);
            if (sl > 0) std::fwrite(p.s, 1, sl, stdout);
            std::printf("\\n");
        } else std::printf("n\\n");
    }
}

static void pyhp_dict_free(pyhp_dict* D) {
    if (!D->items) return;
    for (int64_t i = 0; i < D->len; i++) {
        if (D->keys[i]) std::free((void*)D->keys[i]);
        if (D->items[i].kind == PYHP_VS && D->items[i].s != nullptr) std::free((void*)D->items[i].s);
    }
    std::free(D->keys);
    std::free(D->items);
    D->keys = nullptr;
    D->items = nullptr;
}
int main(int argc, char** argv) {
    if (argc < 2) { std::printf("err missing symbol\\n"); return 2; }
    std::string sym = argv[1];
    int pyhp_rt_nargs = -1;
    if (std::scanf("%d", &pyhp_rt_nargs) != 1) { std::printf("err bad request\\n"); return 3; }
{$branches}    std::printf("err unknown symbol\\n");
    return 1;
}
CPP;
    }

    /** One call over the exec-mode protocol. */
    /** Encode a PyList argument in the exec-mode element protocol:
     *  "l<count>\n" then one line per element (i:..\n d:..\n s:len:bytes\n n\n). */
    private static function encodeListArg($v, string $fn): string {
        if (!($v instanceof PyList)) {
            throw new PyHPTypeError("{$fn}() expects a list (pyhp_list) argument, got " . pyhp_type_name($v));
        }
        $req = 'l' . count($v->a) . "\n";
        foreach ($v->a as $el) {
            if ($el === null) { $req .= "n\n"; }
            elseif (is_int($el)) { $req .= "i:{$el}\n"; }
            elseif (is_float($el)) { $req .= 'd:' . json_encode($el) . "\n"; }
            elseif (is_string($el)) { $req .= 's:' . strlen($el) . ':' . $el . "\n"; }
            else {
                throw new PyHPTypeError("{$fn}(): pyhp_list elements must be int/float/string/null, got " . pyhp_type_name($el));
            }
        }
        return $req;
    }

    /** Parse "ok l:<count>:" + element lines out of the response stream. */
    private static function parseListPayload(string $out, int $p, int $count, string $name) {
        $items = [];
        for ($k = 0; $k < $count; $k++) {
            if ($p >= strlen($out)) throw new PyHPRuntimeError("native call '{$name}': truncated list response");
            $tag = $out[$p];
            if ($tag === 'n') { $items[] = null; $p += 2; continue; }
            if ($tag === 'i' || $tag === 'd') {
                $nl = strpos($out, "\n", $p);
                if ($nl === false) throw new PyHPRuntimeError("native call '{$name}': truncated list response");
                $raw = substr($out, $p + 2, $nl - $p - 2);
                $items[] = $tag === 'i' ? (int)$raw : (float)$raw;
                $p = $nl + 1;
                continue;
            }
            if ($tag === 's') {
                $colon = strpos($out, ':', $p + 2);
                $len = (int)substr($out, $p + 2, $colon - $p - 2);
                $start = $colon + 1;
                $items[] = substr($out, $start, $len);
                $p = $start + $len + 1;
                continue;
            }
            throw new PyHPRuntimeError("native call '{$name}': malformed list response");
        }
        return new PyList($items);
    }

    /** Encode a PyDict argument in the exec-mode element protocol:
     *  "d<count>\n" then per entry a key line (k:len:bytes\n) and a value
     *  line (i:..\n d:..\n s:len:bytes\n n\n). */
    private static function encodeDictArg($v, string $fn): string {
        if (!($v instanceof PyDict)) {
            throw new PyHPTypeError("{$fn}() expects a dict (pyhp_dict) argument, got " . pyhp_type_name($v));
        }
        $req = 'd' . count($v->d) . "\n";
        foreach ($v->d as $k => $el) {
            $ks = (string)$k;   // int keys ride as decimal strings across the bridge
            $req .= 'k:' . strlen($ks) . ':' . $ks . "\n";
            if ($el === null) { $req .= "n\n"; }
            elseif (is_int($el)) { $req .= "i:{$el}\n"; }
            elseif (is_float($el)) { $req .= 'd:' . json_encode($el) . "\n"; }
            elseif (is_string($el)) { $req .= 's:' . strlen($el) . ':' . $el . "\n"; }
            else {
                throw new PyHPTypeError("{$fn}(): pyhp_dict values must be int/float/string/null, got " . pyhp_type_name($el));
            }
        }
        return $req;
    }

    /** Bridge dict keys that look like non-negative integers become int keys
     *  on the PyHP side (same round-trip rule as json_parse). */
    private static function bridgeKey(string $k) {
        if (preg_match('/^[0-9]+$/', $k) === 1) {
            return pyhp_int_conv($k);
        }
        return $k;
    }

    /** Parse "ok d:<count>:" + key/value line pairs out of the response stream. */
    private static function parseDictPayload(string $out, int $p, int $count, string $name) {
        $d = [];
        for ($k = 0; $k < $count; $k++) {
            if ($p >= strlen($out) || $out[$p] !== 'k') {
                throw new PyHPRuntimeError("native call '{$name}': truncated dict response");
            }
            $colon = strpos($out, ':', $p + 2);
            $len = (int)substr($out, $p + 2, $colon - $p - 2);
            $start = $colon + 1;
            $key = substr($out, $start, $len);
            $p = $start + $len + 1;   // past the trailing \n
            if ($p >= strlen($out)) throw new PyHPRuntimeError("native call '{$name}': truncated dict response");
            $tag = $out[$p];
            if ($tag === 'n') { $d[self::bridgeKey($key)] = null; $p += 2; continue; }
            if ($tag === 'i' || $tag === 'd') {
                $nl = strpos($out, "\n", $p);
                if ($nl === false) throw new PyHPRuntimeError("native call '{$name}': truncated dict response");
                $raw = substr($out, $p + 2, $nl - $p - 2);
                $d[self::bridgeKey($key)] = $tag === 'i' ? (int)$raw : (float)$raw;
                $p = $nl + 1;
                continue;
            }
            if ($tag === 's') {
                $colon2 = strpos($out, ':', $p + 2);
                $vlen = (int)substr($out, $p + 2, $colon2 - $p - 2);
                $vstart = $colon2 + 1;
                $d[self::bridgeKey($key)] = substr($out, $vstart, $vlen);
                $p = $vstart + $vlen + 1;
                continue;
            }
            throw new PyHPRuntimeError("native call '{$name}': malformed dict response");
        }
        return new PyDict($d);
    }

    private static function execCall(string $bin, string $name, array $args, array $kinds) {
        if (!is_file($bin)) throw new PyHPRuntimeError('native helper binary is missing: ' . $bin);
        $req = count($kinds) . "\n";
        foreach ($args as $i => $v) {
            switch ($kinds[$i]) {
                case 'i': $req .= ((string)(int)self::toC($v, 'i', $name)) . "\n"; break;
                case 'd': $req .= json_encode((float)self::toC($v, 'd', $name)) . "\n"; break;
                case 's': $s = (string)self::toC($v, 's', $name); $req .= 's:' . strlen($s) . ':' . $s; break;
                case 'l': $req .= self::encodeListArg($v, $name); break;
                case 'm': $req .= self::encodeDictArg($v, $name); break;
            }
        }
        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open(escapeshellarg($bin) . ' ' . escapeshellarg($name), $desc, $pipes);
        if (!is_resource($proc)) throw new PyHPRuntimeError("cannot run native helper for '{$name}'");
        fwrite($pipes[0], $req);
        fclose($pipes[0]);
        $out = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($proc);
        $nl = strpos($out, "\n");
        $line = $nl === false ? $out : substr($out, 0, $nl);
        if ($code !== 0 || !str_starts_with($line, 'ok ')) {
            throw new PyHPRuntimeError("native call '{$name}' failed: " . ($line !== '' ? trim($line) : trim($err)));
        }
        $payload = substr($line, 3); // after "ok " — e.g. "i:42", "s:12:PyHP native!"
        if ($payload === 'v:') return null;
        if (str_starts_with($payload, 'i:')) return (int)substr($payload, 2);
        if (str_starts_with($payload, 's:')) {
            $colon = strpos($payload, ':', 2);
            $len = (int)substr($payload, 2, $colon - 2);
            $start = 3 + $colon + 1; // absolute offset of the string bytes in $out
            return substr($out, $start, $len);
        }
        if (str_starts_with($payload, 'l:')) {
            $colon = strpos($out, ':', 5);
            $count = (int)substr($out, 5, $colon - 5);
            return self::parseListPayload($out, $colon + 1, $count, $name);
        }
        // "d:<count>:" is a dict response; "d:<double>" (no second colon) is a double
        if (str_starts_with($payload, 'd:') && preg_match('/^d:\d+:/', $payload) === 1) {
            $colon = strpos($out, ':', 5);
            $count = (int)substr($out, 5, $colon - 5);
            return self::parseDictPayload($out, $colon + 1, $count, $name);
        }
        if (str_starts_with($payload, 'd:')) {
            return (float)substr($payload, 2);
        }
        throw new PyHPRuntimeError("native call '{$name}': malformed response");
    }
}

/* Public entry points used by the interpreter and transpiled code. */
function pyhp_cpp_register(string $id, string $code): void { PyHPCppNative::register($id, $code); }
function pyhp_cpp_has(string $name): bool { return PyHPCppNative::has($name); }
function pyhp_cpp_call(string $name, array $args) { return PyHPCppNative::call($name, $args); }
function pyhp_cpp_fn(string $name): callable { return PyHPCppNative::fn($name); }
