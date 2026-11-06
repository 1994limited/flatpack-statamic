<?php

namespace NineteenNinetyFour\Flatpack\Yaml;

use Symfony\Component\Yaml\Yaml;

/**
 * Symfony's Yaml facade with a strict fast path: block-style YAML that FastParser is certain about
 * is read directly; anything else (and any call with flags) goes to Symfony's parser.
 *
 * The trailing arguments are passed through untouched: newer symfony/yaml (Statamic 6) adds
 * nesting/alias limits to parse(), older (Statamic 5) does not.
 */
class SymfonyFast extends Yaml
{
    public static function parse(string $input, int $flags = 0, mixed ...$rest): mixed
    {
        if ($flags === 0 && ! $rest && ($r = FastParser::parse($input)) !== null) {
            return $r;
        }

        return parent::parse($input, $flags, ...$rest);
    }
}
