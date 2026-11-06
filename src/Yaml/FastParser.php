<?php

namespace NineteenNinetyFour\Flatpack\Yaml;

use Symfony\Component\Yaml\Inline;

/**
 * A strict, line-based reader for the block-style YAML that Statamic itself writes.
 * Anything it is not 100% sure about makes it return null so the caller can
 * fall back to the full Symfony parser.
 */
final class FastParser
{
    private array $lines = [];

    private int $n = 0;

    private int $p = 0;

    public static function parse(string $str): ?array
    {
        if ($str === '' || strpbrk($str, "\t\r") !== false) {
            return null;
        }

        try {
            return (new self)->run($str);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function run(string $str): ?array
    {
        if (str_starts_with($str, "---\n")) {
            $str = substr($str, 4);
        }

        $this->lines = explode("\n", $str);
        $this->n = count($this->lines);
        $this->skipBlanks();

        if ($this->p >= $this->n) {
            return null;
        }

        $first = $this->lines[$this->p];
        if ($first[0] === ' ' || $first[0] === '-' || $first[0] === '#') {
            return null;
        }

        $result = $this->parseMap(0);
        $this->skipBlanks();

        return $this->p >= $this->n ? $result : null;
    }

    private function skipBlanks(): void
    {
        while ($this->p < $this->n && ltrim($this->lines[$this->p], ' ') === '') {
            $this->p++;
        }
    }

    private function fail(): never
    {
        throw new \RuntimeException('fallback');
    }

    private function parseMap(int $ind): array
    {
        $out = [];
        $lines = &$this->lines;

        while (true) {
            while ($this->p < $this->n && ltrim($lines[$this->p], ' ') === '') {
                $this->p++;
            }
            if ($this->p >= $this->n) {
                break;
            }

            $line = $lines[$this->p];
            $t = ltrim($line, ' ');
            $i = strlen($line) - strlen($t);

            if ($i < $ind) {
                break;
            }
            if ($i > $ind) {
                $this->fail();
            }

            if (! preg_match('/^([A-Za-z_][A-Za-z0-9_]*):(?: +(\S.*?))?\s*$/', $t, $m)) {
                $this->fail();
            }

            $key = $m[1];
            if (isset($out[$key]) || array_key_exists($key, $out)) {
                $this->fail();
            }
            if (strlen($key) <= 5) {
                $lk = strtolower($key);
                if ($lk === 'null' || $lk === 'true' || $lk === 'false') {
                    $this->fail();
                }
            }

            $v = $m[2] ?? '';
            $this->p++;

            if ($v === '') {
                $out[$key] = $this->child($ind, true);
            } elseif ($v[0] === '|') {
                $out[$key] = $this->literal($v, $ind);
            } else {
                $out[$key] = $this->scalar($v);
            }
        }

        return $out;
    }

    private function parseSeq(int $ind): array
    {
        $out = [];
        $lines = &$this->lines;

        while (true) {
            while ($this->p < $this->n && ltrim($lines[$this->p], ' ') === '') {
                $this->p++;
            }
            if ($this->p >= $this->n) {
                break;
            }

            $line = $lines[$this->p];
            $lt = ltrim($line, ' ');
            $i = strlen($line) - strlen($lt);
            $t = rtrim($lt);

            if ($i < $ind) {
                break;
            }
            if ($i > $ind) {
                $this->fail();
            }
            if ($t[0] !== '-') {
                break;
            }
            if (isset($t[1]) && $t[1] !== ' ') {
                $this->fail();
            }

            $rest = ltrim(substr($t, 1), ' ');

            if ($rest === '') {
                $this->p++;
                $out[] = $this->child($ind, false);
            } elseif (preg_match('/^[A-Za-z_][A-Za-z0-9_]*:(?: |$)/', $rest)) {
                $offset = $i + strlen($t) - strlen($rest);
                $lines[$this->p] = str_repeat(' ', $offset).$rest;
                $out[] = $this->parseMap($offset);
            } else {
                $this->p++;
                $out[] = $this->scalar($rest);
            }
        }

        return $out;
    }

    private function child(int $ind, bool $allowSameIndentSeq)
    {
        $lines = &$this->lines;
        $n = $this->n;
        $p = $this->p;
        while ($p < $n && ltrim($lines[$p], ' ') === '') {
            $p++;
        }
        $this->p = $p;

        if ($p >= $n) {
            return null;
        }

        $line = $lines[$p];
        $t = ltrim($line, ' ');
        $i = strlen($line) - strlen($t);

        if ($i > $ind) {
            return $t[0] === '-' ? $this->parseSeq($i) : $this->parseMap($i);
        }

        if ($allowSameIndentSeq && $i === $ind && $t[0] === '-' && (! isset($t[1]) || $t[1] === ' ' || $t[1] === "\n")) {
            return $this->parseSeq($ind);
        }

        return null;
    }

    private function literal(string $header, int $ind): string
    {
        if ($header !== '|' && $header !== '|-') {
            $this->fail();
        }

        $tmp = [];
        $blockInd = null;
        $j = $this->p;

        while ($j < $this->n) {
            $l = $this->lines[$j];
            if (ltrim($l, ' ') === '') {
                if ($l !== '') {
                    $this->fail();
                }
                $tmp[] = '';
                $j++;

                continue;
            }
            $i = strlen($l) - strlen(ltrim($l, ' '));
            if ($i <= $ind) {
                break;
            }
            if ($blockInd === null) {
                $blockInd = $i;
            } elseif ($i < $blockInd) {
                $this->fail();
            }
            $tmp[] = substr($l, $blockInd);
            $j++;
        }

        if ($blockInd === null || $tmp[0] === '') {
            $this->fail();
        }

        while ($tmp && end($tmp) === '') {
            array_pop($tmp);
        }

        // Only fast-path a block that is followed by another key or the end of the document.
        $this->p = $j;
        $text = implode("\n", $tmp);

        if ($header === '|') {
            if ($j >= $this->n) {
                $this->fail();
            }
            $text .= "\n";
        }

        return $text;
    }

    private function scalar(string $v)
    {
        $c = $v[0];

        if (strpos($v, ' #') !== false) {
            $this->fail();
        }

        if ($c === "'") {
            $len = strlen($v);
            if ($len > 1 && $v[$len - 1] === "'") {
                $in = substr($v, 1, -1);
                if (strpos($in, "'") === false) {
                    return $in;
                }
                if (strpos(str_replace("''", '', $in), "'") === false) {
                    return str_replace("''", "'", $in);
                }
            }
            $this->fail();
        }

        if ($c === '"') {
            $len = strlen($v);
            if ($len > 1 && $v[$len - 1] === '"') {
                $in = substr($v, 1, -1);
                if (strpos($in, '\\') === false && strpos($in, '"') === false) {
                    return $in;
                }
            }
            $this->fail();
        }

        if ($c === '[') {
            $v === '[]' ? null : $this->fail();

            return [];
        }

        if ($c === '{') {
            preg_match('/^\{ *\}$/', $v) ? null : $this->fail();

            return [];
        }

        if (preg_match('/: |:$/', $v)) {
            $this->fail();
        }

        if (($c >= 'a' && $c <= 'z') || ($c >= 'A' && $c <= 'Z') || $c === '_' || $c === '/' || $c === '(' || $c === '$' || $c === '=' || $c === ';' || $c === '^' || $c === '<' || $c >= "\x80") {
            if (strlen($v) <= 5) {
                switch (strtolower($v)) {
                    case 'true': return true;
                    case 'false': return false;
                    case 'null': return null;
                }
            }

            return $v;
        }

        if ($c >= '0' && $c <= '9') {
            $len = strlen($v);
            if ($len === 36 && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $v)) {
                return $v;
            }
            if ($len === 8 && ! ctype_digit($v) && preg_match('/^[0-9a-f]{8}$/', $v) && ! preg_match('/^[0-9]+e[0-9]+$/', $v)) {
                return $v;
            }
        }

        if ($c === '-' || $c === '+' || $c === '.' || ($c >= '0' && $c <= '9')) {
            return Inline::parse($v);
        }

        $this->fail();
    }
}
