<?php
/**
 * PyHP parser — recursive descent, token stream -> AST.
 *
 * AST nodes are plain arrays with a 'k' (kind) key and a 'line'.
 * Expressions: Num Str FStr Bool Null Var This Super List Dict Comp Bin Un Post
 *              Ternary Call Idx Slice Member Assign Func
 * Statements:  Expr VarDecl Func Class If While DoWhile For Foreach Try Throw
 *              Return Break Continue Echo Block Cpp
 */

require_once __DIR__ . '/Lexer.php';

class PyHPParser
{
    private array $toks;
    private int $pos = 0;
    private int $loopDepth = 0;
    private int $funcDepth = 0;
    private int $classDepth = 0;

    private const ASSIGN_OPS = ['=', '+=', '-=', '*=', '/=', '%=', '**=', '&=', '|=', '^=', '<<=', '>>='];
    private const TYPE_KWS = ['int', 'float', 'string', 'bool', 'list', 'dict', 'var'];

    public function __construct(array $tokens) { $this->toks = $tokens; }

    public static function parseSource(string $src): array {
        $toks = (new PyHPLexer($src))->tokenize();
        return (new PyHPParser($toks))->parseProgram();
    }

    public function parseProgram(): array {
        $stmts = [];
        while (!$this->at('EOF')) {
            $s = $this->parseStatement();
            if ($s !== null) $stmts[] = $s;
        }
        return $stmts;
    }

    /* ------------------------------------------------------------------ */
    /* token helpers                                                       */
    /* ------------------------------------------------------------------ */

    private function at(string $t, ?string $v = null): bool {
        $tok = $this->toks[$this->pos];
        if ($tok['t'] !== $t) return false;
        return $v === null || $tok['v'] === $v;
    }
    private function atOp(string $v): bool { return $this->at('OP', $v); }
    private function atKw(string $v): bool { return $this->at('KW', $v); }
    private function peekV(int $off = 1) {
        $j = $this->pos + $off;
        return $j < count($this->toks) ? $this->toks[$j]['v'] : null;
    }
    private function line(): int { return $this->toks[$this->pos]['line']; }
    private function adv(): array { return $this->toks[$this->pos++]; }
    private function expectOp(string $v): void {
        if (!$this->atOp($v)) $this->err("expected '{$v}'");
        $this->pos++;
    }
    private function expectKw(string $v): void {
        if (!$this->atKw($v)) $this->err("expected '{$v}'");
        $this->pos++;
    }
    private function expectIdent(): string {
        if (!$this->at('IDENT')) $this->err('expected an identifier');
        return $this->adv()['v'];
    }
    private function err(string $msg): void {
        $tok = $this->toks[$this->pos];
        $got = $tok['t'] === 'EOF' ? 'end of file' : "'" . (string)$tok['v'] . "'";
        throw new PyHPSyntaxError("line {$tok['line']}: {$msg} (found {$got})");
    }

    /* ------------------------------------------------------------------ */
    /* statements                                                          */
    /* ------------------------------------------------------------------ */

    public function parseStatement(): ?array {
        $line = $this->line();
        if ($this->atOp(';')) { $this->pos++; return null; }

        if ($this->atKw('function')) return $this->parseFuncDecl(true);
        if ($this->atKw('class')) return $this->parseClass();
        // cpp { ... } — raw C++ native block (the lexer hands us the raw source)
        if ($this->at('CPP')) {
            $tok = $this->adv();
            $exports = pyhp_cpp_parse_exports($tok['v']);
            foreach ($exports as $e) { pyhp_cpp_type_kind($e['ret']); } // validate early
            return ['k' => 'Cpp', 'code' => $tok['v'], 'exports' => $exports, 'line' => $line];
        }
        if ($this->atKw('if')) return $this->parseIf();
        if ($this->atKw('while')) return $this->parseWhile();
        if ($this->atKw('do')) return $this->parseDoWhile();
        if ($this->atKw('for')) return $this->parseFor();
        if ($this->atKw('foreach')) return $this->parseForeach();
        if ($this->atKw('try')) return $this->parseTry();
        if ($this->atKw('throw')) {
            $this->pos++;
            $e = $this->parseExpression();
            $this->expectOp(';');
            return ['k' => 'Throw', 'e' => $e, 'line' => $line];
        }
        if ($this->atKw('return')) {
            if ($this->funcDepth === 0) $this->err("'return' outside of a function");
            $this->pos++;
            $e = $this->atOp(';') || $this->atOp('}') ? null : $this->parseExpression();
            $this->expectOp(';');
            return ['k' => 'Return', 'e' => $e, 'line' => $line];
        }
        if ($this->atKw('break') || $this->atKw('continue')) {
            if ($this->loopDepth === 0) $this->err("'{$this->toks[$this->pos]['v']}' outside of a loop");
            $k = $this->adv()['v'];
            $this->expectOp(';');
            return ['k' => $k === 'break' ? 'Break' : 'Continue', 'line' => $line];
        }
        if ($this->atKw('echo')) {
            $this->pos++;
            $args = [];
            if (!$this->atOp(';')) {
                $args[] = $this->parseExpression();
                while ($this->atOp(',')) { $this->pos++; $args[] = $this->parseExpression(); }
            }
            $this->expectOp(';');
            return ['k' => 'Echo', 'args' => $args, 'line' => $line];
        }
        if ($this->atOp('{')) return $this->parseBlock();

        // typed / var declaration: `int x = 5;` / `var x;`
        if ($this->at('KW') && in_array($this->toks[$this->pos]['v'], self::TYPE_KWS, true)
            && $this->peekV(1) !== null && is_string($this->peekV(1)) && $this->toks[$this->pos + 1]['t'] === 'IDENT') {
            return $this->parseVarDecl();
        }
        if ($this->atKw('var') && $this->toks[$this->pos + 1]['t'] === 'IDENT') {
            return $this->parseVarDecl();
        }

        // expression or assignment statement
        $e = $this->parseExpression();
        if ($this->at('OP') && in_array($this->toks[$this->pos]['v'], self::ASSIGN_OPS, true)) {
            if (!in_array($e['k'], ['Var', 'Idx', 'Member'], true)) {
                $this->err('invalid assignment target');
            }
            $op = $this->adv()['v'];
            $rhs = $this->parseExpression();
            $this->expectOp(';');
            return ['k' => 'Expr', 'e' => ['k' => 'Assign', 'target' => $e, 'op' => $op, 'value' => $rhs, 'line' => $line], 'line' => $line];
        }
        $this->expectOp(';');
        return ['k' => 'Expr', 'e' => $e, 'line' => $line];
    }

    private function parseVarDecl(): array {
        $line = $this->line();
        $type = $this->adv()['v'];               // int/float/string/bool/list/dict/var
        $name = $this->expectIdent();
        $init = null;
        if ($this->atOp('=')) { $this->pos++; $init = $this->parseExpression(); }
        $this->expectOp(';');
        return ['k' => 'VarDecl', 'name' => $name, 'type' => $type, 'init' => $init, 'line' => $line];
    }

    /** Statement used inside `for (...)` headers — no semicolon consumed. */
    private function parseSimpleStmt(): ?array {
        $line = $this->line();
        if ($this->at('KW') && in_array($this->toks[$this->pos]['v'], self::TYPE_KWS, true)
            && $this->toks[$this->pos + 1]['t'] === 'IDENT') {
            $type = $this->adv()['v'];
            $name = $this->expectIdent();
            $init = null;
            if ($this->atOp('=')) { $this->pos++; $init = $this->parseExpression(); }
            return ['k' => 'VarDecl', 'name' => $name, 'type' => $type, 'init' => $init, 'line' => $line];
        }
        $e = $this->parseExpression();
        if ($this->at('OP') && in_array($this->toks[$this->pos]['v'], self::ASSIGN_OPS, true)) {
            if (!in_array($e['k'], ['Var', 'Idx', 'Member'], true)) $this->err('invalid assignment target');
            $op = $this->adv()['v'];
            $rhs = $this->parseExpression();
            return ['k' => 'Expr', 'e' => ['k' => 'Assign', 'target' => $e, 'op' => $op, 'value' => $rhs, 'line' => $line], 'line' => $line];
        }
        return ['k' => 'Expr', 'e' => $e, 'line' => $line];
    }

    private function parseBlock(): array {
        $line = $this->line();
        $this->expectOp('{');
        $stmts = [];
        while (!$this->atOp('}')) {
            if ($this->at('EOF')) $this->err("expected '}' to close block");
            $s = $this->parseStatement();
            if ($s !== null) $stmts[] = $s;
        }
        $this->pos++;
        return ['k' => 'Block', 'stmts' => $stmts, 'line' => $line];
    }

    private function parseParams(): array {
        $this->expectOp('(');
        $params = [];
        while (!$this->atOp(')')) {
            if ($this->atOp(',')) $this->pos++;
            if ($this->atOp(')')) break;
            $line = $this->line();
            $type = null;
            if ($this->at('KW') && in_array($this->toks[$this->pos]['v'], ['int', 'float', 'string', 'bool', 'list', 'dict'], true)) {
                $type = $this->adv()['v'];
            }
            $variadic = false;
            if ($this->atOp('...')) { $this->pos++; $variadic = true; }
            $name = $this->expectIdent();
            if ($variadic) {
                $params[] = ['name' => $name, 'type' => null, 'def' => null, 'variadic' => true];
            } else {
                $def = null;
                if ($this->atOp('=')) { $this->pos++; $def = $this->parseExpression(); }
                $params[] = ['name' => $name, 'type' => $type, 'def' => $def, 'variadic' => false];
            }
            if ($this->atOp(',')) { $this->pos++; }
        }
        $this->expectOp(')');
        return $params;
    }

    private function parseFuncDecl(bool $requireName): array {
        $line = $this->line();
        $this->expectKw('function');
        $name = null;
        if ($this->at('IDENT')) $name = $this->adv()['v'];
        elseif ($requireName) $this->err('function declaration needs a name');
        $params = $this->parseParams();
        $ret = null;
        if ($this->atOp('->')) {
            $this->pos++;
            if (!($this->at('KW') && in_array($this->toks[$this->pos]['v'], ['int', 'float', 'string', 'bool', 'list', 'dict'], true))) {
                $this->err('expected a return type after -> (int, float, string, bool, list or dict)');
            }
            $ret = $this->adv()['v'];
        }
        $this->funcDepth++;
        $body = $this->parseBlock();
        $this->funcDepth--;
        return ['k' => 'Func', 'name' => $name, 'params' => $params, 'ret' => $ret, 'body' => $body, 'line' => $line];
    }

    private function parseClass(): array {
        $line = $this->line();
        $this->expectKw('class');
        $name = $this->expectIdent();
        $ext = null;
        if ($this->atKw('extends')) { $this->pos++; $ext = $this->expectIdent(); }
        $this->expectOp('{');
        $members = [];
        while (!$this->atOp('}')) {
            if ($this->at('EOF')) $this->err("expected '}' to close class body");
            if ($this->atKw('function')) {
                $members[] = $this->parseFuncDecl(true);
                continue;
            }
            // property: [type] name [= expr] ;   or   name = expr ;
            $ml = $this->line();
            $type = null;
            if ($this->at('KW') && in_array($this->toks[$this->pos]['v'], self::TYPE_KWS, true)
                && $this->toks[$this->pos + 1]['t'] === 'IDENT') {
                $type = $this->adv()['v'];
            }
            if ($type === null && $this->at('KW')) $this->err('unexpected token in class body');
            $pname = $this->expectIdent();
            $init = null;
            if ($this->atOp('=')) { $this->pos++; $init = $this->parseExpression(); }
            $this->expectOp(';');
            $members[] = ['k' => 'VarDecl', 'name' => $pname, 'type' => $type, 'init' => $init, 'line' => $ml];
        }
        $this->pos++;
        return ['k' => 'Class', 'name' => $name, 'ext' => $ext, 'members' => $members, 'line' => $line];
    }

    private function parseIf(): array {
        $line = $this->line();
        $this->expectKw('if');
        $branches = [];
        $else = null;
        while (true) {
            $cond = $this->parseCond();
            $this->classDepth++;
            $body = $this->parseBlock();
            $this->classDepth--;
            $branches[] = [$cond, $body];
            if ($this->atKw('elseif') || $this->atKw('elif')) {
                $this->pos++;
                continue;
            }
            if ($this->atKw('else')) {
                $this->pos++;
                if ($this->atKw('if')) { $this->pos++; continue; } // `else if` == elseif
                $else = $this->parseBlock();
            }
            break;
        }
        return ['k' => 'If', 'branches' => $branches, 'else' => $else, 'line' => $line];
    }

    /** Condition after if/while — parentheses optional ('(' is plain grouping). */
    private function parseCond(): array {
        return $this->parseExpression();
    }

    private function parseWhile(): array {
        $line = $this->line();
        $this->expectKw('while');
        $cond = $this->parseCond();
        $this->loopDepth++;
        $body = $this->parseBlock();
        $this->loopDepth--;
        return ['k' => 'While', 'cond' => $cond, 'body' => $body, 'line' => $line];
    }

    private function parseDoWhile(): array {
        $line = $this->line();
        $this->expectKw('do');
        $this->loopDepth++;
        $body = $this->parseBlock();
        $this->loopDepth--;
        $this->expectKw('while');
        $cond = $this->parseCond();
        $this->expectOp(';');
        return ['k' => 'DoWhile', 'cond' => $cond, 'body' => $body, 'line' => $line];
    }

    private function parseFor(): array {
        $line = $this->line();
        $this->expectKw('for');
        $this->expectOp('(');
        $init = $this->atOp(';') ? null : $this->parseSimpleStmt();
        $this->expectOp(';');
        $cond = $this->atOp(';') ? null : $this->parseExpression();
        $this->expectOp(';');
        $update = null;
        if (!$this->atOp(')')) {
            $update = $this->parseForUpdatePart();
            while ($this->atOp(',')) {
                $this->pos++;
                $nxt = $this->parseForUpdatePart();
                $update = ['k' => 'Bin', 'op' => ',', 'l' => $update, 'r' => $nxt, 'line' => $line];
            }
        }
        $this->expectOp(')');
        $this->loopDepth++;
        $body = $this->parseBlock();
        $this->loopDepth--;
        return ['k' => 'For', 'init' => $init, 'cond' => $cond, 'update' => $update, 'body' => $body, 'line' => $line];
    }

    /** for-update part: expression or assignment (i++, i += 2, arr[k] = v). */
    private function parseForUpdatePart(): array {
        $line = $this->line();
        $e = $this->parseExpression();
        if ($this->at('OP') && in_array($this->toks[$this->pos]['v'], self::ASSIGN_OPS, true)) {
            if (!in_array($e['k'], ['Var', 'Idx', 'Member'], true)) $this->err('invalid assignment target');
            $op = $this->adv()['v'];
            $rhs = $this->parseExpression();
            return ['k' => 'Assign', 'target' => $e, 'op' => $op, 'value' => $rhs, 'line' => $line];
        }
        return $e;
    }

    private function parseForeach(): array {
        $line = $this->line();
        $this->expectKw('foreach');
        $paren = false;
        if ($this->atOp('(')) { $paren = true; $this->pos++; }
        $var1 = $this->expectIdent();
        $var2 = null;
        if ($this->atOp(',')) { $this->pos++; $var2 = $this->expectIdent(); }
        $this->expectKw('in');
        $iter = $this->parseExpression();
        if ($paren) $this->expectOp(')');
        $this->loopDepth++;
        $body = $this->parseBlock();
        $this->loopDepth--;
        return ['k' => 'Foreach', 'var1' => $var1, 'var2' => $var2, 'iter' => $iter, 'body' => $body, 'line' => $line];
    }

    private function parseTry(): array {
        $line = $this->line();
        $this->expectKw('try');
        $body = $this->parseBlock();
        $catches = [];
        while ($this->atKw('catch')) {
            $cl = $this->line();
            $this->pos++;
            $this->expectOp('(');
            $first = $this->expectIdent();
            $type = null;
            $var = $first;
            if ($this->at('IDENT')) { $type = $first; $var = $this->expectIdent(); }
            $this->expectOp(')');
            $cblock = $this->parseBlock();
            $catches[] = ['type' => $type, 'var' => $var, 'body' => $cblock, 'line' => $cl];
        }
        $finally = null;
        if ($this->atKw('finally')) {
            $this->pos++;
            $finally = $this->parseBlock();
        }
        if (count($catches) === 0 && $finally === null) {
            $this->err("'try' needs at least one 'catch' or a 'finally'");
        }
        return ['k' => 'Try', 'body' => $body, 'catches' => $catches, 'finally' => $finally, 'line' => $line];
    }

    /* ------------------------------------------------------------------ */
    /* expressions                                                         */
    /* ------------------------------------------------------------------ */

    public function parseExpression(): array { return $this->parseTernary(); }

    private function parseTernary(): array {
        $c = $this->parseOr();
        if ($this->atOp('?')) {
            $line = $this->line();
            $this->pos++;
            $a = $this->parseTernary();
            $this->expectOp(':');
            $b = $this->parseTernary();
            return ['k' => 'Ternary', 'c' => $c, 'a' => $a, 'b' => $b, 'line' => $line];
        }
        return $c;
    }

    private function binLoop(callable $next, array $ops, callable $mk): array {
        $e = $next();
        while (true) {
            $tok = $this->toks[$this->pos];
            // word operators (and/or) are KW tokens; symbolic ones are OP tokens
            $match = ($tok['t'] === 'OP' || $tok['t'] === 'KW') && in_array($tok['v'], $ops, true);
            if (!$match) break;
            $line = $tok['line'];
            $op = $this->adv()['v'];
            $r = $next();
            $e = $mk($op, $e, $r, $line);
        }
        return $e;
    }

    private function parseOr(): array {
        return $this->binLoop(
            fn() => $this->parseAnd(),
            ['||', 'or'],
            fn($op, $l, $r, $line) => ['k' => 'Bin', 'op' => 'or', 'l' => $l, 'r' => $r, 'line' => $line]
        );
    }
    private function parseAnd(): array {
        return $this->binLoop(
            fn() => $this->parseBitOr(),
            ['&&', 'and'],
            fn($op, $l, $r, $line) => ['k' => 'Bin', 'op' => 'and', 'l' => $l, 'r' => $r, 'line' => $line]
        );
    }
    private function parseBitOr(): array {
        return $this->binLoop(fn() => $this->parseBitXor(), ['|'],
            fn($op, $l, $r, $line) => ['k' => 'Bin', 'op' => '|', 'l' => $l, 'r' => $r, 'line' => $line]);
    }
    private function parseBitXor(): array {
        return $this->binLoop(fn() => $this->parseBitAnd(), ['^'],
            fn($op, $l, $r, $line) => ['k' => 'Bin', 'op' => '^', 'l' => $l, 'r' => $r, 'line' => $line]);
    }
    private function parseBitAnd(): array {
        return $this->binLoop(fn() => $this->parseEquality(), ['&'],
            fn($op, $l, $r, $line) => ['k' => 'Bin', 'op' => '&', 'l' => $l, 'r' => $r, 'line' => $line]);
    }
    private function parseEquality(): array {
        return $this->binLoop(fn() => $this->parseCompare(), ['==', '!='],
            fn($op, $l, $r, $line) => ['k' => 'Bin', 'op' => $op, 'l' => $l, 'r' => $r, 'line' => $line]);
    }
    private function parseCompare(): array {
        $e = $this->parseShift();
        while (true) {
            $line = $this->line();
            if ($this->at('OP') && in_array($this->toks[$this->pos]['v'], ['<', '<=', '>', '>='], true)) {
                $op = $this->adv()['v'];
                $r = $this->parseShift();
                $e = ['k' => 'Bin', 'op' => $op, 'l' => $e, 'r' => $r, 'line' => $line];
                continue;
            }
            if ($this->atKw('in')) {
                $this->pos++;
                $r = $this->parseShift();
                $e = ['k' => 'Bin', 'op' => 'in', 'l' => $e, 'r' => $r, 'line' => $line];
                continue;
            }
            if ($this->atKw('not') && $this->peekV() === 'in') {
                $this->pos += 2;
                $r = $this->parseShift();
                $e = ['k' => 'Bin', 'op' => 'notin', 'l' => $e, 'r' => $r, 'line' => $line];
                continue;
            }
            break;
        }
        return $e;
    }
    private function parseShift(): array {
        return $this->binLoop(fn() => $this->parseAdd(), ['<<', '>>'],
            fn($op, $l, $r, $line) => ['k' => 'Bin', 'op' => $op, 'l' => $l, 'r' => $r, 'line' => $line]);
    }
    private function parseAdd(): array {
        return $this->binLoop(fn() => $this->parseMul(), ['+', '-'],
            fn($op, $l, $r, $line) => ['k' => 'Bin', 'op' => $op, 'l' => $l, 'r' => $r, 'line' => $line]);
    }
    private function parseMul(): array {
        return $this->binLoop(fn() => $this->parseUnary(), ['*', '/', '%'],
            fn($op, $l, $r, $line) => ['k' => 'Bin', 'op' => $op, 'l' => $l, 'r' => $r, 'line' => $line]);
    }
    private function parseUnary(): array {
        $line = $this->line();
        if ($this->at('OP') && in_array($this->toks[$this->pos]['v'], ['-', '!', '~'], true)) {
            $op = $this->adv()['v'];
            $e = $this->parseUnary();
            return ['k' => 'Un', 'op' => $op, 'e' => $e, 'line' => $line];
        }
        if ($this->atKw('not')) {
            $this->pos++;
            $e = $this->parseUnary();
            return ['k' => 'Un', 'op' => 'not', 'e' => $e, 'line' => $line];
        }
        return $this->parsePower();
    }
    private function parsePower(): array {
        $base = $this->parsePostfix();
        if ($this->atOp('**')) {
            $line = $this->line();
            $this->pos++;
            $exp = $this->parseUnary(); // right-assoc; allows 2 ** -1
            return ['k' => 'Bin', 'op' => '**', 'l' => $base, 'r' => $exp, 'line' => $line];
        }
        return $base;
    }
    private function parsePostfix(): array {
        $e = $this->parsePrimary();
        while (true) {
            $line = $this->line();
            if ($this->atOp('(')) {
                $args = [];
                $kwargs = [];
                $kwSeen = [];
                $this->pos++;
                while (!$this->atOp(')')) {
                    if ($this->atOp(',')) $this->pos++;
                    if ($this->atOp(')')) break;
                    // keyword argument: IDENT '=' expr (a single '=', never '==')
                    $n1 = $this->toks[$this->pos + 1] ?? null;
                    if ($this->at('IDENT') && $n1 !== null && $n1['t'] === 'OP' && $n1['v'] === '=') {
                        $kwLine = $this->line();
                        $kwName = $this->adv()['v'];
                        $this->pos++; // '='
                        if (isset($kwSeen[$kwName])) {
                            $this->err("keyword argument '{$kwName}' repeated in call");
                        }
                        $kwSeen[$kwName] = true;
                        $kwargs[] = ['name' => $kwName, 'expr' => $this->parseExpression(), 'line' => $kwLine];
                    } else {
                        if (count($kwargs) > 0) {
                            $this->err('positional argument after keyword argument');
                        }
                        $args[] = $this->parseExpression();
                    }
                    if ($this->atOp(',')) $this->pos++;
                }
                $this->expectOp(')');
                $e = ['k' => 'Call', 'callee' => $e, 'args' => $args, 'kwargs' => $kwargs, 'line' => $line];
                continue;
            }
            if ($this->atOp('[')) {
                $this->pos++;
                $start = null; $stop = null; $step = null;
                if (!$this->atOp(':')) {
                    $start = $this->parseExpression();
                }
                if ($this->atOp(':')) {
                    $this->pos++;
                    if (!$this->atOp(':') && !$this->atOp(']')) { $stop = $this->parseExpression(); }
                    if ($this->atOp(':')) {
                        $this->pos++;
                        if (!$this->atOp(']')) { $step = $this->parseExpression(); }
                    }
                    $this->expectOp(']');
                    $e = ['k' => 'Slice', 'obj' => $e, 'start' => $start, 'stop' => $stop, 'step' => $step, 'line' => $line];
                    continue;
                }
                $this->expectOp(']');
                $e = ['k' => 'Idx', 'obj' => $e, 'idx' => $start, 'line' => $line];
                continue;
            }
            if ($this->atOp('.')) {
                $this->pos++;
                $name = $this->expectIdent();
                $e = ['k' => 'Member', 'obj' => $e, 'name' => $name, 'line' => $line];
                continue;
            }
            if ($this->atOp('++') || $this->atOp('--')) {
                if (!in_array($e['k'], ['Var'], true)) $this->err("'++'/'--' can only be applied to a variable");
                $op = $this->adv()['v'];
                $e = ['k' => 'Post', 'op' => $op, 'e' => $e, 'line' => $line];
                continue;
            }
            break;
        }
        return $e;
    }

    private function parsePrimary(): array {
        $tok = $this->toks[$this->pos];
        $line = $tok['line'];
        switch ($tok['t']) {
            case 'INT':  $this->pos++; return ['k' => 'Num', 'v' => $tok['v'], 'line' => $line];
            case 'FLOAT': $this->pos++; return ['k' => 'Num', 'v' => $tok['v'], 'line' => $line];
            case 'STR':  $this->pos++; return ['k' => 'Str', 'v' => $tok['v'], 'line' => $line];
            case 'FSTR': $this->pos++; return $this->buildFStr($tok['v']['parts'], $line);
            case 'IDENT': $this->pos++; return ['k' => 'Var', 'name' => $tok['v'], 'line' => $line];
            case 'OP':
                if ($tok['v'] === '(') {
                    $this->pos++;
                    $e = $this->parseExpression();
                    $this->expectOp(')');
                    return $e;
                }
                if ($tok['v'] === '[') return $this->parseListOrComp();
                if ($tok['v'] === '{') return $this->parseDictOrComp();
                $this->err("unexpected '{$tok['v']}' in expression");
            case 'KW':
                if ($tok['v'] === 'true') { $this->pos++; return ['k' => 'Bool', 'v' => true, 'line' => $line]; }
                if ($tok['v'] === 'false') { $this->pos++; return ['k' => 'Bool', 'v' => false, 'line' => $line]; }
                if ($tok['v'] === 'null') { $this->pos++; return ['k' => 'Null', 'line' => $line]; }
                if ($tok['v'] === 'this') { $this->pos++; return ['k' => 'This', 'line' => $line]; }
                if ($tok['v'] === 'super' || $tok['v'] === 'parent') { $this->pos++; return ['k' => 'Super', 'line' => $line]; }
                if ($tok['v'] === 'function') {
                    $f = $this->parseFuncDecl(false);
                    return ['k' => 'Func', 'name' => $f['name'], 'params' => $f['params'], 'ret' => $f['ret'], 'body' => $f['body'], 'line' => $line, 'expr' => true];
                }
                if ($tok['v'] === 'lambda') return $this->parseLambda();
                if ($tok['v'] === 'yield') {
                    if ($this->funcDepth === 0) {
                        $this->err("'yield' outside a function (yield turns a function into a lazy generator)");
                    }
                    $this->pos++;
                    // bare `yield` when the next token cannot start an expression
                    $canExpr = !($this->atOp(';') || $this->atOp('}') || $this->atOp(')')
                        || $this->atOp(']') || $this->atOp(',') || $this->atOp(':')
                        || $this->at('EOF'));
                    $e = $canExpr ? $this->parseTernary() : null;
                    return ['k' => 'Yield', 'e' => $e, 'line' => $line];
                }
                if ($tok['v'] === 'list' || $tok['v'] === 'dict') {
                    $this->pos++;
                    return ['k' => 'Var', 'name' => $tok['v'], 'line' => $line];
                }
                // int(x) / float(x) / bool(x) used as conversion calls
                if (in_array($tok['v'], ['int', 'float', 'string', 'bool'], true)
                    && $this->toks[$this->pos + 1]['t'] === 'OP' && $this->toks[$this->pos + 1]['v'] === '(') {
                    $this->pos++;
                    return ['k' => 'Var', 'name' => $tok['v'], 'line' => $line];
                }
                if (in_array($tok['v'], ['int', 'float', 'string', 'bool'], true)) {
                    $this->err("type '{$tok['v']}' cannot be used as a value");
                }
                if (in_array($tok['v'], ['var'], true)) $this->err("unexpected 'var' in expression");
                $this->err("unexpected keyword '{$tok['v']}' in expression");
        }
        $this->err('unexpected token in expression');
    }

    private function parseLambda(): array {
        $line = $this->line();
        $this->expectKw('lambda');
        $params = [];
        while (!$this->atOp(':')) {
            if ($this->atOp(',')) $this->pos++;
            if ($this->atOp(':')) break;
            $params[] = ['name' => $this->expectIdent(), 'type' => null, 'def' => null, 'variadic' => false];
            if ($this->atOp(',')) $this->pos++;
        }
        $this->expectOp(':');
        $this->funcDepth++;               // a lambda body is a function body (yield allowed)
        $e = $this->parseExpression();
        $this->funcDepth--;
        return [
            'k' => 'Func', 'name' => null, 'params' => $params, 'ret' => null,
            'body' => ['k' => 'Block', 'stmts' => [['k' => 'Return', 'e' => $e, 'line' => $line]], 'line' => $line],
            'line' => $line, 'expr' => true,
        ];
    }

    private function parseListOrComp(): array {
        $line = $this->line();
        $this->expectOp('[');
        if ($this->atOp(']')) { $this->pos++; return ['k' => 'List', 'items' => [], 'line' => $line]; }
        $first = $this->parseExpression();
        if ($this->atKw('for')) {
            $this->pos++;
            $var1 = $this->expectIdent();
            $var2 = null;
            if ($this->atOp(',')) { $this->pos++; $var2 = $this->expectIdent(); }
            $this->expectKw('in');
            $iter = $this->parseExpression();
            $cond = null;
            if ($this->atKw('if')) { $this->pos++; $cond = $this->parseExpression(); }
            $this->expectOp(']');
            return ['k' => 'Comp', 'isDict' => false, 'key' => null, 'val' => $first,
                    'var1' => $var1, 'var2' => $var2, 'iter' => $iter, 'cond' => $cond, 'line' => $line];
        }
        $items = [$first];
        while ($this->atOp(',')) {
            $this->pos++;
            if ($this->atOp(']')) break;
            $items[] = $this->parseExpression();
        }
        $this->expectOp(']');
        return ['k' => 'List', 'items' => $items, 'line' => $line];
    }

    private function parseDictOrComp(): array {
        $line = $this->line();
        $this->expectOp('{');
        if ($this->atOp('}')) { $this->pos++; return ['k' => 'Dict', 'pairs' => [], 'line' => $line]; }
        $k = $this->parseExpression();
        if (!$this->atOp(':') && !$this->atOp('=>')) $this->err("expected ':' between dict key and value");
        $this->pos++;
        $v = $this->parseExpression();
        if ($this->atKw('for')) {
            $this->pos++;
            $var1 = $this->expectIdent();
            $var2 = null;
            if ($this->atOp(',')) { $this->pos++; $var2 = $this->expectIdent(); }
            $this->expectKw('in');
            $iter = $this->parseExpression();
            $cond = null;
            if ($this->atKw('if')) { $this->pos++; $cond = $this->parseExpression(); }
            $this->expectOp('}');
            return ['k' => 'Comp', 'isDict' => true, 'key' => $k, 'val' => $v,
                    'var1' => $var1, 'var2' => $var2, 'iter' => $iter, 'cond' => $cond, 'line' => $line];
        }
        $pairs = [[$k, $v]];
        while ($this->atOp(',')) {
            $this->pos++;
            if ($this->atOp('}')) break;
            $pk = $this->parseExpression();
            if (!$this->atOp(':') && !$this->atOp('=>')) $this->err("expected ':' between dict key and value");
            $this->pos++;
            $pv = $this->parseExpression();
            $pairs[] = [$pk, $pv];
        }
        $this->expectOp('}');
        return ['k' => 'Dict', 'pairs' => $pairs, 'line' => $line];
    }

    private function buildFStr(array $parts, int $line): array {
        $out = [];
        foreach ($parts as $p) {
            if ($p[0] === 's') { $out[] = ['s', $p[1]]; continue; }
            [$exprRaw, $spec] = $this->splitFormatSpec($p[1], $line);
            $sub = (new PyHPLexer($exprRaw))->tokenize();
            $subParser = new PyHPParser($sub);
            $e = $subParser->parseExpression();
            if (!$subParser->at('EOF')) $subParser->err('unexpected token inside f-string expression');
            $out[] = $spec === null ? ['e', $e] : ['e', $e, $spec];
        }
        return ['k' => 'FStr', 'parts' => $out, 'line' => $line];
    }

    /** The f-string format spec grammar (shared with pyhp_format in runtime.php). */
    private const SPEC_RE = '/^(?:(?<fill>.)?(?<align>[<>^]))?(?<sign>[+\- ]?)(?<alt>#)?(?<zero>0)?(?<width>\d[\d,]*|,)?(?:\.(?<prec>\d+))?(?<type>[bcdeEfFgGnosxX%])?$/';

    /** Split '{expr:spec}' at the first top-level ':' — unless that colon
     *  closes a ternary '?' branch or heads a lambda body, which take the
     *  colon first. Slices and dict literals sit inside brackets, so their
     *  colons are never at the top level. */
    private function splitFormatSpec(string $raw, int $line): array {
        $n = strlen($raw); $depth = 0; $tern = 0; $lamHead = false; $inStr = null;
        for ($i = 0; $i < $n; $i++) {
            $c = $raw[$i];
            if ($inStr !== null) {
                if ($c === '\\') { $i++; continue; }
                if ($c === $inStr) $inStr = null;
                continue;
            }
            if ($c === '"' || $c === "'") { $inStr = $c; continue; }
            if ($c === '(' || $c === '[' || $c === '{') { $depth++; continue; }
            if ($c === ')' || $c === ']' || $c === '}') { if ($depth > 0) $depth--; continue; }
            if ($depth > 0) continue;
            if ($c === '?') { $tern++; continue; }
            if ($c === ':') {
                if ($tern > 0) { $tern--; continue; }         // closes a ternary branch
                if ($lamHead) { $lamHead = false; continue; } // lambda body colon
                $spec = trim(substr($raw, $i + 1));
                if (!preg_match(self::SPEC_RE, $spec)) {
                    throw new PyHPSyntaxError("line {$line}: invalid format spec '{$spec}'");
                }
                return [substr($raw, 0, $i), $spec];
            }
            if (ctype_alpha($c) || $c === '_') {
                $j = $i;
                while ($j < $n && (ctype_alnum($raw[$j]) || $raw[$j] === '_')) $j++;
                if (substr($raw, $i, $j - $i) === 'lambda') $lamHead = true;
                $i = $j - 1;
                continue;
            }
        }
        return [$raw, null];
    }
}
