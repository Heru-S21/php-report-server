<?php

namespace ReportingEngine\Renderer;

class ExpressionEvaluator
{
    private string $input;
    private int $pos;
    private int $len;
    private array $data;

    public function __construct(string $expression, array $data)
    {
        $this->input = $expression;
        $this->pos = 0;
        $this->len = strlen($expression);
        $this->data = $data;
    }

    public static function evaluate(string $expression, array $data): string
    {
        $parser = new self($expression, $data);
        $result = $parser->parseExpression();
        return $parser->toString($result);
    }

    public static function evaluateBool(string $expression, array $data): bool
    {
        $parser = new self($expression, $data);
        $result = $parser->parseExpression();
        return $parser->toBool($result);
    }

    private function parseExpression(): mixed
    {
        return $this->parseTernary();
    }

    private function parseTernary(): mixed
    {
        $cond = $this->parseComparison();
        $this->skipWhitespace();
        if ($this->match('?')) {
            $trueVal = $this->parseTernary();
            $this->skipWhitespace();
            $this->expect(':');
            $falseVal = $this->parseTernary();
            return $this->toBool($cond) ? $trueVal : $falseVal;
        }
        return $cond;
    }

    private function parseComparison(): mixed
    {
        $left = $this->parseAddition();
        $this->skipWhitespace();
        if ($this->match('>=')) {
            $right = $this->parseAddition();
            return $this->compare($left, $right) >= 0;
        }
        if ($this->match('<=')) {
            $right = $this->parseAddition();
            return $this->compare($left, $right) <= 0;
        }
        if ($this->match('>')) {
            $right = $this->parseAddition();
            return $this->compare($left, $right) > 0;
        }
        if ($this->match('<')) {
            $right = $this->parseAddition();
            return $this->compare($left, $right) < 0;
        }
        if ($this->match('==')) {
            $right = $this->parseAddition();
            return $this->compare($left, $right) === 0;
        }
        if ($this->match('!=')) {
            $right = $this->parseAddition();
            return $this->compare($left, $right) !== 0;
        }
        return $left;
    }

    private function parseAddition(): mixed
    {
        $left = $this->parseTerm();
        $this->skipWhitespace();
        while (true) {
            if ($this->match('+')) {
                $right = $this->parseTerm();
                $left = $this->toNumber($left) + $this->toNumber($right);
            } elseif ($this->match('-')) {
                $right = $this->parseTerm();
                $left = $this->toNumber($left) - $this->toNumber($right);
            } else {
                break;
            }
            $this->skipWhitespace();
        }
        return $left;
    }

    private function parseTerm(): mixed
    {
        $left = $this->parseUnary();
        $this->skipWhitespace();
        while (true) {
            if ($this->match('*')) {
                $right = $this->parseUnary();
                $left = $this->toNumber($left) * $this->toNumber($right);
            } elseif ($this->match('/')) {
                $right = $this->parseUnary();
                $denom = $this->toNumber($right);
                if ($denom == 0) $left = 0;
                else $left = $this->toNumber($left) / $denom;
            } else {
                break;
            }
            $this->skipWhitespace();
        }
        return $left;
    }

    private function parseUnary(): mixed
    {
        $this->skipWhitespace();
        if ($this->match('-')) {
            $val = $this->parseUnary();
            return -$this->toNumber($val);
        }
        if ($this->match('!')) {
            $val = $this->parseUnary();
            return !$this->toBool($val);
        }
        return $this->parsePrimary();
    }

    private function parsePrimary(): mixed
    {
        $this->skipWhitespace();
        if ($this->match('(')) {
            $val = $this->parseExpression();
            $this->skipWhitespace();
            $this->expect(')');
            return $val;
        }
        if ($this->peek() === '[') {
            return $this->parseFieldRef();
        }
        $ch = $this->peek();
        if ($ch === '"' || $ch === "'") {
            return $this->parseString();
        }
        if ($ch === '-' || $ch === '.' || ctype_digit($ch)) {
            return $this->parseNumber();
        }
        // Logical operators as words
        $word = $this->parseWord();
        $lower = strtolower($word);
        if ($lower === 'true') return true;
        if ($lower === 'false') return false;
        if ($lower === 'null') return null;
        // Return the word as a string literal fallback
        return $word;
    }

    private function parseFieldRef(): mixed
    {
        $this->expect('[');
        $name = '';
        while ($this->pos < $this->len && $this->peek() !== ']') {
            $name .= $this->advance();
        }
        $this->expect(']');
        return $this->data[$name] ?? '';
    }

    private function parseString(): string
    {
        $quote = $this->advance();
        $val = '';
        while ($this->pos < $this->len && $this->peek() !== $quote) {
            if ($this->peek() === '\\') {
                $this->advance();
                $val .= $this->advance();
            } else {
                $val .= $this->advance();
            }
        }
        $this->expect($quote);
        return $val;
    }

    private function parseNumber(): float|int
    {
        $num = '';
        if ($this->peek() === '-') {
            $num .= $this->advance();
        }
        while ($this->pos < $this->len && ctype_digit($this->peek())) {
            $num .= $this->advance();
        }
        $isFloat = false;
        if ($this->peek() === '.') {
            $isFloat = true;
            $num .= $this->advance();
            while ($this->pos < $this->len && ctype_digit($this->peek())) {
                $num .= $this->advance();
            }
        }
        return $isFloat ? (float)$num : (int)$num;
    }

    private function parseWord(): string
    {
        $w = '';
        while ($this->pos < $this->len && (ctype_alnum($this->peek()) || $this->peek() === '_')) {
            $w .= $this->advance();
        }
        return $w;
    }

    private function compare(mixed $a, mixed $b): int
    {
        if (is_numeric($a) && is_numeric($b)) {
            $na = (float)$a;
            $nb = (float)$b;
            return $na <=> $nb;
        }
        return (string)$a <=> (string)$b;
    }

    private function toNumber(mixed $val): float
    {
        if (is_bool($val)) return $val ? 1.0 : 0.0;
        if (is_null($val)) return 0.0;
        if (is_numeric($val)) return (float)$val;
        return 0.0;
    }

    private function toBool(mixed $val): bool
    {
        if (is_bool($val)) return $val;
        if (is_null($val)) return false;
        if (is_numeric($val)) return $val != 0;
        $s = strtolower((string)$val);
        if ($s === 'true' || $s === 'yes') return true;
        if ($s === 'false' || $s === 'no') return false;
        return $val !== '';
    }

    private function toString(mixed $val): string
    {
        if (is_bool($val)) return $val ? 'true' : 'false';
        if (is_null($val)) return '';
        if (is_float($val)) {
            return $val == (int)$val ? (string)(int)$val : (string)$val;
        }
        return (string)$val;
    }

    private function peek(): string
    {
        return $this->pos < $this->len ? $this->input[$this->pos] : "\0";
    }

    private function advance(): string
    {
        return $this->pos < $this->len ? $this->input[$this->pos++] : "\0";
    }

    private function match(string $s): bool
    {
        $len = strlen($s);
        if (substr($this->input, $this->pos, $len) === $s) {
            $this->pos += $len;
            return true;
        }
        return false;
    }

    private function expect(string $s): void
    {
        if (!$this->match($s)) {
            throw new \RuntimeException("Expected '{$s}' at position {$this->pos} in expression '{$this->input}'");
        }
    }

    private function skipWhitespace(): void
    {
        while ($this->pos < $this->len && ctype_space($this->input[$this->pos])) {
            $this->pos++;
        }
    }
}
