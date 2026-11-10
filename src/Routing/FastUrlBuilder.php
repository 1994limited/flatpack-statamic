<?php

namespace NineteenNinetyFour\Flatpack\Routing;

use Statamic\Facades\Antlers;
use Statamic\Routing\UrlBuilder;

/**
 * Statamic builds every entry's URL by running its route through Antlers, with a new parser
 * each time. Most routes are only placeholders ("/books/{slug}", "{parent_uri}/{slug}"). For
 * those, the values are put in here and the result goes through the rest of Statamic's
 * builder (slugifying, tidying) with a parser that has nothing to do.
 *
 * Statamic's builder is used as it is for anything else: a route written in Antlers, a
 * placeholder Antlers might read as something other than a variable, a placeholder with no
 * value, and a value that isn't a plain string or integer or has braces in it.
 */
class FastUrlBuilder extends UrlBuilder
{
    /** Names Antlers gives a meaning of its own. */
    private const RESERVED = [
        'true' => 1, 'false' => 1, 'null' => 1, 'void' => 1, 'if' => 1, 'else' => 1, 'elseif' => 1, 'unless' => 1, 'elseunless' => 1,
        'endif' => 1, 'endunless' => 1, 'and' => 1, 'or' => 1, 'xor' => 1, 'not' => 1, 'switch' => 1, 'once' => 1, 'endonce' => 1,
        'noparse' => 1, 'endnoparse' => 1, 'stop' => 1, 'recursive' => 1, 'new' => 1, 'bwa' => 1, 'bwo' => 1, 'bxor' => 1, 'bnot' => 1, 'bsl' => 1, 'bsr' => 1,
        'list' => 1, 'as' => 1, 'arr' => 1, 'merge' => 1, 'pluck' => 1, 'where' => 1, 'take' => 1, 'skip' => 1, 'orderby' => 1, 'groupby' => 1,
    ];

    private static ?PlainTextParser $plain = null;

    private static ?object $noData = null;

    public function build($route)
    {
        $pattern = is_array($route) ? ($route[$this->content->locale()] ?? null) : $route;

        if (! is_string($pattern) || str_contains($pattern, '{{')) {
            return parent::build($route);
        }

        $data = null;
        $simple = true;

        // The same placeholders Statamic turns into Antlers variables.
        $filled = preg_replace_callback('/{\s*([a-zA-Z0-9_\-]+)\s*}/', function ($match) use (&$data, &$simple) {
            $name = $match[1];

            if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name) || isset(self::RESERVED[strtolower($name)])) {
                $simple = false;

                return '';
            }

            $data ??= array_merge($this->content->routeData(), $this->merged ?? []);
            $value = $data[$name] ?? null;

            if (is_int($value)) {
                return (string) $value;
            }

            if (is_string($value) && strpbrk($value, '{}') === false) {
                return $value;
            }

            $simple = false;

            return '';
        }, $pattern);

        if (! $simple || $filled === null || strpbrk($filled, '{}') !== false) {
            return parent::build($route);
        }

        // Statamic's builder does the rest. It has no values left to look up, so it is given
        // content with no route data rather than working the entry's out a second time.
        $content = $this->content;
        $merged = $this->merged ?? [];
        $thrown = null;

        $this->content = self::$noData ??= new class
        {
            public function routeData()
            {
                return [];
            }
        };
        $this->merged = [];

        try {
            // usingParser() doesn't put the usual parser back if the callback throws, so nothing is thrown through it.
            $url = Antlers::usingParser(self::$plain ??= new PlainTextParser, function () use ($filled, &$thrown) {
                try {
                    return parent::build($filled);
                } catch (\Throwable $e) {
                    $thrown = $e;

                    return null;
                }
            });
        } finally {
            $this->content = $content;
            $this->merged = $merged;
        }

        if ($thrown) {
            throw $thrown;
        }

        return $url;
    }
}
