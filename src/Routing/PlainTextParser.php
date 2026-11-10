<?php

namespace NineteenNinetyFour\Flatpack\Routing;

use Statamic\Contracts\View\Antlers\Parser;

/**
 * Stands in for Antlers while a URL that has already had its values put in goes through
 * Statamic's URL builder: the text has nothing left to parse, so it is returned as it is.
 */
final class PlainTextParser implements Parser
{
    public function parse($text, $data = [])
    {
        return (string) $text;
    }

    public function parseView($view, $text, $data = [])
    {
        return (string) $text;
    }

    public function extractNoparse($text)
    {
        return $text;
    }

    public function injectNoparse($text)
    {
        return $text;
    }

    public function valueWithNoparse($text)
    {
        return $text;
    }

    public function getVariable($key, $context, $default = null)
    {
        return $default;
    }

    public function allowPhp($allow = true)
    {
        return $this;
    }

    public function callback($callback)
    {
        return $this;
    }

    public function cascade($cascade)
    {
        return $this;
    }
}
