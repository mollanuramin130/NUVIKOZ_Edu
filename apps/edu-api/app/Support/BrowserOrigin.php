<?php

namespace App\Support;

class BrowserOrigin
{
    /** Exact origins only: never suffix-match sibling hosts, wildcards or credentials. */
    public static function allowed(string $origin, array $trusted): bool
    {
        $parts = parse_url($origin);
        if (! $parts || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || isset($parts['path'])) {
            return false;
        }
        if ($parts['scheme'] !== 'https' && ! ($parts['scheme'] === 'http' && in_array($parts['host'], ['localhost', '127.0.0.1'], true))) {
            return false;
        }

        return in_array($origin, $trusted, true);
    }
}
