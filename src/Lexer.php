<?php
/**
 * PyHP lexer — turns .pyh source text into a token stream.
 *
 * Token shape: ['t' => TYPE, 'v' => value, 'line' => int]
 * Types: INT FLOAT STR FSTR IDENT KW OP CPP EOF
 * FSTR value = ['parts' => [['s', string], ['e', rawExprString], ...]]
 * CPP  value = raw C++ source between the braces of `cpp { ... }`
 */

require_once __DIR__ . '/runtime.php';

class PyHPLexer
{
    private string $src;
    private int $i = 0;
    private int $line = 1;
    private int $n;

    public const KEYWORDS = [
        'function', 'lambda', 'return', 'if', 'elif', 'elseif', 'else', 'while', 'do',
        'for', 'foreach', 'in', 'not', 'and', 'or', 'break', 'continue',
        'try', 'catch', 'finally', 'throw', 'class', 'extends',
        'this', 'super', 'parent', 'echo', 'true', 'false', 'null', 'var',
        'int', 'float', 'string', 'bool', 'list', 'dict', 'yield',
    ];

    public const TYPE_KWS = ['int', 'float', 'string', 'bool', 'list', 'dict', 'var'];

    /** Operators, longest first so greedy matching works. */
    private const OPS = [
        '**=', '<<=', '>>=', '...',
        '==', '!=', '<=', '>=', '&&', '||', '<<', '>>', '++', '--',
        '+=', '-=', '*=', '/=', '%=', '&=', '|=', '^=', '=>', '->', '**',
        '+', '-', '*', '/', '%', '<', '>', '=', '&', '|', '^', '!', '~',
        '?', ':', ';', ',', '.', '(', ')', '{', '}', '[', ']',
    ];

    public function __construct(string $src) {
        $this->src = $src;
        $this->n = strlen($src);
    }

    public function tokenize(): array {
        $tokens = [];
        while ($this->i < $this->n) {
            $c = $this->src[$this->i];
            if ($c === ' ' || $c === "\t" || $c === "\r") { $this->i++; continue; }
            if ($c === "\n") { $this->line++; $this->i++; continue; }
            if ($c === '/' && $this->peek(1) === '/') { $this->skipLineComment(); continue; }
            if ($c === '#') { $this->skipLineComment(); continue; }
            if ($c === '/' && $this->peek(1) === '*') { $this->skipBlockComment(); continue; }
            if ($c === '"' || $c === "'") { $tokens[] = $this->lexString($c, false); continue; }
            if (($c === 'f' || $c === 'F') && ($this->peek(1) === '"' || $this->peek(1) === "'")) {
                $this->i++; // consume f
                $tokens[] = $this->lexString($this->src[$this->i], true);
                continue;
            }
            if (ctype_digit($c)) { $tokens[] = $this->lexNumber(); continue; }
            if ($c === '_' || ctype_alpha($c)) { $tokens[] = $this->lexIdent(); continue; }
            $op = $this->lexOp();
            if ($op === null) {
                throw new PyHPSyntaxError("line {$this->line}: unexpected character '" . $c . "'");
            }
            $tokens[] = ['t' => 'OP', 'v' => $op, 'line' => $this->line];
        }
        $tokens[] = ['t' => 'EOF', 'v' => null, 'line' => $this->line];
        return $tokens;
    }

    private function peek(int $off): ?string {
        $j = $this->i + $off;
        return $j < $this->n ? $this->src[$j] : null;
    }

    private function skipLineComment(): void {
        while ($this->i < $this->n && $this->src[$this->i] !== "\n") { $this->i++; }
    }

    private function skipBlockComment(): void {
        $startLine = $this->line;
        $this->i += 2;
        while ($this->i < $this->n && !($this->src[$this->i] === '*' && $this->peek(1) === '/')) {
            if ($this->src[$this->i] === "\n") $this->line++;
            $this->i++;
        }
        if ($this->i >= $this->n) {
            throw new PyHPSyntaxError("line {$startLine}: unterminated block comment");
        }
        $this->i += 2;
    }

    private function lexIdent(): array {
        $start = $this->i;
        $line = $this->line;
        while ($this->i < $this->n && ($this->src[$this->i] === '_' || ctype_alnum($this->src[$this->i]))) { $this->i++; }
        $word = substr($this->src, $start, $this->i - $start);
        // `cpp { ... }` — a raw C++ native block. Contextual: the identifier
        // `cpp` followed (after whitespace) by '{' opens the block.
        if ($word === 'cpp') {
            $save = $this->i;
            $savedLine = $this->line;
            while ($this->i < $this->n && ($this->src[$this->i] === ' ' || $this->src[$this->i] === "\t" || $this->src[$this->i] === "\r" || $this->src[$this->i] === "\n")) {
                if ($this->src[$this->i] === "\n") $this->line++;
                $this->i++;
            }
            if ($this->i < $this->n && $this->src[$this->i] === '{') {
                $code = $this->lexRawBraces();
                return ['t' => 'CPP', 'v' => $code, 'line' => $line];
            }
            $this->i = $save;
            $this->line = $savedLine;
        }
        if (in_array($word, self::KEYWORDS, true)) {
            return ['t' => 'KW', 'v' => $word, 'line' => $line];
        }
        return ['t' => 'IDENT', 'v' => $word, 'line' => $line];
    }

    /** At '{': consume a balanced {...} region and return the raw text inside,
     *  honouring C++ strings, char literals and comments so braces inside them
     *  never confuse the counter. */
    private function lexRawBraces(): string {
        $openLine = $this->line;
        $this->i++; // consume '{'
        $start = $this->i;
        $depth = 1;
        while ($this->i < $this->n) {
            $c = $this->src[$this->i];
            if ($c === '/' && $this->peek(1) === '/') {
                while ($this->i < $this->n && $this->src[$this->i] !== "\n") $this->i++;
                continue;
            }
            if ($c === '/' && $this->peek(1) === '*') {
                $this->i += 2;
                while ($this->i < $this->n && !($this->src[$this->i] === '*' && $this->peek(1) === '/')) {
                    if ($this->src[$this->i] === "\n") $this->line++;
                    $this->i++;
                }
                if ($this->i >= $this->n) break;
                $this->i += 2;
                continue;
            }
            if ($c === '"' || $c === "'") {
                $q = $c;
                $this->i++;
                while ($this->i < $this->n) {
                    if ($this->src[$this->i] === "\\") { $this->i += 2; continue; }
                    if ($this->src[$this->i] === "\n") $this->line++;
                    if ($this->src[$this->i] === $q) { $this->i++; break; }
                    $this->i++;
                }
                continue;
            }
            if ($c === '{') { $depth++; $this->i++; continue; }
            if ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    $code = substr($this->src, $start, $this->i - $start);
                    $this->i++; // consume closing '}'
                    return $code;
                }
                $this->i++;
                continue;
            }
            if ($c === "\n") $this->line++;
            $this->i++;
        }
        throw new PyHPSyntaxError("line {$openLine}: unterminated cpp { ... } block");
    }

    private function lexNumber(): array {
        $start = $this->i;
        $line = $this->line;
        // 0x / 0b / 0o prefixed literals
        if ($this->src[$this->i] === '0' && $this->peek(1) !== null && in_array(strtolower($this->peek(1)), ['x', 'b', 'o'], true)) {
            $base = strtolower($this->peek(1));
            $this->i += 2;
            $ds = $this->i;
            while ($this->i < $this->n && ctype_alnum($this->src[$this->i])) { $this->i++; }
            $digits = substr($this->src, $ds, $this->i - $ds);
            if ($digits === '') {
                throw new PyHPSyntaxError("line {$line}: malformed number literal");
            }
            if (ctype_alnum($this->src[$this->i - 1]) === false) {
                throw new PyHPSyntaxError("line {$line}: malformed number literal");
            }
            if ($this->i < $this->n && ($this->src[$this->i] === '_' || ctype_alpha($this->src[$this->i]))) {
                throw new PyHPSyntaxError("line {$line}: malformed number literal");
            }
            $v = intval($digits, ['x' => 16, 'b' => 2, 'o' => 8][$base]);
            // out-of-64-bit literals stay exact via big-int (like Python)
            if (strlen($digits) >= 16) {
                $dec = PyBigMath::fromBase($digits, ['x' => 16, 'b' => 2, 'o' => 8][$base]);
                if (!pyhp_big_fits_int($dec)) {
                    return ['t' => 'INT', 'v' => new PyBigInt($dec), 'line' => $line];
                }
                $v = (int)$dec;
            }
            return ['t' => 'INT', 'v' => $v, 'line' => $line];
        }
        $isFloat = false;
        while ($this->i < $this->n && ctype_digit($this->src[$this->i])) { $this->i++; }
        if ($this->i < $this->n && $this->src[$this->i] === '.' && $this->peek(1) !== null && ctype_digit($this->peek(1))) {
            $isFloat = true;
            $this->i++;
            while ($this->i < $this->n && ctype_digit($this->src[$this->i])) { $this->i++; }
        }
        if ($this->i < $this->n && ($this->src[$this->i] === 'e' || $this->src[$this->i] === 'E')) {
            $save = $this->i;
            $this->i++;
            if ($this->i < $this->n && ($this->src[$this->i] === '+' || $this->src[$this->i] === '-')) { $this->i++; }
            if ($this->i < $this->n && ctype_digit($this->src[$this->i])) {
                $isFloat = true;
                while ($this->i < $this->n && ctype_digit($this->src[$this->i])) { $this->i++; }
            } else {
                $this->i = $save; // not an exponent, back off
            }
        }
        if ($this->i < $this->n && ($this->src[$this->i] === '_' || ctype_alpha($this->src[$this->i]))) {
            throw new PyHPSyntaxError("line {$line}: malformed number literal");
        }
        $text = substr($this->src, $start, $this->i - $start);
        if ($isFloat) {
            return ['t' => 'FLOAT', 'v' => (float)$text, 'line' => $line];
        }
        // out-of-64-bit literals stay exact via big-int (like Python)
        if (strlen($text) > 19 || (strlen($text) === 19 && strcmp($text, '9223372036854775807') > 0)) {
            return ['t' => 'INT', 'v' => new PyBigInt($text), 'line' => $line];
        }
        return ['t' => 'INT', 'v' => (int)$text, 'line' => $line];
    }

    private function lexOp(): ?string {
        foreach (self::OPS as $op) {
            $len = strlen($op);
            if (substr($this->src, $this->i, $len) === $op) {
                $this->i += $len;
                return $op;
            }
        }
        return null;
    }

    private function lexString(string $quote, bool $isF): array {
        $line = $this->line;
        $this->i++; // opening quote
        $buf = '';
        $parts = [];
        $flush = function () use (&$buf, &$parts) {
            if ($buf !== '') { $parts[] = ['s', $buf]; $buf = ''; }
        };
        while (true) {
            if ($this->i >= $this->n) {
                throw new PyHPSyntaxError("line {$line}: unterminated string literal");
            }
            $c = $this->src[$this->i];
            if ($c === $quote) {
                $this->i++;
                if ($isF) { $flush(); return ['t' => 'FSTR', 'v' => ['parts' => $parts], 'line' => $line]; }
                return ['t' => 'STR', 'v' => $buf, 'line' => $line];
            }
            if ($c === "\\") {
                $this->i++;
                if ($this->i >= $this->n) {
                    throw new PyHPSyntaxError("line {$line}: unterminated string literal");
                }
                $e = $this->src[$this->i];
                $buf .= match ($e) {
                    'n' => "\n", 't' => "\t", 'r' => "\r", '0' => "\0",
                    '\\' => '\\', "'" => "'", '"' => '"',
                    default => $e,
                };
                $this->i++;
                continue;
            }
            if ($c === "\n") { $this->line++; }
            if ($isF && $c === '{') {
                if ($this->peek(1) === '{') { $buf .= '{'; $this->i += 2; continue; }
                $flush();
                $this->i++; // consume {
                $raw = '';
                $depth = 1;
                $inStr = null;
                while (true) {
                    if ($this->i >= $this->n) {
                        throw new PyHPSyntaxError("line {$line}: unterminated '{' expression inside f-string");
                    }
                    $ch = $this->src[$this->i];
                    if ($inStr !== null) {
                        if ($ch === "\\") { $raw .= $ch . ($this->peek(1) ?? ''); $this->i += 2; continue; }
                        if ($ch === $inStr) { $inStr = null; $raw .= $ch; $this->i++; continue; }
                        if ($ch === "\n") $this->line++;
                        $raw .= $ch; $this->i++;
                        continue;
                    }
                    if ($ch === '"' || $ch === "'") { $inStr = $ch; $raw .= $ch; $this->i++; continue; }
                    if ($ch === '{') { $depth++; $raw .= $ch; $this->i++; continue; }
                    if ($ch === '}') {
                        $depth--;
                        if ($depth === 0) { $this->i++; break; }
                        $raw .= $ch; $this->i++;
                        continue;
                    }
                    if ($ch === "\n") $this->line++;
                    $raw .= $ch; $this->i++;
                }
                if (trim($raw) === '') {
                    throw new PyHPSyntaxError("line {$line}: empty '{' expression inside f-string");
                }
                $parts[] = ['e', trim($raw)];
                continue;
            }
            if ($isF && $c === '}' && $this->peek(1) === '}') { $buf .= '}'; $this->i += 2; continue; }
            $buf .= $c;
            $this->i++;
        }
    }
}
