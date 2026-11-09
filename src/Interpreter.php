<?php
/**
 * PyHP tree-walking interpreter — "our own interpreter".
 *
 * Executes the AST directly (used by `pyhp run` and the REPL).
 * The transpiler (`pyhp build`) shares the value model from runtime.php but
 * generates PHP code instead of walking the tree.
 */

require_once __DIR__ . '/Parser.php';

class PyHPInterpreter
{
    public PyHPEnv $global;
    private array $classes = [];
    private int $depth = 0;
    private const MAX_DEPTH = 300;
    private string $classNs = '';       // non-empty while loading a module ('m0\')
    private array $modules = [];        // realpath => export PyDict (null while loading)
    private array $fileDirs = [];       // stack of dirs of the file(s) being executed

    public function __construct() {
        require_once __DIR__ . '/runtime.php';
        $this->global = $this->freshRootEnv();
        foreach (pyhp_error_class_map() as $errName => $phpClass) {
            $this->classes[$errName] = $this->makeErrorClass($errName);
        }
        $GLOBALS['__pyhp_str_hook'] = function (InterpInstance $inst): ?string {
            $f = $this->findMethod($inst->cls, '__str__');
            if ($f === null) $f = $this->findMethod($inst->cls, '__toString');
            if ($f === null) return null;
            $r = $this->invokeMethodWithSuper($inst, $f, []);
            return is_string($r) ? $r : pyhp_tostr($r);
        };
        $GLOBALS['__pyhp_iter_hook'] = function ($v): ?array {
            if (!($v instanceof InterpInstance)) return null;
            try {
                return $this->interpIterate($v);
            } catch (PyHPTypeError $e) {
                return null;   // not iterable — let the caller report it
            }
        };
    }

    /* ================================================================ */
    /* program execution                                                */
    /* ================================================================ */

    /** Track the file being executed so require("...") resolves relative to it. */
    public function setMainFile(string $path): void {
        $rp = realpath($path);
        $this->fileDirs[] = ($rp !== false ? dirname($rp) : dirname($path));
    }

    private function currentDir(): string {
        if (count($this->fileDirs) > 0) return $this->fileDirs[count($this->fileDirs) - 1];
        $cwd = getcwd();
        return $cwd === false ? '.' : $cwd;
    }

    /** Class registry key, namespaced while a module is loading. */
    private function clsKey(string $n): string { return $this->classNs . $n; }

    /** Find a class: module namespace first, then global (error classes). */
    private function clsFind(string $n): ?ClassDef {
        if ($this->classNs !== '' && isset($this->classes[$this->classNs . $n])) {
            return $this->classes[$this->classNs . $n];
        }
        return $this->classes[$n] ?? null;
    }

    private function freshRootEnv(): PyHPEnv {
        $env = new PyHPEnv(null);
        foreach (array_keys(pyhp_builtins()) as $name) {
            $env->declare($name, new PyHPBuiltin($name));
        }
        return $env;
    }

    /** Static scan of a module AST: which top-level names are exported?
     *  Named function defs, top-level lambda assignments, and classes. */
    private function moduleExportNames(array $stmts): array {
        $funcs = [];
        $classes = [];
        foreach ($stmts as $s) {
            if ($s['k'] === 'Func' && $s['name'] !== null) {
                $funcs[] = $s['name'];
            } elseif ($s['k'] === 'Expr' && $s['e']['k'] === 'Assign'
                && $s['e']['target']['k'] === 'Var' && $s['e']['value']['k'] === 'Func') {
                $funcs[] = $s['e']['target']['name'];
            } elseif ($s['k'] === 'VarDecl' && $s['init'] !== null && $s['init']['k'] === 'Func') {
                $funcs[] = $s['name'];
            } elseif ($s['k'] === 'Class') {
                $classes[] = $s['name'];
            }
        }
        return [$funcs, $classes];
    }

    /** require("name" | "./path.pyh") — loads a module in an isolated scope
     *  and returns its export table (a dict of functions and class factories). */
    private function loadModule(array $args, int $line) {
        if (count($args) !== 1) {
            throw new PyHPTypeError("require() takes exactly 1 argument(s), got " . count($args));
        }
        $spec = $args[0];
        if (!is_string($spec)) {
            throw new PyHPTypeError("require() expects a module name or path string, got " . pyhp_type_name($spec));
        }
        $path = pyhp_resolve_module($spec, $this->currentDir());
        if ($path === null) {
            throw new PyHPError("line {$line}: ModuleNotFoundError: cannot find module '{$spec}' (looked in ./vendor and the registry)");
        }
        if (array_key_exists($path, $this->modules)) {
            if ($this->modules[$path] === null) {
                throw new PyHPSyntaxError("circular module dependency: '{$spec}'");
            }
            return $this->modules[$path];
        }
        $src = file_get_contents($path);
        $stmts = PyHPParser::parseSource($src);
        $id = 'm' . count($this->modules);
        $this->modules[$path] = null; // reserve as cycle guard
        [$funcNames, $classNames] = $this->moduleExportNames($stmts);

        $savedNs = $this->classNs;
        $this->classNs = $id . '\\';
        $this->fileDirs[] = dirname($path);
        $env = $this->freshRootEnv();
        try {
            $this->execStmts($stmts, $env);
        } finally {
            array_pop($this->fileDirs);
            $this->classNs = $savedNs;
        }

        $exports = [];
        foreach ($funcNames as $n) {
            if (array_key_exists($n, $env->vars) && $env->vars[$n] instanceof InterpFunc
                && $env->vars[$n]->cls === null) {
                $exports[$n] = $env->vars[$n];
            }
        }
        foreach ($classNames as $n) {
            $cls = $this->classes[$id . '\\' . $n] ?? null;
            if ($cls !== null) {
                $exports[$n] = new InterpClassRef($cls, $this);
            }
        }
        $table = new PyDict($exports);
        $this->modules[$path] = $table;
        return $table;
    }

    /** Execute statements; if the program is a single bare expression,
     *  return its value (used by the REPL). */
    public function runProgram(array $stmts) {
        $value = null;
        $isExpr = count($stmts) === 1 && $stmts[0]['k'] === 'Expr'
            && in_array($stmts[0]['e']['k'], ['Assign', 'Post'], true) === false;
        foreach ($stmts as $s) {
            if ($isExpr && $s === $stmts[0]) {
                $value = $this->evalExpr($s['e'], $this->global);
            } else {
                $this->execStmt($s, $this->global);
            }
        }
        return $isExpr ? $value : null;
    }

    /* ================================================================ */
    /* statements                                                       */
    /* ================================================================ */

    public function execStmts(array $stmts, PyHPEnv $env): void {
        foreach ($stmts as $s) { $this->execStmt($s, $env); }
    }

    public function execStmt(array $s, PyHPEnv $env): void {
        switch ($s['k']) {
            case 'Expr':
                $this->evalExpr($s['e'], $env);
                return;
            case 'VarDecl':
                if ($s['init'] !== null) {
                    $v = $this->evalExpr($s['init'], $env);
                    $env->declare($s['name'], $v, $s['type']);
                } else {
                    $env->declare($s['name'], null, $s['type'], true);
                }
                return;
            case 'Func':
                $f = new InterpFunc();
                $f->name = $s['name'] ?? '<lambda>';
                $f->params = $s['params'];
                $f->ret = $s['ret'] ?? null;
                $f->body = $s['body']['stmts'];
                $f->env = $env;
                $f->interp = $this;
                $f->cls = null;
                $f->fileDir = $this->currentDir();
                if ($s['name'] !== null) {
                    $env->assign($s['name'], $f);
                }
                return;
            case 'Class':
                $this->declareClass($s, $env);
                return;
            case 'If': {
                foreach ($s['branches'] as [$cond, $body]) {
                    if (pyhp_truthy($this->evalExpr($cond, $env))) {
                        $this->execStmt($body, $env);
                        return;
                    }
                }
                if ($s['else'] !== null) $this->execStmt($s['else'], $env);
                return;
            }
            case 'While': {
                while (pyhp_truthy($this->evalExpr($s['cond'], $env))) {
                    try {
                        $this->execStmt($s['body'], $env);
                    } catch (PyHPBreak $b) { break; }
                      catch (PyHPContinue $c) { /* next turn */ }
                }
                return;
            }
            case 'DoWhile': {
                do {
                    try {
                        $this->execStmt($s['body'], $env);
                    } catch (PyHPBreak $b) { break; }
                      catch (PyHPContinue $c) { /* next turn */ }
                } while (pyhp_truthy($this->evalExpr($s['cond'], $env)));
                return;
            }
            case 'For': {
                if ($s['init'] !== null) $this->execStmt($s['init'], $env);
                while ($s['cond'] === null || pyhp_truthy($this->evalExpr($s['cond'], $env))) {
                    try {
                        $this->execStmt($s['body'], $env);
                    } catch (PyHPBreak $b) { break; }
                      catch (PyHPContinue $c) { /* fall through to update */ }
                    if ($s['update'] !== null) $this->evalExpr($s['update'], $env);
                }
                return;
            }
            case 'Foreach': {
                $itv = $this->evalExpr($s['iter'], $env);
                $items = $itv instanceof InterpInstance ? $this->interpIterate($itv) : pyhp_foreach($itv);
                foreach ($items as $k => $v) {
                    try {
                        if ($s['var2'] !== null) {
                            $env->declare($s['var1'], $k);
                            $env->declare($s['var2'], $v);
                        } else {
                            $env->declare($s['var1'], $v);
                        }
                        $this->execStmt($s['body'], $env);
                    } catch (PyHPBreak $b) { break; }
                      catch (PyHPContinue $c) { continue; }
                }
                return;
            }
            case 'Try':
                $this->execTry($s, $env);
                return;
            case 'Throw': {
                $v = $this->evalExpr($s['e'], $env);
                throw new PyHPThrow($this->asThrowableInstance($v));
            }
            case 'Return':
                throw new PyHPReturn($s['e'] === null ? null : $this->evalExpr($s['e'], $env));
            case 'Break':
                throw new PyHPBreak();
            case 'Continue':
                throw new PyHPContinue();
            case 'Echo':
                foreach ($s['args'] as $a) { echo pyhp_tostr($this->evalExpr($a, $env)); }
                return;
            case 'Cpp':
                PyHPCppNative::register($this->classNs !== '' ? $this->classNs : 'main', $s['code']);
                return;
            case 'Block':
                $this->execStmts($s['stmts'], $env);
                return;
        }
        throw new PyHPRuntimeError("line {$s['line']}: unsupported statement kind '{$s['k']}'");
    }

    private function execTry(array $s, PyHPEnv $env): void {
        try {
            $this->execStmt($s['body'], $env);
            $this->runFinally($s, $env); // normal completion
        } catch (PyHPThrow $t) {
            $this->matchCatch($s, $t->value, $env); // matchCatch runs finally itself
        } catch (PyHPError $e) {
            $this->matchCatch($s, $this->errorToInstance($e), $env);
        } catch (PyHPReturn $r) {
            $this->runFinally($s, $env);
            throw $r;
        } catch (PyHPBreak $b) {
            $this->runFinally($s, $env);
            throw $b;
        } catch (PyHPContinue $c) {
            $this->runFinally($s, $env);
            throw $c;
        }
    }

    private function matchCatch(array $s, InterpInstance $inst, PyHPEnv $env): void {
        foreach ($s['catches'] as $c) {
            if ($c['type'] === null || $this->instanceMatches($inst, $c['type'])) {
                $cenv = new PyHPEnv($env);
                $cenv->declare($c['var'], $inst);
                $this->execStmt($c['body'], $cenv);
                $this->runFinally($s, $env);
                return;
            }
        }
        // no catch matched: run finally, then rethrow
        $this->runFinally($s, $env);
        throw new PyHPThrow($inst);
    }

    private function runFinally(array $s, PyHPEnv $env): void {
        if ($s['finally'] !== null) $this->execStmt($s['finally'], $env);
    }

    private function instanceMatches(InterpInstance $inst, string $name): bool {
        for ($c = $inst->cls; $c !== null; $c = $c->parent) {
            if ($c->name === $name) return true;
        }
        return false;
    }

    /** Wrap any thrown PyHP value into an exception instance. */
    private function asThrowableInstance($v): InterpInstance {
        if ($v instanceof InterpInstance && $this->instanceMatches($v, 'Exception')) return $v;
        if ($v instanceof InterpInstance) {
            // a non-Exception object: wrap message
            $msg = pyhp_tostr($v);
            return $this->errorToInstance(new PyHPRuntimeError($msg));
        }
        $msg = is_string($v) ? $v : pyhp_tostr($v);
        return $this->errorToInstance(new PyHPRuntimeError($msg));
    }

    private function errorToInstance(PyHPError $e): InterpInstance {
        return $this->makeErrorInstance($e->pyhpType, $e->message);
    }

    private function makeErrorInstance(string $type, string $msg): InterpInstance {
        $cls = $this->classes[$type] ?? $this->classes['RuntimeError'];
        $inst = new InterpInstance($cls);
        $inst->props['message'] = $msg;
        return $inst;
    }

    private function makeErrorClass(string $name): ClassDef {
        $cls = new ClassDef();
        $cls->name = $name;
        $cls->isBuiltinError = true;
        if ($name !== 'Exception') {
            $cls->parent = $this->classes['Exception'] ?? null;
        }
        $ctor = new InterpFunc();
        $ctor->name = '__construct';
        $ctor->params = [['name' => 'message', 'type' => null, 'def' => null, 'variadic' => false]];
        $ctor->body = [];
        $ctor->env = $this->global;
        $ctor->interp = $this;
        $ctor->cls = $cls;
        $ctor->native = function ($env, $args) {
            $env->get('this')->props['message'] = count($args) > 0 ? pyhp_tostr($args[0]) : '';
            return null;
        };
        $cls->methods['__construct'] = $ctor;

        $str = new InterpFunc();
        $str->name = '__str__';
        $str->params = [];
        $str->body = [];
        $str->env = $this->global;
        $str->interp = $this;
        $str->cls = $cls;
        $str->native = function ($env, $args) {
            return pyhp_tostr($env->get('this')->props['message'] ?? '');
        };
        $cls->methods['__str__'] = $str;
        return $cls;
    }

    /* ================================================================ */
    /* classes                                                          */
    /* ================================================================ */

    private function declareClass(array $s, ?PyHPEnv $env = null): void {
        $name = $s['name'];
        $key = $this->clsKey($name);
        if (isset($this->classes[$key])) {
            throw new PyHPSyntaxError("line {$s['line']}: cannot redefine class '{$name}'");
        }
        $cls = new ClassDef();
        $cls->name = $name;
        if ($s['ext'] !== null) {
            $parent = $this->clsFind($s['ext']);
            if ($parent !== null) {
                // extending by class name is only allowed within the file's
                // own namespace (or an error class)
                if ($this->classNs !== '' && !isset($this->classes[$this->classNs . $s['ext']])
                    && !array_key_exists($s['ext'], pyhp_error_class_map())) {
                    throw new PyHPSyntaxError("line {$s['line']}: module classes cannot extend classes from other files");
                }
            } elseif ($env !== null && $env->has($s['ext']) && $env->get($s['ext']) instanceof InterpClassRef) {
                // `extends SomeVar` — a class object held in a variable, e.g.
                // S = require("./base.pyh").Shape; class Circle extends S
                $parent = $env->get($s['ext'])->def;
            } else {
                throw new PyHPSyntaxError("line {$s['line']}: unknown parent class '{$s['ext']}'");
            }
            $cls->parent = $parent;
        }
        foreach ($s['members'] as $m) {
            if ($m['k'] === 'Func') {
                $f = new InterpFunc();
                $f->name = $m['name'];
                $f->params = $m['params'];
                $f->ret = $m['ret'] ?? null;
                $f->body = $m['body']['stmts'];
                $f->env = $this->global;
                $f->interp = $this;
                $f->cls = $cls;
                $f->fileDir = $this->currentDir();
                $cls->methods[$m['name']] = $f;
            } elseif ($m['k'] === 'VarDecl') {
                $cls->propStmts[] = $m;
            }
        }
        $this->classes[$key] = $cls;
    }

    private function findMethod(ClassDef $cls, string $name): ?InterpFunc {
        for ($c = $cls; $c !== null; $c = $c->parent) {
            if (isset($c->methods[$name])) return $c->methods[$name];
        }
        return null;
    }

    private function chainClasses(ClassDef $leaf): array {
        $chain = [];
        for ($c = $leaf; $c !== null; $c = $c->parent) { array_unshift($chain, $c); }
        return $chain;
    }

    public function instantiate(ClassDef $cls, array $args, array $kwargs = []): InterpInstance {
        $inst = new InterpInstance($cls);
        // property defaults: parent first, then child (in declaration order)
        foreach ($this->chainClasses($cls) as $c) {
            foreach ($c->propStmts as $p) {
                if ($p['type'] !== null && $p['type'] !== 'var') $inst->propTypes[$p['name']] = $p['type'];
            }
        }
        $env = new PyHPEnv($this->global);
        $env->declare('this', $inst);
        foreach ($this->chainClasses($cls) as $c) {
            foreach ($c->propStmts as $p) {
                if ($p['init'] !== null) {
                    $v = $this->evalExpr($p['init'], $env);
                    $t = $inst->propTypes[$p['name']] ?? null;
                    if ($t !== null) $v = pyhp_check_type($v, $t, $p['name']);
                    $inst->props[$p['name']] = $v;
                } else {
                    $inst->props[$p['name']] = null;
                }
            }
        }
        $ctor = $this->findMethod($cls, '__construct') ?? $this->findMethod($cls, '__init');
        if ($ctor !== null) $this->invokeMethodWithSuper($inst, $ctor, $args, $kwargs);
        return $inst;
    }

    /** Iterator protocol for interpreter instances: __iter__() then
     *  __next__() until a StopIteration raise ends the stream. */
    private function interpIterate(InterpInstance $v): array {
        $hasMethod = function (InterpInstance $inst, string $n): bool {
            for ($c = $inst->cls; $c !== null; $c = $c->parent) {
                if (isset($c->methods[$n])) return true;
            }
            return false;
        };
        $obj = $v;
        if ($hasMethod($v, '__iter__')) {
            $f = $this->findMethod($v->cls, '__iter__');
            $obj = $this->invokeMethodWithSuper($v, $f, []);
            if ($obj instanceof PyList) return $obj->a;
            if (!($obj instanceof InterpInstance)) {
                throw new PyHPTypeError('__iter__ must return an object or list');
            }
        }
        if (!$hasMethod($obj, '__next__')) {
            throw new PyHPTypeError("'" . pyhp_type_name($v) . "' object is not iterable");
        }
        $out = [];
        try {
            while (true) {
                $f = $this->findMethod($obj->cls, '__next__');
                $out[] = $this->invokeMethodWithSuper($obj, $f, []);
            }
        } catch (PyHPThrow $t) {
            if (!pyhp_throw_is_stop($t)) throw $t;
        }
        return $out;
    }

    private function invokeMethodWithSuper(InterpInstance $inst, InterpFunc $f, array $args, array $kwargs = []) {
        return $this->callFunction($f, $args, $inst, $f->cls, $kwargs);
    }

    public function invokeFunc(InterpFunc $f, array $args, array $kwargs = []) {
        return $this->callFunction($f, $args, null, null, $kwargs);
    }

    private function callFunction(InterpFunc $f, array $args, ?InterpInstance $thisInst, ?ClassDef $superCls, array $kwargs = []) {
        if (++$this->depth > self::MAX_DEPTH) {
            $this->depth--;
            throw new PyHPRuntimeError("stack overflow: recursion deeper than " . self::MAX_DEPTH . " calls");
        }
        $pushedDir = false;
        if ($f->fileDir !== null) { $this->fileDirs[] = $f->fileDir; $pushedDir = true; }
        try {
            $env = new PyHPEnv($f->env);
            if ($thisInst !== null) $env->declare('this', $thisInst);
            if ($superCls !== null) $env->declare('__supercls', $superCls);

            // bind parameters: positional first (in order), then keyword arguments
            $nNamed = 0;
            foreach ($f->params as $p) { if (!$p['variadic']) $nNamed++; }
            $given = count($args);
            $hasVar = $this->hasVariadic($f->params);
            if (!$hasVar && $given > $nNamed) {
                throw new PyHPTypeError("{$f->name}() takes {$nNamed} argument(s), {$given} given");
            }
            if ($f->native !== null) {
                if (count($kwargs) > 0) {
                    throw new PyHPTypeError("{$f->name}() does not accept keyword arguments");
                }
                return ($f->native)($env, $args);
            }
            $namedNames = [];
            foreach ($f->params as $p) { if (!$p['variadic']) $namedNames[] = $p['name']; }
            $values = array_slice($args, 0, $nNamed);
            while (count($values) < $nNamed) { $values[] = null; }
            $filled = [];
            for ($i = 0; $i < $nNamed; $i++) { $filled[$i] = $i < $given; }
            foreach ($kwargs as $kw) {
                $idx = array_search($kw['name'], $namedNames, true);
                if ($idx === false) {
                    throw new PyHPTypeError("{$f->name}() got an unexpected keyword argument '{$kw['name']}'");
                }
                if ($filled[$idx]) {
                    throw new PyHPTypeError("{$f->name}() got multiple values for argument '{$kw['name']}'");
                }
                $values[$idx] = $kw['value'];
                $filled[$idx] = true;
            }
            $i = 0;
            foreach ($f->params as $p) {
                if ($p['variadic']) {
                    $rest = array_slice($args, $nNamed);
                    $env->declare($p['name'], new PyList($rest));
                    break;
                }
                if ($filled[$i]) {
                    $v = $values[$i];
                } elseif ($p['def'] !== null) {
                    $v = $this->evalExpr($p['def'], $env);
                } else {
                    throw new PyHPTypeError("{$f->name}() missing required argument '{$p['name']}'");
                }
                if ($p['type'] !== null) $v = pyhp_check_type($v, $p['type'], $p['name']);
                $env->declare($p['name'], $v);
                $i++;
            }
            try {
                $this->execStmts($f->body, $env);
                $ret = null;
            } catch (PyHPReturn $r) {
                $ret = $r->value;
            }
            if ($f->ret !== null) {
                $ret = pyhp_check_type($ret, $f->ret, $f->name . '() return');
            }
            return $ret;
        } finally {
            $this->depth--;
            if ($pushedDir) { array_pop($this->fileDirs); }
        }
    }

    private function hasVariadic(array $params): bool {
        foreach ($params as $p) { if ($p['variadic']) return true; }
        return false;
    }

    /* ================================================================ */
    /* expressions                                                      */
    /* ================================================================ */

    public function evalExpr(array $e, PyHPEnv $env) {
        switch ($e['k']) {
            case 'Num': case 'Str': case 'Bool':
                return $e['v'];
            case 'Null':
                return null;
            case 'FStr': {
                $out = '';
                foreach ($e['parts'] as $p) {
                    if ($p[0] === 's') { $out .= $p[1]; continue; }
                    $v = $this->evalExpr($p[1], $env);
                    $out .= isset($p[2]) ? pyhp_format($v, $p[2]) : pyhp_tostr($v);
                }
                return $out;
            }
            case 'Var':
                return $env->get($e['name']);
            case 'This':
                return $env->get('this');
            case 'Super':
                return null; // only meaningful behind Member/Call
            case 'List': {
                $items = [];
                foreach ($e['items'] as $it) { $items[] = $this->evalExpr($it, $env); }
                return new PyList($items);
            }
            case 'Dict': {
                $d = [];
                foreach ($e['pairs'] as [$pk, $pv]) {
                    $d[pyhp_guard_key($this->evalExpr($pk, $env))] = $this->evalExpr($pv, $env);
                }
                return new PyDict($d);
            }
            case 'Comp':
                return $this->evalComp($e, $env);
            case 'Un': {
                $v = $this->evalExpr($e['e'], $env);
                return match ($e['op']) {
                    '-' => pyhp_neg($v),
                    '!' , 'not' => pyhp_not($v),
                    '~' => pyhp_bitnot($v),
                    default => throw new PyHPRuntimeError("unknown unary operator '{$e['op']}'"),
                };
            }
            case 'Post': {
                $name = $e['e']['name'];
                $old = $env->get($name);
                if (!pyhp_is_num($old)) {
                    throw new PyHPTypeError("'{$e['op']}' requires a numeric variable, got " . pyhp_type_name($old));
                }
                $env->assign($name, $e['op'] === '++' ? pyhp_add($old, 1) : pyhp_sub($old, 1));
                return $old;
            }
            case 'Bin': {
                $op = $e['op'];
                if ($op === 'and') {
                    $l = $this->evalExpr($e['l'], $env);
                    return pyhp_truthy($l) ? $this->evalExpr($e['r'], $env) : $l;
                }
                if ($op === 'or') {
                    $l = $this->evalExpr($e['l'], $env);
                    return pyhp_truthy($l) ? $l : $this->evalExpr($e['r'], $env);
                }
                if ($op === ',') { // for-update sequence
                    $this->evalExpr($e['l'], $env);
                    return $this->evalExpr($e['r'], $env);
                }
                $l = $this->evalExpr($e['l'], $env);
                $r = $this->evalExpr($e['r'], $env);
                return $this->applyBin($op, $l, $r, $e['line']);
            }
            case 'Ternary':
                return pyhp_truthy($this->evalExpr($e['c'], $env))
                    ? $this->evalExpr($e['a'], $env)
                    : $this->evalExpr($e['b'], $env);
            case 'Idx': {
                $obj = $this->evalExpr($e['obj'], $env);
                $idx = $this->evalExpr($e['idx'], $env);
                return pyhp_get($obj, $idx);
            }
            case 'Slice': {
                $obj = $this->evalExpr($e['obj'], $env);
                $s = $e['start'] === null ? null : $this->evalExpr($e['start'], $env);
                $t = $e['stop'] === null ? null : $this->evalExpr($e['stop'], $env);
                $st = $e['step'] === null ? null : $this->evalExpr($e['step'], $env);
                return pyhp_slice($obj, $s, $t, $st);
            }
            case 'Member':
                return $this->evalMember($e, $env);
            case 'Assign':
                return $this->evalAssign($e, $env);
            case 'Call':
                return $this->evalCall($e, $env);
            case 'Func':
                $f = new InterpFunc();
                $f->name = $e['name'] ?? '<lambda>';
                $f->params = $e['params'];
                $f->ret = $e['ret'] ?? null;
                $f->body = $e['body']['stmts'];
                $f->env = $env;
                $f->interp = $this;
                return $f;
        }
        throw new PyHPRuntimeError("line {$e['line']}: unsupported expression kind '{$e['k']}'");
    }

    private function applyBin(string $op, $l, $r, int $line) {
        return match ($op) {
            '+' => pyhp_add($l, $r),
            '-' => pyhp_sub($l, $r),
            '*' => pyhp_mul($l, $r),
            '/' => pyhp_div($l, $r),
            '%' => pyhp_mod($l, $r),
            '**' => pyhp_pow($l, $r),
            '&' => pyhp_bitand($l, $r),
            '|' => pyhp_bitor($l, $r),
            '^' => pyhp_bitxor($l, $r),
            '<<' => pyhp_lshift($l, $r),
            '>>' => pyhp_rshift($l, $r),
            '==' => pyhp_eq($l, $r),
            '!=' => !pyhp_eq($l, $r),
            '<' => pyhp_compare($l, $r) < 0,
            '<=' => pyhp_compare($l, $r) <= 0,
            '>' => pyhp_compare($l, $r) > 0,
            '>=' => pyhp_compare($l, $r) >= 0,
            'in' => pyhp_in($l, $r),
            'notin' => !pyhp_in($l, $r),
            default => throw new PyHPRuntimeError("line {$line}: unknown operator '{$op}'"),
        };
    }

    private function evalComp(array $e, PyHPEnv $env) {
        $cenv = new PyHPEnv($env);
        $iter = pyhp_foreach($this->evalExpr($e['iter'], $cenv));
        if (!$e['isDict']) {
            $out = [];
            foreach ($iter as $k => $v) {
                $this->bindCompVars($e, $cenv, $k, $v);
                if ($e['cond'] !== null && !pyhp_truthy($this->evalExpr($e['cond'], $cenv))) continue;
                $out[] = $this->evalExpr($e['val'], $cenv);
            }
            return new PyList($out);
        }
        $d = [];
        foreach ($iter as $k => $v) {
            $this->bindCompVars($e, $cenv, $k, $v);
            if ($e['cond'] !== null && !pyhp_truthy($this->evalExpr($e['cond'], $cenv))) continue;
            $key = pyhp_guard_key($this->evalExpr($e['key'], $cenv));
            $d[$key] = $this->evalExpr($e['val'], $cenv);
        }
        return new PyDict($d);
    }

    private function bindCompVars(array $e, PyHPEnv $cenv, $k, $v): void {
        if ($e['var2'] !== null) {
            $cenv->declare($e['var1'], $k);
            $cenv->declare($e['var2'], $v);
        } else {
            $cenv->declare($e['var1'], $v);
        }
    }

    private function evalMember(array $e, PyHPEnv $env) {
        $obj = $this->evalExpr($e['obj'], $env);
        return $this->memberRead($obj, $e['name'], $e['line']);
    }

    private function memberRead($obj, string $name, int $line) {
        if ($obj instanceof InterpInstance) {
            if (array_key_exists($name, $obj->props)) return $obj->props[$name];
            $f = $this->findMethod($obj->cls, $name);
            if ($f !== null) return $f; // bound-method style access
            throw new PyHPError("line {$line}: " . pyhp_type_name($obj) . " object has no property '{$name}'");
        }
        if (is_object($obj)) {
            if ($obj instanceof Closure || $obj instanceof InterpFunc || $obj instanceof PyHPBuiltin) {
                throw new PyHPError("line {$line}: functions have no property '{$name}'");
            }
            if ($obj instanceof PyDict && array_key_exists($name, $obj->d)
                && !method_exists($obj, $name)) {
                return $obj->d[$name]; // module table entry (function, class factory, value)
            }
            if (property_exists($obj, $name)) {
                return $obj->$name;
            }
            if (method_exists($obj, $name)) {
                return fn(...$args) => pyhp_call_method($obj, $name, $args);
            }
            throw new PyHPError("line {$line}: " . pyhp_type_name($obj) . " object has no property '{$name}'");
        }
        throw new PyHPError("line {$line}: '" . pyhp_type_name($obj) . "' has no property '{$name}'");
    }

    private function evalAssign(array $e, PyHPEnv $env) {
        $target = $e['target'];
        $op = $e['op'];
        if ($target['k'] === 'Var') {
            $name = $target['name'];
            if ($op === '=') {
                $v = $this->evalExpr($e['value'], $env);
                $env->assign($name, $v);
                return $v;
            }
            $old = $env->get($name);
            $v = $this->evalExpr($e['value'], $env);
            $new = $this->compoundValue($op, $old, $v, $e['line']);
            $env->assign($name, $new);
            return $new;
        }
        if ($target['k'] === 'Idx') {
            $obj = $this->evalExpr($target['obj'], $env);
            $idx = $this->evalExpr($target['idx'], $env);
            if ($op === '=') {
                $v = $this->evalExpr($e['value'], $env);
                pyhp_set($obj, $idx, $v);
                return $v;
            }
            $old = pyhp_get($obj, $idx);
            $v = $this->evalExpr($e['value'], $env);
            $new = $this->compoundValue($op, $old, $v, $e['line']);
            pyhp_set($obj, $idx, $new);
            return $new;
        }
        if ($target['k'] === 'Member') {
            if ($target['obj']['k'] === 'This') {
                $inst = $env->get('this');
            } elseif ($target['obj']['k'] === 'Var') {
                $inst = $env->get($target['obj']['name']);
            } else {
                throw new PyHPSyntaxError("line {$e['line']}: can only assign to properties of 'this' or a variable");
            }
            if (!($inst instanceof InterpInstance)) {
                throw new PyHPTypeError("line {$e['line']}: cannot set property '" . $target['name'] . "' on " . pyhp_type_name($inst));
            }
            $name = $target['name'];
            if ($op === '=') {
                $v = $this->evalExpr($e['value'], $env);
                $this->setProp($inst, $name, $v, $e['line']);
                return $v;
            }
            $old = $inst->props[$name] ?? null;
            $v = $this->evalExpr($e['value'], $env);
            $new = $this->compoundValue($op, $old, $v, $e['line']);
            $this->setProp($inst, $name, $new, $e['line']);
            return $new;
        }
        throw new PyHPSyntaxError("line {$e['line']}: invalid assignment target");
    }

    private function setProp(InterpInstance $inst, string $name, $v, int $line): void {
        $t = $inst->propTypes[$name] ?? null;
        if ($t !== null) $v = pyhp_check_type($v, $t, $name);
        $inst->props[$name] = $v;
    }

    private function compoundValue(string $op, $old, $v, int $line) {
        return match ($op) {
            '+=' => pyhp_add($old, $v),
            '-=' => pyhp_sub($old, $v),
            '*=' => pyhp_mul($old, $v),
            '/=' => pyhp_div($old, $v),
            '%=' => pyhp_mod($old, $v),
            '**=' => pyhp_pow($old, $v),
            '&=' => pyhp_bitand($old, $v),
            '|=' => pyhp_bitor($old, $v),
            '^=' => pyhp_bitxor($old, $v),
            '<<=' => pyhp_lshift($old, $v),
            '>>=' => pyhp_rshift($old, $v),
            default => throw new PyHPRuntimeError("line {$line}: unknown assignment operator '{$op}'"),
        };
    }

    private function evalCall(array $e, PyHPEnv $env) {
        $callee = $e['callee'];
        $args = [];
        foreach ($e['args'] as $a) { $args[] = $this->evalExpr($a, $env); }
        $kws = [];
        foreach (($e['kwargs'] ?? []) as $kw) {
            $kws[] = ['name' => $kw['name'], 'value' => $this->evalExpr($kw['expr'], $env)];
        }

        // super handling
        if ($callee['k'] === 'Super') {
            return $this->callSuperConstructor($args, $kws, $env, $e['line']);
        }
        if ($callee['k'] === 'Member' && $callee['obj']['k'] === 'Super') {
            return $this->callSuperMethod($callee['name'], $args, $kws, $env, $e['line']);
        }
        if ($callee['k'] === 'Member' && $callee['obj']['k'] === 'This') {
            $inst = $env->get('this');
            if ($inst instanceof InterpInstance) {
                $f = $this->findMethod($inst->cls, $callee['name']);
                if ($f === null) {
                    throw new PyHPError("line {$e['line']}: " . pyhp_type_name($inst) . " has no method '{$callee['name']}'");
                }
                return $this->invokeMethodWithSuper($inst, $f, $args, $kws);
            }
        }
        if ($callee['k'] === 'Member') {
            $obj = $this->evalExpr($callee['obj'], $env);
            if ($obj instanceof InterpInstance) {
                $f = $this->findMethod($obj->cls, $callee['name']);
                if ($f === null) {
                    throw new PyHPError("line {$e['line']}: " . pyhp_type_name($obj) . " has no method '{$callee['name']}'");
                }
                return $this->invokeMethodWithSuper($obj, $f, $args, $kws);
            }
            if (is_object($obj)) {
                try {
                    if (method_exists($obj, $callee['name'])) {
                        if (count($kws) > 0) {
                            return pyhp_call_kw_method($obj, $callee['name'], $args, $kws, $callee['name']);
                        }
                        return $obj->{$callee['name']}(...$args);
                    }
                } catch (ArgumentCountError $err) {
                    throw new PyHPTypeError("{$callee['name']}(): wrong number of arguments — " . pyhp_first_line($err->getMessage()));
                } catch (TypeError $err) {
                    throw new PyHPTypeError("{$callee['name']}(): " . pyhp_first_line($err->getMessage()));
                }
                // callables stored in dicts (module tables) are dot-callable
                if ($obj instanceof PyDict && array_key_exists($callee['name'], $obj->d)
                    && is_callable($obj->d[$callee['name']])) {
                    return pyhp_callfunc($obj->d[$callee['name']], $args, $callee['name'], $kws);
                }
            }
            if (is_string($obj)) {
                if (count($kws) > 0) {
                    throw new PyHPTypeError("line {$e['line']}: string methods do not accept keyword arguments yet");
                }
                return pyhp_str_method($obj, $callee['name'], $args);
            }
            throw new PyHPError("line {$e['line']}: '" . pyhp_type_name($obj) . "' object has no method '{$callee['name']}'");
        }
        if ($callee['k'] === 'Var') {
            $name = $callee['name'];
            if ($env->has($name)) {
                $target = $env->get($name);
                if (!is_callable($target)) {
                    throw new PyHPTypeError("line {$e['line']}: '{$name}' is not callable (" . pyhp_type_name($target) . ")");
                }
                if ($target instanceof InterpFunc) {
                    return $this->invokeFunc($target, $args, $kws);
                }
                if ($target instanceof PyHPBuiltin) {
                    return pyhp_call_builtin($target->name, $args, $kws);
                }
                if (count($kws) > 0) {
                    throw new PyHPTypeError("line {$e['line']}: '{$name}' does not accept keyword arguments");
                }
                return $target(...$args);
            }
            if ($name === 'require') {
                if (count($kws) > 0) {
                    throw new PyHPError("line {$e['line']}: require() takes exactly 1 argument(s)");
                }
                return $this->loadModule($args, $e['line']);
            }
            $cls = $this->clsFind($name);
            if ($cls !== null) {
                return $this->instantiate($cls, $args, $kws);
            }
            if (pyhp_builtin_exists($name)) {
                return pyhp_call_builtin($name, $args, $kws);
            }
            if (PyHPCppNative::has($name)) { // native cpp export (lazy compile on first call)
                return PyHPCppNative::call($name, $args, $kws);
            }
            throw new PyHPError("line {$e['line']}: undefined function '{$name}'");
        }
        // general callable expression
        $target = $this->evalExpr($callee, $env);
        if (!is_callable($target)) {
            throw new PyHPTypeError("line {$e['line']}: expression is not callable (" . pyhp_type_name($target) . ")");
        }
        if ($target instanceof InterpFunc) {
            return $this->invokeFunc($target, $args, $kws);
        }
        if (count($kws) > 0) {
            throw new PyHPTypeError("line {$e['line']}: expression does not accept keyword arguments");
        }
        return $target(...$args);
    }

    private function callSuperConstructor(array $args, array $kws, PyHPEnv $env, int $line) {
        [$inst, $parent] = $this->superContext($env, $line);
        $ctor = $this->findMethod($parent, '__construct') ?? $this->findMethod($parent, '__init');
        if ($ctor !== null) {
            // keep __supercls pointing at the class that DEFINES the ctor
            $this->callFunction($ctor, $args, $inst, $ctor->cls, $kws);
        }
        return null;
    }

    private function callSuperMethod(string $name, array $args, array $kws, PyHPEnv $env, int $line) {
        [$inst, $parent] = $this->superContext($env, $line);
        $f = $this->findMethod($parent, $name);
        if ($f === null) {
            throw new PyHPError("line {$line}: parent class has no method '{$name}'");
        }
        return $this->callFunction($f, $args, $inst, $f->cls, $kws);
    }

    private function superContext(PyHPEnv $env, int $line): array {
        if (!$env->has('this') || !$env->has('__supercls')) {
            throw new PyHPError("line {$line}: 'super' can only be used inside a class method");
        }
        $inst = $env->get('this');
        $cls = $env->get('__supercls');
        $parent = $cls->parent;
        if ($parent === null) {
            throw new PyHPError("line {$line}: class '{$cls->name}' has no parent");
        }
        return [$inst, $parent];
    }
}
