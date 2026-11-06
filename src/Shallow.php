<?php

namespace NineteenNinetyFour\Flatpack;

/**
 * Index-first parsing: split YAML front matter at column-0 keys without parsing it.
 * Blocks larger than $limit bytes are not kept; their byte ranges are returned instead.
 */
final class Shallow
{
    /** @return array{0: string, 1: array<string, array{0:int,1:int}>} [kept yaml, skipped key => [start, end]] */
    public static function split(string $yaml, int $limit): array
    {
        $keep = ''; $skipped = []; $len = strlen($yaml); $pos = 0; $start = 0; $key = null;
        $flush = function (int $end) use (&$keep, &$skipped, &$start, &$key, $yaml, $limit) {
            if ($end <= $start) return;
            if ($key !== null && $end - $start > $limit) $skipped[$key] = [$start, $end];
            else $keep .= substr($yaml, $start, $end - $start);
        };
        while ($pos < $len) {
            $nl = strpos($yaml, "\n", $pos); $next = $nl === false ? $len : $nl + 1;
            $c = $yaml[$pos];
            if ($c !== ' ' && $c !== '-' && $c !== '#' && $c !== "\n" && $c !== "\t") {
                $flush($pos); $start = $pos;
                $colon = strpos($yaml, ':', $pos);
                $key = ($colon !== false && $colon < $next) ? trim(substr($yaml, $pos, $colon - $pos), " '\"") : null;
            }
            $pos = $next;
        }
        $flush($len);

        return [$keep, $skipped];
    }
}
