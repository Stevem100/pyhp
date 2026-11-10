<?php
/**
 * Generator compiler for the interpreter path.
 *
 * A PyHP function containing `yield` runs as a real PHP Generator. Its body
 * is compiled once (and cached on the InterpFunc) into PHP source in which
 * every variable access goes through a PyHPEnv — so closures, module state,
 * `this` and late binding behave exactly like the tree-walking interpreter —
 * while `yield` maps to PHP's native yield. Complex subtrees (member access,
 * general calls, comprehensions, throws) are delegated to the interpreter at
 * runtime via pyhp_interp_eval(), which walks that subtree with full
 * interpreter semantics.
 *
 * The eval context that runs the produced source must define:
 *   $__pyhp_env    — the generator frame's PyHPEnv (params pre-bound)
 *   $__pyhp_interp — the PyHPInterpreter instance
 */

require_once __DIR__ . '/runtime.php';

class PyHPGenCompiler
{
    private int $tmpN = 0;

    /** Compile a generator body; the returned source evaluates to a PHP Generator. */
    public function compile(string $fnName, array $params, array $bodyStmts): string {
        $this->tmpN = 0;
        $body = [];
        $this->emitStmts($bodyStmts, $body, 1);
        $inner = implode("\n", array_map(fn($l) => $l === '' ? '' : '    ' . $l, $body));
        return "return (function () use (\$__pyhp_env, \$__pyhp_interp) {\n"
             . "// PyHP generator '{$fnName}' — variables live in \$__pyhp_env\n"
             . $inner . "\n})();";
    }

    /* ================================================================ */
    /* statements                                                        */
    /* ================================================================ */

    private function emitStmts(array $stmts, array &$L, int $ind): void {
        foreach ($stmts as $s) { $this->emitStmt($s, $L, $ind); }
    }

    private function w(array &$L, int $ind, string $line): void {
        $L[] = str_repeat('    ', $ind) . $line;
    }

    private function emitStmt(array $s, array &$L, int $ind): void {
        switch ($s['k']) {
            case 'Expr':
                $e = $s['e'];
                if ($e['k'] === 'Assign') { $this->emitAssign($e, $L, $ind); return; }
                if ($e['k'] === 'Post') {
                    $dir = $e['op'] === '++' ? '1' : '-1';
                    $this->w($L, $ind, "pyhp_env_post(\$__pyhp_env, " . var_export($e['e']['name'], true) . ", {$dir});");
                    return;
                }
                $this->w($L, $ind, $this->emitExpr($e) . ';');
                return;
            case 'VarDecl':
                if ($s['init'] !== null) {
                    $type = $s['type'] !== null && $s['type'] !== 'var' ? ', ' . var_export($s['type'], true) : '';
                    $this->w($L, $ind, "\$__pyhp_env->declare(" . var_export($s['name'], true) . ', ' . $this->emitExpr($s['init']) . "{$type});");
                } else {
                    $type = $s['type'] !== null && $s['type'] !== 'var' ? ', ' . var_export($s['type'], true) : '';
                    $this->w($L, $ind, "\$__pyhp_env->declare(" . var_export($s['name'], true) . ", null{$type}, true);");
                }
                return;
            case 'Func':
                if ($s['name'] === null) { $this->w($L, $ind, $this->emitExpr($s) . ';'); return; }
                $this->w($L, $ind, "\$__pyhp_env->assign(" . var_export($s['name'], true) . ', '
                    . 'pyhp_env_def_func($__pyhp_interp, $__pyhp_env, ' . var_export($s, true) . '));');
                return;
            case 'If': {
                $first = true;
                foreach ($s['branches'] as [$cond, $body]) {
                    $kw = $first ? 'if' : '} else if';
                    $this->w($L, $ind, "{$kw} (pyhp_truthy(" . $this->emitExpr($cond) . ')) {');
                    $first = false;
                    $this->emitStmts($body['stmts'], $L, $ind + 1);
                }
                if ($s['else'] !== null) {
                    $this->w($L, $ind, '} else {');
                    $this->emitStmts($s['else']['stmts'], $L, $ind + 1);
                }
                $this->w($L, $ind, '}');
                return;
            }
            case 'While':
                $this->w($L, $ind, 'while (pyhp_truthy(' . $this->emitExpr($s['cond']) . ')) {');
                $this->emitStmts($s['body']['stmts'], $L, $ind + 1);
                $this->w($L, $ind, '}');
                return;
            case 'DoWhile':
                $this->w($L, $ind, 'do {');
                $this->emitStmts($s['body']['stmts'], $L, $ind + 1);
                $this->w($L, $ind, '} while (pyhp_truthy(' . $this->emitExpr($s['cond']) . '));');
                return;
            case 'For':
                if ($s['init'] !== null) { $this->emitStmt($s['init'], $L, $ind); }
                $cond = $s['cond'] === null ? '' : 'pyhp_truthy(' . $this->emitExpr($s['cond']) . ')';
                $updates = [];
                if ($s['update'] !== null) { $this->collectUpdates($s['update'], $updates); }
                $upd = implode(', ', array_map(fn($u) => $this->updateCode($u), $updates));
                $this->w($L, $ind, "for (; {$cond}; {$upd}) {");
                $this->emitStmts($s['body']['stmts'], $L, $ind + 1);
                $this->w($L, $ind, '}');
                return;
            case 'Foreach':
                $this->w($L, $ind, 'foreach (pyhp_iter(' . $this->emitExpr($s['iter']) . ') as $__k => $__v) {');
                $this->w($L, $ind + 1, "\$__pyhp_env->declare(" . var_export($s['var1'], true) . ($s['var2'] !== null ? ', $__v);' : ');'));
                if ($s['var2'] !== null) {
                    $this->w($L, $ind + 1, "\$__pyhp_env->declare(" . var_export($s['var2'], true) . ', $__k);');
                }
                $this->emitStmts($s['body']['stmts'], $L, $ind + 1);
                $this->w($L, $ind, '}');
                return;
            case 'Try': {
                $catches = [];
                foreach ($s['catches'] as $c) { $catches[] = ['type' => $c['type'], 'var' => $c['var']]; }
                $this->w($L, $ind, 'try {');
                $this->emitStmts($s['body']['stmts'], $L, $ind + 1);
                $this->w($L, $ind, '} catch (Exception $__e) {');
                $this->w($L, $ind + 1, '$__m = pyhp_try_match($__pyhp_interp, $__e, ' . var_export($catches, true) . ');');
                $this->w($L, $ind + 1, 'if ($__m !== null) {');
                foreach ($s['catches'] as $ci => $c) {
                    $kw = $ci === 0 ? 'if' : '} else if';
                    $this->w($L, $ind + 2, "{$kw} (\$__m[0] === {$ci}) {");
                    $this->w($L, $ind + 3, "\$__pyhp_env->declare(" . var_export($c['var'], true) . ', $__m[2]);');
                    $this->emitStmts($c['body']['stmts'], $L, $ind + 3);
                }
                $this->w($L, $ind + 2, '} else { throw $__e; }');
                $this->w($L, $ind + 1, '} else { throw $__e; }');
                $this->w($L, $ind, '}');
                if ($s['finally'] !== null) {
                    $this->w($L, $ind, 'finally {');
                    $this->emitStmts($s['finally']['stmts'], $L, $ind + 1);
                    $this->w($L, $ind, '}');
                }
                return;
            }
            case 'Throw':
                $this->w($L, $ind, 'pyhp_interp_exec($__pyhp_interp, $__pyhp_env, ' . var_export($s, true) . ');');
                return;
            case 'Return':
                $this->w($L, $ind, 'return ' . ($s['e'] === null ? '' : $this->emitExpr($s['e'])) . ';');
                return;
            case 'Break':
                $this->w($L, $ind, 'break;');
                return;
            case 'Continue':
                $this->w($L, $ind, 'continue;');
                return;
            case 'Echo': {
                $parts = array_map(fn($a) => 'pyhp_tostr(' . $this->emitExpr($a) . ')', $s['args']);
                $this->w($L, $ind, 'echo ' . (implode(', ', $parts) ?: "''") . ';');
                return;
            }
            case 'Block':
                $this->w($L, $ind, '{');
                $this->emitStmts($s['stmts'], $L, $ind + 1);
                $this->w($L, $ind, '}');
                return;
        }
        // anything else (Cpp etc.) — run through the interpreter
        $this->w($L, $ind, 'pyhp_interp_exec($__pyhp_interp, $__pyhp_env, ' . var_export($s, true) . ');');
    }

    /* ================================================================ */
    /* expressions                                                       */
    /* ================================================================ */

    private function tmp(): string { return '$__t' . (++$this->tmpN); }

    private function emitExpr(array $e): string {
        switch ($e['k']) {
            case 'Num':
                return $e['v'] instanceof PyBigInt
                    ? 'pyhp_big(' . var_export($e['v']->dec, true) . ')'
                    : var_export($e['v'], true);
            case 'Str':
                return var_export($e['v'], true);
            case 'Bool':
                return $e['v'] ? 'true' : 'false';
            case 'Null':
                return 'null';
            case 'Yield':
                return 'yield' . ($e['e'] !== null ? ' (' . $this->emitExpr($e['e']) . ')' : '');
            case 'Var':
                return '$__pyhp_env->get(' . var_export($e['name'], true) . ')';
            case 'This':
                return '$__pyhp_env->get(\'this\')';
            case 'Super':
                return 'null';
            case 'List':
                return 'new PyList([' . implode(', ', array_map(fn($i) => $this->emitExpr($i), $e['items'])) . '])';
            case 'Dict': {
                $flat = [];
                foreach ($e['pairs'] as [$pk, $pv]) {
                    $flat[] = $this->emitExpr($pk);
                    $flat[] = $this->emitExpr($pv);
                }
                return 'new PyDict(pyhp_dict_pairs([' . implode(', ', $flat) . ']))';
            }
            case 'FStr': {
                $parts = [];
                foreach ($e['parts'] as $p) {
                    if ($p[0] === 's') { $parts[] = var_export($p[1], true); continue; }
                    $inner = $this->emitExpr($p[1]);
                    $parts[] = isset($p[2])
                        ? 'pyhp_format(' . $inner . ', ' . var_export($p[2], true) . ')'
                        : 'pyhp_tostr(' . $inner . ')';
                }
                return '(' . (implode(' . ', $parts) ?: "''") . ')';
            }
            case 'Un': {
                $v = $this->emitExpr($e['e']);
                return match ($e['op']) {
                    '-' => "pyhp_neg({$v})",
                    '!', 'not' => "pyhp_not({$v})",
                    '~' => "pyhp_bitnot({$v})",
                };
            }
            case 'Post': {
                $dir = $e['op'] === '++' ? '1' : '-1';
                return 'pyhp_env_post($__pyhp_env, ' . var_export($e['e']['name'], true) . ", {$dir})";
            }
            case 'Bin':
                return $this->emitBin($e);
            case 'Ternary':
                return '(pyhp_truthy(' . $this->emitExpr($e['c']) . ') ? ' . $this->emitExpr($e['a']) . ' : ' . $this->emitExpr($e['b']) . ')';
            case 'Idx':
                return 'pyhp_get(' . $this->emitExpr($e['obj']) . ', ' . $this->emitExpr($e['idx']) . ')';
            case 'Slice': {
                $s = $e['start'] === null ? 'null' : $this->emitExpr($e['start']);
                $t = $e['stop'] === null ? 'null' : $this->emitExpr($e['stop']);
                $st = $e['step'] === null ? 'null' : $this->emitExpr($e['step']);
                return 'pyhp_slice(' . $this->emitExpr($e['obj']) . ", {$s}, {$t}, {$st})";
            }
            case 'Call':
                if ($e['callee']['k'] === 'Var') {
                    $args = '[' . implode(', ', array_map(fn($a) => $this->emitExpr($a), $e['args'])) . ']';
                    $kws = '[' . implode(', ', array_map(fn($kw) =>
                        "['name' => " . var_export($kw['name'], true) . ", 'value' => " . $this->emitExpr($kw['expr']) . ']',
                        $e['kwargs'] ?? []) ?? []) . ']';
                    return 'pyhp_call_name($__pyhp_interp, ' . var_export($e['callee']['name'], true)
                        . ", {$args}, {$kws}, {$e['line']}, \$__pyhp_env)";
                }
                return 'pyhp_interp_eval($__pyhp_interp, $__pyhp_env, ' . var_export($e, true) . ')';
            case 'Func':
                return 'pyhp_env_def_func($__pyhp_interp, $__pyhp_env, ' . var_export($e, true) . ')';
        }
        // Member, Comp, Assign-in-expression, … — delegate to the interpreter
        return 'pyhp_interp_eval($__pyhp_interp, $__pyhp_env, ' . var_export($e, true) . ')';
    }

    private function emitBin(array $e): string {
        $op = $e['op'];
        if ($op === 'and' || $op === 'or') {
            $l = $this->emitExpr($e['l']);
            $r = $this->emitExpr($e['r']);
            $t = $this->tmp();
            if ($op === 'and') return "(({$t} = {$l}, pyhp_truthy({$t})) ? {$r} : {$t})";
            return "(({$t} = {$l}, pyhp_truthy({$t})) ? {$t} : {$r})";
        }
        if ($op === ',') {
            // comma expressions only appear in for-updates (handled there);
            // elsewhere run both sides through the interpreter
            return 'pyhp_interp_eval($__pyhp_interp, $__pyhp_env, ' . var_export($e, true) . ')';
        }
        $l = $this->emitExpr($e['l']);
        $r = $this->emitExpr($e['r']);
        return match ($op) {
            '+' => "pyhp_add({$l}, {$r})",
            '-' => "pyhp_sub({$l}, {$r})",
            '*' => "pyhp_mul({$l}, {$r})",
            '/' => "pyhp_div({$l}, {$r})",
            '%' => "pyhp_mod({$l}, {$r})",
            '**' => "pyhp_pow({$l}, {$r})",
            '&' => "pyhp_bitand({$l}, {$r})",
            '|' => "pyhp_bitor({$l}, {$r})",
            '^' => "pyhp_bitxor({$l}, {$r})",
            '<<' => "pyhp_lshift({$l}, {$r})",
            '>>' => "pyhp_rshift({$l}, {$r})",
            '==' => "pyhp_eq({$l}, {$r})",
            '!=' => "!pyhp_eq({$l}, {$r})",
            '<' => "(pyhp_compare({$l}, {$r}) < 0)",
            '<=' => "(pyhp_compare({$l}, {$r}) <= 0)",
            '>' => "(pyhp_compare({$l}, {$r}) > 0)",
            '>=' => "(pyhp_compare({$l}, {$r}) >= 0)",
            'in' => "pyhp_in({$l}, {$r})",
            'notin' => "!pyhp_in({$l}, {$r})",
            default => throw new PyHPSyntaxError("line {$e['line']}: unknown operator '{$op}' in generator body"),
        };
    }

    /* ---------------- assignment ---------------- */

    /** Flatten a for-update comma chain into single updates. */
    private function collectUpdates(array $e, array &$out): void {
        if ($e['k'] === 'Bin' && $e['op'] === ',') {
            $this->collectUpdates($e['l'], $out);
            $this->collectUpdates($e['r'], $out);
            return;
        }
        $out[] = $e;
    }

    /** One for-update part as an expression (no trailing semicolon). */
    private function updateCode(array $u): string {
        if ($u['k'] === 'Post') {
            $dir = $u['op'] === '++' ? '1' : '-1';
            return 'pyhp_env_post($__pyhp_env, ' . var_export($u['e']['name'], true) . ", {$dir})";
        }
        if ($u['k'] === 'Assign') {
            return rtrim($this->assignCode($u), ';');
        }
        return $this->emitExpr($u);
    }

    private function assignCode(array $e): string {
        $t = $e['target'];
        $op = $e['op'];
        if ($t['k'] === 'Var') {
            $name = var_export($t['name'], true);
            if ($op === '=') {
                return "\$__pyhp_env->assign({$name}, " . $this->emitExpr($e['value']) . ')';
            }
            $fn = $this->compoundFn($op);
            return "\$__pyhp_env->assign({$name}, {$fn}(\$__pyhp_env->get({$name}), " . $this->emitExpr($e['value']) . '))';
        }
        if ($t['k'] === 'Idx') {
            $obj = $this->emitExpr($t['obj']);
            $idx = $this->emitExpr($t['idx']);
            $v = $this->emitExpr($e['value']);
            if ($op === '=') {
                return "pyhp_set({$obj}, {$idx}, {$v})";
            }
            $fn = $this->compoundFn($op);
            return "pyhp_set({$obj}, {$idx}, {$fn}(pyhp_get({$obj}, {$idx}), {$v}))";
        }
        // Member targets (this.x = ..., obj.y = ...) — full interpreter semantics
        return 'pyhp_interp_eval($__pyhp_interp, $__pyhp_env, ' . var_export($e, true) . ')';
    }

    private function emitAssign(array $e, array &$L, int $ind): void {
        $this->w($L, $ind, $this->assignCode($e) . ';');
    }

    private function compoundFn(string $op): string {
        return match ($op) {
            '+=' => 'pyhp_add', '-=' => 'pyhp_sub', '*=' => 'pyhp_mul',
            '/=' => 'pyhp_div', '%=' => 'pyhp_mod', '**=' => 'pyhp_pow',
            '&=' => 'pyhp_bitand', '|=' => 'pyhp_bitor', '^=' => 'pyhp_bitxor',
            '<<=' => 'pyhp_lshift', '>>=' => 'pyhp_rshift',
            default => throw new PyHPSyntaxError("unknown compound operator '{$op}'"),
        };
    }
}
