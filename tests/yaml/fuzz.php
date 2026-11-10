<?php

// Checks the fast YAML parser against Symfony's: whenever the fast parser returns a result, it
// must be exactly what Symfony returns, and it must return nothing where Symfony throws.
// Documents are random data written by Symfony's dumper in several styles, and mutated copies
// of them and of the samples below.
//
//   php tests/yaml/fuzz.php <dir with vendor/autoload.php> [cases=100000] [seed=1]

require $argv[1].'/vendor/autoload.php';
require_once __DIR__.'/../../src/Yaml/CannotFastParse.php';
require_once __DIR__.'/../../src/Yaml/FastParser.php';

use NineteenNinetyFour\Flatpack\Yaml\FastParser;
use Symfony\Component\Yaml\Parser;
use Symfony\Component\Yaml\Yaml;

$count = (int) ($argv[2] ?? 100000);
mt_srand((int) ($argv[3] ?? 1));

$sym = new Parser;
$fast = fn ($s) => FastParser::parse($s);

$corpus = [
    "---\nid: 7817ea53-6aad-4d73-a426-441769699060\nblueprint: page\ntitle: 'A page'\npublished: true\nupdated_at: 1700000000\ntags:\n  - one\n  - two\nseo:\n  title: null\n  noindex: false\n",
    "---\ntitle: \"Line one\\nLine two\"\nblocks:\n  -\n    id: 4b2a7e2d\n    type: text\n    enabled: true\n    text: |\n      First line\n\n      Third line\n    rows:\n      -\n        label: Reef\n        value: 'sage copper'\n  -\n    type: image\n    image:\n      - a.jpg\nintro: |-\n  <script>\n  \ttabbed();\n  </script>\nlast: 1",
    "title: Main\nmax_depth: 2\ncollections:\n- pages\n- articles\nsettings: {  }\nlist: []\n1_simple_content: x\n'New Zealand': 'New Zealand'\n",
    "content:\n  -\n    type: paragraph\n    attrs:\n      textAlign: left\n    content:\n      -\n        type: text\n        marks:\n          -\n            type: link\n            attrs:\n              href: 'https://example.com'\n              rel: null\n        text: 'a link'\n",
    "sections:\n  main:\n    display: Main\n    fields:\n      - handle: title\n        field:\n          type: text\n          validate: [required, 'max:120']\n      - handle: body\n        field: { type: markdown }\n",
];

$words = ['title', 'type', 'text', 'id', 'enabled', 'content', 'null', 'true', 'false', 'Null', 'TRUE', '~', 'yes', 'no', 'on', 'off', 'a', 'x_1', 'foo-bar', '1_simple', '<<', 'key with space', '0', '-', '--', '---', '...', '=', '!', '&a', '*a', '?', '%', '@', '`'];
$scalars = ['', ' ', 'hello', 'Hello World', 'it\'s', 'say "hi"', 'a: b', 'a:b', 'a :b', 'trail:', ':lead', 'x # y', 'x #y', 'x# y', '#hash', '# hash', "line1\nline2", "line1\n\nline3\n", '  lead', 'trail  ', "tab\there", 'back\\slash', 'ünï©ödé', '日本語', "\u{00A0}nbsp", '123', '0123', '0', '-5', '+5', '1.5', '.5', '1e5', '1234e567', '0x1F', '0o17', '0b11', '1_000', '1_0.5', '.inf', '-.inf', '.NaN', '2024-01-15', '2024-01-15 10:00:00', '2024-01-15T10:00:00Z', '12:30', '12345678-1234-1234-1234-123456789abc', '7817ea53-6aad-4d73-a426-441769699060', 'deadbeef', '12345678', '9223372036854775807', '9223372036854775808', '99999999999999999999', 'null', 'Null', 'NULL', '~', 'true', 'True', 'false', 'FALSE', 'yes', 'no', '[]', '{}', '[a, b]', '{a: 1}', '{ }', '[', ']', '{', '}', ',', '|', '>', '|-', '- item', '-', '-x', '--', '---', '...', '!tag', '!!str x', '&anchor', '*alias', '?', '? x', '@at', '`tick', '%pct', '<p>html</p>', '<<', '=', '$var', '(paren)', '^caret', ';semi', '/path/to', 'http://example.com/a?b=c#d', 'a@b.co', "'", '"', "''", '""', "'quoted'", '"quoted"', '"a\\nb"', '"unterminated', "'unterminated", 'a\'b', 'a"b', "\0nul", "\x0Bvt", "\x0Cff", "\x7F", 'a,b', 'a, b', 'x]', 'x}', '\\', '\\n', 'a\\', "end\\\n"];

$r = fn (array $a) => $a[mt_rand(0, count($a) - 1)];

$randScalar = function () use ($r, $scalars, $words) {
    switch (mt_rand(0, 12)) {
        case 0: return null;
        case 1: return (bool) mt_rand(0, 1);
        case 2: return mt_rand(-1000, 100000);
        case 3: return mt_rand() / 1000;
        case 4: return [];
        case 5: case 6: return $r($words);
        case 7: return $r($scalars).$r($scalars);
        default: return $r($scalars);
    }
};

$randData = function (int $depth) use (&$randData, $randScalar, $r, $words, $scalars) {
    if ($depth <= 0 || mt_rand(0, 9) < 4) {
        return $randScalar();
    }
    $n = mt_rand(0, 4);
    $out = [];
    if (mt_rand(0, 1)) {
        for ($i = 0; $i < $n; $i++) {
            $out[] = $randData($depth - 1);
        }
    } else {
        for ($i = 0; $i < $n; $i++) {
            $k = mt_rand(0, 6) ? $r($words) : $r($scalars);
            $out[$k === '' ? 'e' : $k] = $randData($depth - 1);
        }
    }

    return $out;
};

$dumpFlags = [0, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE, Yaml::DUMP_NULL_AS_TILDE];
foreach (['DUMP_COMPACT_NESTED_MAPPING', 'DUMP_FORCE_DOUBLE_QUOTES_ON_VALUES', 'DUMP_NULL_AS_EMPTY'] as $c) {
    if (defined(Yaml::class.'::'.$c)) {
        $dumpFlags[] = constant(Yaml::class.'::'.$c) | Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK;
    }
}

$inserts = ["\t", "\t", " \t", "\tx", ' ', '  ', '#', ' #', ': ', ':', '-', '- ', '|', '|-', '|+', '>', '"', "'", '\\', '[', ']', '{', '}', ',', '!', '&a ', '*a', '?', '~', '---', '...', '%', '@', "\n", "\n\n", '   ', '0', 'null', 'x', "\n  ", "\n- ", "\n    x: 1", ' # c', '<<: ', 'é', '.', '+', '='];

$mutate = function (string $doc) use ($r, $inserts) {
    $lines = explode("\n", $doc);
    if (count($lines) > 40) {
        $lines = array_slice($lines, mt_rand(0, count($lines) - 40), 40);
        // keep it a plausible document: strip the common indentation of the window's first line
        $ind = strspn($lines[0], ' ');
        if ($ind && mt_rand(0, 3)) {
            $lines = array_map(fn ($l) => strspn($l, ' ') >= $ind ? substr($l, $ind) : $l, $lines);
        }
    }
    for ($k = mt_rand(1, 3); $k > 0; $k--) {
        $i = mt_rand(0, count($lines) - 1);
        $l = $lines[$i];
        switch (mt_rand(0, 11)) {
            case 0: array_splice($lines, $i, 1);
                break;
            case 1: array_splice($lines, $i, 0, [$lines[mt_rand(0, count($lines) - 1)]]);
                break;
            case 2: $lines[$i] = str_repeat(' ', mt_rand(0, 6)).ltrim($l, ' ');
                break;
            case 3: $lines[$i] = ' '.$l;
                break;
            case 4: $lines[$i] = substr($l, 1);
                break;
            case 5: $p = mt_rand(0, strlen($l));
                $lines[$i] = substr($l, 0, $p).$r($inserts).substr($l, $p);
                break;
            case 6: $lines[$i] = $l.$r($inserts);
                break;
            case 7: $p = mt_rand(0, max(0, strlen($l) - 1));
                $lines[$i] = substr($l, 0, $p).substr($l, $p + mt_rand(1, 3));
                break;
            case 8: $lines[$i] = strspn($l, ' ') ? str_repeat(' ', strspn($l, ' ')).$r($inserts).ltrim($l, ' ') : $r($inserts).$l;
                break;
            case 9: array_splice($lines, $i, 0, [str_repeat(' ', mt_rand(0, 4)).$r(['', '# comment', '- x', 'k: v', 'k:', '-', 'text', '"q"', '|', 'k: |', 'k: |-', '- k: |', '- a: 1', '  ', 'k: >', "\tcode();", "  \tcode();", "k: |\n      \ttabbed\n      more", "k: |-\n   \ttabbed\n\n   x"])]);
                break;
            case 10: $j = mt_rand(0, count($lines) - 1);
                [$lines[$i], $lines[$j]] = [$lines[$j], $lines[$i]];
                break;
            case 11: $lines = array_slice($lines, 0, max(1, $i));
                break;
        }
        if (! $lines) {
            $lines = ['a: 1'];
        }
    }

    return implode("\n", $lines);
};

$stats = ['cases' => 0, 'fast' => 0, 'fallback' => 0, 'symfony_throws' => 0, 'WRONG' => 0];
$failures = [];

for ($c = 0; $c < $count; $c++) {
    $mode = mt_rand(0, 9);
    if ($mode < 4) {
        try {
            $doc = Yaml::dump($randData(mt_rand(1, 5)), $r([1, 2, 3, 100, 100, 100]), $r([2, 2, 4]), $r($dumpFlags));
        } catch (Throwable) {
            continue;
        }
        if (mt_rand(0, 4) === 0) {
            $doc = $mutate($doc);
        }
        if (mt_rand(0, 9) === 0) {
            $doc = "---\n".$doc;
        }
        if (mt_rand(0, 5) === 0) {
            $doc = rtrim($doc, "\n");
        }
    } else {
        $doc = $mutate($r($corpus));
    }

    $stats['cases']++;

    try {
        $fastResult = $fast($doc);
    } catch (Throwable $e) {
        $stats['WRONG']++;
        $failures[] = ['doc' => $doc, 'why' => 'fast parser threw '.get_class($e).': '.$e->getMessage()];

        continue;
    }

    if ($fastResult === null) {
        $stats['fallback']++;

        continue;
    }
    $stats['fast']++;

    try {
        $ref = $sym->parse($doc);
        $refS = serialize($ref);
    } catch (Throwable $e) {
        $stats['symfony_throws']++;
        $stats['WRONG']++;
        $failures[] = ['doc' => $doc, 'why' => 'symfony throws: '.$e->getMessage(), 'fast' => $fastResult];

        continue;
    }

    if (serialize($fastResult) !== $refS) {
        $stats['WRONG']++;
        $failures[] = ['doc' => $doc, 'why' => 'different result', 'fast' => $fastResult, 'symfony' => $ref];
    }
}

echo json_encode($stats), "\n";

foreach (array_slice($failures, 0, 5) as $f) {
    echo '---- ', $f['why'], "\n", json_encode($f['doc']), "\n fast:    ", json_encode($f['fast'] ?? null, JSON_PARTIAL_OUTPUT_ON_ERROR), "\n symfony: ", json_encode($f['symfony'] ?? null, JSON_PARTIAL_OUTPUT_ON_ERROR), "\n";
}

exit($failures ? 1 : 0);
