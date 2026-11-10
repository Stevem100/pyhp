<?php
/**
 * PyHP bytecode VM — the interpreter path's fast engine.
 *
 * Programs compile to a flat array of ops executed by a dispatch loop over a
 * value stack per call frame. Hot ops (constants, variables, arithmetic,
 * comparisons, jumps, echo, return) run directly; complex subtrees (calls,
 * member access, comprehensions, class declarations, cpp blocks) carry their
 * AST in the op and delegate to the tree-walking interpreter, so semantics
 * are shared with `--tree` mode by construction. Functions compile to their
 * own bytecode once (cached on the InterpFunc); generator functions bypass
 * the VM and use the compiled-PHP generator path (GenCompiler).
 *
 * Engine selection: PyHPInterpreter::runProgram($stmts, 'vm') (default from
 * `pyhp run`) or 'tree' (pyhp run --tree / the REPL).
 */

require_once __DIR__ . '/runtime.php';

class PyHPVmFrame
{
    public array $stack = [];
    public array $loopStack = [];   // [exitIp, topIp] pairs for dynamic break/continue
    public function __construct(
        public array $ops,
        public PyHPEnv $env,
    ) {}
}

class PyHPVm
{
    /** Compile a statement list to bytecode. Ops: [op, a, b, line]. */
    public static function compile(array $stmts): array {
        $ops = [];
        self::compileStmts($stmts, $ops);
        $ops[] = ['END', null, null, 0];
        return $ops;
    }

    /** Compile a function body (no trailing END — RETURN handles it). */
    public static function compileFunction(array $stmts): array {
        $ops = [];
        self::compileStmts($stmts, $ops);
        $ops[] = ['RET', null, null, 0];
        return $ops;
    }

    /* ================================================================ */
    /* compiler                                                         */
    /* ================================================================ */

    private static function compileStmts(array $stmts, array &$ops): void {
        foreach ($stmts as $s) { self::compileStmt($s, $ops); }
    }

    private static function compileStmt(array $s, array &$ops): void {
        switch ($s['k']) {
            case 'Expr':
                self::compileExpr($s['e'], $ops);
                $ops[] = ['POP', null, null, $s['line']];
                return;
            case 'VarDecl':
                if ($s['init'] !== null) {
                    self::compileExpr($s['init'], $ops);
                } else {
                    $ops[] = ['CONST', null, null, $s['line']];
                }
                $ops[] = ['DECL', $s['name'], $s['type'], $s['line']];
                return;
            case 'Func':
                if ($s['name'] === null) {
                    self::compileExpr($s, $ops);   // anonymous function expression
                    $ops[] = ['POP', null, null, $s['line']];
                    return;
                }
                $ops[] = ['FUNCDEF', $s, null, $s['line']];
                return;
            case 'Class':
            case 'Cpp':
                $ops[] = ['STMT', $s, null, $s['line']];
                return;
            case 'If': {
                $patches = [];   // [jmpfAddr, jmpAddr]
                $last = count($s['branches']) - 1;
                foreach ($s['branches'] as $bi => [$cond, $body]) {
                    self::compileExpr($cond, $ops);
                    $jmpf = count($ops);
                    $ops[] = ['JMPF', null, null, $s['line']];
                    self::compileStmt($body, $ops);
                    $jmp = count($ops);
                    $ops[] = ['JMP', null, null, $s['line']];
                    $patches[] = [$jmpf, $jmp];
                }
                if ($s['else'] !== null) {
                    self::compileStmt($s['else'], $ops);
                }
                $end = count($ops);
                foreach ($patches as $i => [$jmpf, $jmp]) {
                    // falsy condition -> next branch's condition (or end/else)
                    $ops[$jmpf][1] = ($i < $last || $s['else'] !== null) ? $jmp + 1 : $end;
                    // branch done -> end
                    $ops[$jmp][1] = $end;
                }
                return;
            }
            case 'While': {
                $top = count($ops);
                $ops[] = ['LOOP_BEGIN', null, null, $s['line']];   // exit patched below; continue = cond
                self::compileExpr($s['cond'], $ops);
                $exitJmp = count($ops);
                $ops[] = ['JMPF', null, null, $s['line']];
                $breaks = [];
                $conts = [];
                self::compileLoopBody($s['body'], $ops, $breaks, $conts);
                $ops[] = ['JMP', $top + 1, null, $s['line']];   // back edge -> cond (not LOOP_BEGIN)
                $end = count($ops);
                foreach ($breaks as $bAddr) { $ops[$bAddr][0] = 'JMP'; $ops[$bAddr][1] = $end; }
                foreach ($conts as $cAddr) { $ops[$cAddr][0] = 'JMP'; $ops[$cAddr][1] = $top + 1; }
                $ops[$exitJmp][1] = $end;
                $ops[$top][1] = $end;          // break -> end
                $ops[$top][2] = $top + 1;      // continue -> cond
                $ops[] = ['LOOP_END', null, null, $s['line']];
                return;
            }
            case 'DoWhile': {
                $top = count($ops);
                $ops[] = ['LOOP_BEGIN', null, null, $s['line']];
                $breaks = [];
                $conts = [];
                self::compileLoopBody($s['body'], $ops, $breaks, $conts);
                $condStart = count($ops);
                self::compileExpr($s['cond'], $ops);
                $exitJmp = count($ops);
                $ops[] = ['JMPF', null, null, $s['line']];
                $ops[] = ['JMP', $top + 1, null, $s['line']];   // back edge -> body start
                $end = count($ops);
                foreach ($breaks as $bAddr) { $ops[$bAddr][0] = 'JMP'; $ops[$bAddr][1] = $end; }
                foreach ($conts as $cAddr) { $ops[$cAddr][0] = 'JMP'; $ops[$cAddr][1] = $condStart; }
                $ops[$exitJmp][1] = $end;
                $ops[$top][1] = $end;          // break -> end
                $ops[$top][2] = $condStart;    // continue -> cond
                $ops[] = ['LOOP_END', null, null, $s['line']];
                return;
            }
            case 'For': {
                if ($s['init'] !== null) self::compileStmt($s['init'], $ops);
                $top = count($ops);
                $ops[] = ['LOOP_BEGIN', null, null, $s['line']];
                if ($s['cond'] !== null) {
                    self::compileExpr($s['cond'], $ops);
                    $exitJmp = count($ops);
                    $ops[] = ['JMPF', null, null, $s['line']];
                } else {
                    $exitJmp = null;
                }
                $breaks = [];
                $conts = [];
                self::compileLoopBody($s['body'], $ops, $breaks, $conts);
                $updStart = count($ops);
                if ($s['update'] !== null) self::compileExpr($s['update'], $ops);
                if ($s['update'] !== null) $ops[] = ['POP', null, null, $s['line']];
                $ops[] = ['JMP', $top + 1, null, $s['line']];   // back edge -> cond
                $end = count($ops);
                foreach ($breaks as $bAddr) { $ops[$bAddr][0] = 'JMP'; $ops[$bAddr][1] = $end; }
                foreach ($conts as $cAddr) { $ops[$cAddr][0] = 'JMP'; $ops[$cAddr][1] = $updStart; }
                if ($exitJmp !== null) $ops[$exitJmp][1] = $end;
                $ops[$top][1] = $end;                             // break -> end
                $ops[$top][2] = $s['update'] !== null ? $updStart : $top + 1;   // dynamic continue
                $ops[] = ['LOOP_END', null, null, $s['line']];
                return;
            }
            case 'Foreach':
                $ops[] = ['FOREACH', $s, null, $s['line']];
                return;
            case 'Try':
                $ops[] = ['TRY', $s, null, $s['line']];
                return;
            case 'Throw':
                self::compileExpr($s['e'], $ops);
                $ops[] = ['THROW', null, null, $s['line']];
                return;
            case 'Return':
                if ($s['e'] !== null) self::compileExpr($s['e'], $ops);
                else $ops[] = ['CONST', null, null, $s['line']];
                $ops[] = ['RET', null, null, $s['line']];
                return;
            case 'Break':
                $ops[] = ['BREAK', null, null, $s['line']];
                return;
            case 'Continue':
                $ops[] = ['CONT', null, null, $s['line']];
                return;
            case 'Echo':
                foreach ($s['args'] as $a) self::compileExpr($a, $ops);
                $ops[] = ['ECHO', count($s['args']), null, $s['line']];
                return;
            case 'Block':
                self::compileStmts($s['stmts'], $ops);
                return;
            default:
                // anything exotic runs through the tree-walker
                $ops[] = ['STMT', $s, null, $s['line']];
                return;
        }
    }

    /** Loop body: collect unpatched break/continue op addresses (nested loops
     *  patch their own first, so only this loop's remain). */
    private static function compileLoopBody(array $body, array &$ops, array &$breakAddrs, array &$contAddrs): void {
        $start = count($ops);
        self::compileStmt($body, $ops);
        $end = count($ops);
        for ($i = $start; $i < $end; $i++) {
            if ($ops[$i][0] === 'BREAK') { $breakAddrs[] = $i; }
            if ($ops[$i][0] === 'CONT') { $contAddrs[] = $i; }
        }
    }

    private static function compileExpr(array $e, array &$ops): void {
        switch ($e['k']) {
            case 'Num':
                $ops[] = ['CONST', $e['v'], null, $e['line']];
                return;
            case 'Str':
            case 'Bool':
            case 'Null':
                $ops[] = ['CONST', $e['v'] ?? null, null, $e['line']];
                return;
            case 'Var':
                $ops[] = ['LOAD', $e['name'], null, $e['line']];
                return;
            case 'This':
                $ops[] = ['LOAD', 'this', null, $e['line']];
                return;
            case 'List':
                foreach ($e['items'] as $it) self::compileExpr($it, $ops);
                $ops[] = ['LIST', count($e['items']), null, $e['line']];
                return;
            case 'Dict':
                foreach ($e['pairs'] as [$pk, $pv]) { self::compileExpr($pk, $ops); self::compileExpr($pv, $ops); }
                $ops[] = ['DICT', count($e['pairs']), null, $e['line']];
                return;
            case 'FStr':
                $ops[] = ['EVAL', $e, null, $e['line']];   // cheap to delegate
                return;
            case 'Bin': {
                $op = $e['op'];
                if ($op === 'and' || $op === 'or') {
                    self::compileExpr($e['l'], $ops);
                    $j = count($ops);
                    $ops[] = $op === 'and' ? ['TAND', null, null, $e['line']] : ['TOR', null, null, $e['line']];
                    self::compileExpr($e['r'], $ops);
                    $ops[$j][1] = count($ops);   // falsy(and)/truthy(or): keep left value, skip right
                    return;
                }
                if ($op === ',') { $ops[] = ['EVAL', $e, null, $e['line']]; return; }
                self::compileExpr($e['l'], $ops);
                self::compileExpr($e['r'], $ops);
                $ops[] = ['BIN', $op, $e['line'], $e['line']];
                return;
            }
            case 'Un':
                self::compileExpr($e['e'], $ops);
                $ops[] = ['UN', $e['op'], null, $e['line']];
                return;
            case 'Post':
                $ops[] = ['POST', $e['e']['name'], $e['op'], $e['line']];
                return;
            case 'Ternary': {
                self::compileExpr($e['c'], $ops);
                $jf = count($ops);
                $ops[] = ['JMPF_POP', null, null, $e['line']];
                self::compileExpr($e['a'], $ops);
                $jend = count($ops);
                $ops[] = ['JMP', null, null, $e['line']];
                self::compileExpr($e['b'], $ops);
                // false condition -> the ELSE branch (after the jump-over)
                $ops[$jf][1] = $jend + 1;
                $ops[$jend][1] = count($ops);
                return;
            }
            case 'Idx':
                self::compileExpr($e['obj'], $ops);
                self::compileExpr($e['idx'], $ops);
                $ops[] = ['IDX', null, null, $e['line']];
                return;
            case 'Slice': {
                self::compileExpr($e['obj'], $ops);
                foreach (['start', 'stop', 'step'] as $p) {
                    if ($e[$p] !== null) self::compileExpr($e[$p], $ops);
                    else $ops[] = ['CONST', null, null, $e['line']];
                }
                $ops[] = ['SLICE', null, null, $e['line']];
                return;
            }
            case 'Assign':
                // fast path: plain var = expr
                if ($e['op'] === '=' && $e['target']['k'] === 'Var') {
                    self::compileExpr($e['value'], $ops);
                    $ops[] = ['STORE', $e['target']['name'], null, $e['line']];
                    return;
                }
                $ops[] = ['EVAL', $e, null, $e['line']];
                return;
            default:
                // Call, Member, Comp, Func, Yield(!), Super … — delegate
                $ops[] = ['EVAL', $e, null, $e['line']];
                return;
        }
    }

    /* ================================================================ */
    /* execution                                                        */
    /* ================================================================ */

    /** Run top-level bytecode in $env; used by runProgram(engine 'vm'). */
    public static function run(PyHPInterpreter $interp, array $ops, PyHPEnv $env) {
        $frame = new PyHPVmFrame($ops, $env);
        return self::dispatch($interp, $frame);
    }

    /** Run a compiled function body; returns the return value. */
    public static function runFunction(PyHPInterpreter $interp, InterpFunc $f, PyHPEnv $env) {
        if ($f->vmOps === null) {
            $f->vmOps = self::compileFunction($f->body);
        }
        $frame = new PyHPVmFrame($f->vmOps, $env);
        return self::dispatch($interp, $frame);
    }

    private static function dispatch(PyHPInterpreter $interp, PyHPVmFrame $frame, int $startIp = 0) {
        $ops = $frame->ops;
        $n = count($ops);
        $ip = $startIp;
        $stack = &$frame->stack;
        $env = $frame->env;

        try {
            while ($ip < $n) {
                [$op, $a, $b, $line] = $ops[$ip];
                switch ($op) {
                    case 'CONST': $stack[] = $a; $ip++; break;
                    case 'LOAD':  $stack[] = $env->get($a); $ip++; break;
                    case 'STORE': $v = array_pop($stack); $env->assign($a, $v); $stack[] = $v; $ip++; break;
                    case 'DECL': {
                        $v = array_pop($stack);
                        if ($b !== null && $b !== 'var') $v = pyhp_check_type($v, $b, $a);
                        $env->declare($a, $v, $b);
                        $ip++;
                        break;
                    }
                    case 'POP': array_pop($stack); $ip++; break;
                    case 'LIST': {
                        $items = array_splice($stack, count($stack) - $a);
                        $stack[] = new PyList($items);
                        $ip++;
                        break;
                    }
                    case 'DICT': {
                        $flat = array_splice($stack, count($stack) - 2 * $a);
                        $stack[] = new PyDict(pyhp_dict_pairs($flat));
                        $ip++;
                        break;
                    }
                    case 'BIN': {
                        $r = array_pop($stack);
                        $l = array_pop($stack);
                        $stack[] = match ($a) {
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
                            default => throw new PyHPRuntimeError("line {$line}: unknown operator '{$a}'"),
                        };
                        $ip++;
                        break;
                    }
                    case 'UN': {
                        $v = array_pop($stack);
                        $stack[] = match ($a) {
                            '-' => pyhp_neg($v),
                            '!', 'not' => pyhp_not($v),
                            '~' => pyhp_bitnot($v),
                            default => throw new PyHPRuntimeError("line {$line}: unknown unary operator '{$a}'"),
                        };
                        $ip++;
                        break;
                    }
                    case 'POST': {
                        $old = $env->get($a);
                        if (!pyhp_is_num($old)) {
                            throw new PyHPTypeError("'{$b}' requires a numeric variable, got " . pyhp_type_name($old));
                        }
                        $env->assign($a, $b === '++' ? pyhp_add($old, 1) : pyhp_sub($old, 1));
                        $stack[] = $old;
                        $ip++;
                        break;
                    }
                    case 'TAND': {
                        // value-preserving short-circuit and
                        if (!pyhp_truthy(end($stack))) { $ip = $a; }        // keep left value
                        else { array_pop($stack); $ip++; }                  // evaluate right
                        break;
                    }
                    case 'TOR': {
                        if (pyhp_truthy(end($stack))) { $ip = $a; }         // keep left value
                        else { array_pop($stack); $ip++; }                  // evaluate right
                        break;
                    }
                    case 'JMP': $ip = $a; break;
                    case 'JMPF': {
                        $v = array_pop($stack);
                        $ip = pyhp_truthy($v) ? $ip + 1 : $a;
                        break;
                    }
                    case 'JMPF_KEEP': {
                        $ip = pyhp_truthy(end($stack)) ? $ip + 1 : $a;
                        break;
                    }
                    case 'JMPF_POP': {
                        $v = array_pop($stack);
                        $ip = pyhp_truthy($v) ? $ip + 1 : $a;
                        break;
                    }
                    case 'BREAK': throw new PyHPBreak();
                    case 'CONT': throw new PyHPContinue();
                    case 'LOOP_BEGIN': {
                        $frame->loopStack[] = [$a, $b];   // [exitIp, continueIp]
                        $ip++;
                        break;
                    }
                    case 'LOOP_END': {
                        if (count($frame->loopStack) > 0) array_pop($frame->loopStack);
                        $ip++;
                        break;
                    }
                    case 'ECHO': {
                        $args = array_splice($stack, count($stack) - $a);
                        foreach ($args as $arg) { echo pyhp_tostr($arg); }
                        $ip++;
                        break;
                    }
                    case 'IDX': {
                        $i = array_pop($stack);
                        $c = array_pop($stack);
                        $stack[] = pyhp_get($c, $i);
                        $ip++;
                        break;
                    }
                    case 'SLICE': {
                        $st = array_pop($stack);
                        $t = array_pop($stack);
                        $s = array_pop($stack);
                        $c = array_pop($stack);
                        $stack[] = pyhp_slice($c, $s, $t, $st);
                        $ip++;
                        break;
                    }
                    case 'EVAL': {
                        $stack[] = $interp->evalExpr($a, $env);
                        $ip++;
                        break;
                    }
                    case 'STMT': {
                        $interp->execStmt($a, $env);
                        $ip++;
                        break;
                    }
                    case 'FUNCDEF': {
                        $f = new InterpFunc();
                        $f->name = $a['name'] ?? '<lambda>';
                        $f->params = $a['params'];
                        $f->ret = $a['ret'] ?? null;
                        $f->body = $a['body']['stmts'];
                        $f->env = $env;
                        $f->interp = $interp;
                        $f->fileDir = $interp->currentDirPub();
                        $f->isGenerator = pyhp_ast_has_yield($f->body);
                        $f->vmOps = $f->isGenerator ? null : self::compileFunction($f->body);
                        $env->assign($a['name'], $f);
                        $ip++;
                        break;
                    }
                    case 'FOREACH': {
                        self::runForeach($interp, $frame, $a);
                        $ip++;
                        break;
                    }
                    case 'TRY': {
                        self::runTryBody($interp, $frame, $a, $env);
                        $ip++;
                        break;
                    }
                    case 'THROW': {
                        $v = array_pop($stack);
                        throw new PyHPThrow($interp->asThrowableInstancePub($v));
                    }
                    case 'RET': {
                        return array_pop($stack);
                    }
                    case 'END': {
                        return array_pop($stack);
                    }
                    default:
                        throw new PyHPRuntimeError("unknown opcode '{$op}'");
                }
            }
            return array_pop($stack);
        } catch (PyHPReturn $r) {
            // a delegated stmt (execStmt) raised return — honour it
            return $r->value;
        } catch (PyHPBreak | PyHPContinue $c) {
            // dynamic break/continue: unwind to the innermost loop in THIS frame
            if (count($frame->loopStack) > 0) {
                [$exitIp, $contIp] = array_pop($frame->loopStack);
                $ip = $c instanceof PyHPBreak ? $exitIp : $contIp;
                return self::dispatch($interp, $frame, $ip);
            }
            throw $c;
        } catch (PyHPThrow | PyHPError $e) {
            throw $e;
        }
    }

    /** Foreach statement: stream the iterable lazily and run the body ops. */
    private static function runForeach(PyHPInterpreter $interp, PyHPVmFrame $frame, array $s): void {
        $itv = $interp->evalExpr($s['iter'], $frame->env);
        $items = $interp->lazyIterablePub($itv);
        $bodyOps = self::compileFunction($s['body']['stmts']);
        foreach ($items as $k => $v) {
            try {
                if ($s['var2'] !== null) {
                    $frame->env->declare($s['var1'], $k);
                    $frame->env->declare($s['var2'], $v);
                } else {
                    $frame->env->declare($s['var1'], $v);
                }
                $sub = new PyHPVmFrame($bodyOps, $frame->env);
                try {
                    self::dispatch($interp, $sub);
                } catch (PyHPContinue $c) { /* next item */ }
            } catch (PyHPBreak $b) {
                break;
            }
        }
    }

    /** Try statement: run the body via a nested dispatch with handler state. */
    private static function runTryBody(PyHPInterpreter $interp, PyHPVmFrame $frame, array $s, PyHPEnv $env): void {
        $bodyOps = self::compileFunction($s['body']['stmts']);
        $sub = new PyHPVmFrame($bodyOps, $env);
        try {
            self::dispatch($interp, $sub);
            // normal completion
            if ($s['finally'] !== null) $interp->execStmt($s['finally'], $env);
            return;
        } catch (PyHPThrow $t) {
            $inst = $t->value;
            $thisCatches = $s['catches'];
            foreach ($thisCatches as $c) {
                if ($c['type'] === null || $interp->instanceMatches($inst, $c['type'])) {
                    $cenv = new PyHPEnv($env);
                    $cenv->declare($c['var'], $inst);
                    try {
                        $catchOps = self::compileFunction($c['body']['stmts']);
                        $cframe = new PyHPVmFrame($catchOps, $cenv);
                        self::dispatch($interp, $cframe);
                        if ($s['finally'] !== null) $interp->execStmt($s['finally'], $env);
                        return;
                    } catch (PyHPReturn | PyHPBreak | PyHPContinue $x) {
                        if ($s['finally'] !== null) $interp->execStmt($s['finally'], $env);
                        throw $x;
                    } catch (PyHPThrow | PyHPError $e2) {
                        if ($s['finally'] !== null) $interp->execStmt($s['finally'], $env);
                        throw $e2;
                    }
                }
            }
            if ($s['finally'] !== null) $interp->execStmt($s['finally'], $env);
            throw $t;
        } catch (PyHPError $e) {
            $inst = $interp->errorInstanceFor($e);
            foreach ($s['catches'] as $c) {
                if ($c['type'] === null || $interp->instanceMatches($inst, $c['type'])) {
                    $cenv = new PyHPEnv($env);
                    $cenv->declare($c['var'], $inst);
                    try {
                        $catchOps = self::compileFunction($c['body']['stmts']);
                        $cframe = new PyHPVmFrame($catchOps, $cenv);
                        self::dispatch($interp, $cframe);
                        if ($s['finally'] !== null) $interp->execStmt($s['finally'], $env);
                        return;
                    } catch (PyHPReturn | PyHPBreak | PyHPContinue $x) {
                        if ($s['finally'] !== null) $interp->execStmt($s['finally'], $env);
                        throw $x;
                    } catch (PyHPThrow | PyHPError $e2) {
                        if ($s['finally'] !== null) $interp->execStmt($s['finally'], $env);
                        throw $e2;
                    }
                }
            }
            if ($s['finally'] !== null) $interp->execStmt($s['finally'], $env);
            throw $e;
        } catch (PyHPReturn | PyHPBreak | PyHPContinue $x) {
            if ($s['finally'] !== null) $interp->execStmt($s['finally'], $env);
            throw $x;
        }
    }
}
