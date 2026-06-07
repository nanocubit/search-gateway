<?php

declare(strict_types=1);

namespace SearchGateway\Router;

final class PathMatcher
{
    public static function normalise(string $path): string
    {
        if ($path === '') {
            return '/';
        }
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }
        $collapsed = preg_replace('#/+#', '/', $path);
        if ($collapsed === null) {
            $collapsed = $path;
        }
        if (strlen($collapsed) > 1 && substr($collapsed, -1) === '/') {
            $collapsed = rtrim($collapsed, '/');
        }
        return $collapsed;
    }

    /**
     * Match $actual against $pattern. Returns map of path params on success, null otherwise.
     *
     * Supported syntax:
     *   - static segments must match exactly
     *   - :name captures one segment
     *   - * matches zero or more trailing segments (only at the end, only once)
     *
     * @return array<string, string>|null
     */
    public static function match(string $pattern, string $actual): ?array
    {
        $pattern = self::normalise($pattern);
        $actual = self::normalise($actual);

        $hasWildcard = str_ends_with($pattern, '/*');
        if ($hasWildcard) {
            $pattern = substr($pattern, 0, -2);
        }

        $patternParts = $pattern === '/' ? [] : explode('/', ltrim($pattern, '/'));
        $actualParts = $actual === '/' ? [] : explode('/', ltrim($actual, '/'));

        if ($hasWildcard) {
            $minPatternParts = count($patternParts);
            if (count($actualParts) < $minPatternParts) {
                return null;
            }
        } else {
            if (count($patternParts) !== count($actualParts)) {
                return null;
            }
        }

        $params = [];
        $patternCount = count($patternParts);
        for ($i = 0; $i < $patternCount; $i++) {
            $pp = $patternParts[$i];
            $ap = $actualParts[$i];
            if (isset($pp[0]) && $pp[0] === ':') {
                $name = substr($pp, 1);
                if ($name === '' || !self::isValidParamName($name)) {
                    return null;
                }
                if ($ap === '') {
                    return null;
                }
                $params[$name] = $ap;
                continue;
            }
            if ($pp !== $ap) {
                return null;
            }
        }

        if ($hasWildcard) {
            $tail = array_slice($actualParts, $patternCount);
            $params['*'] = implode('/', $tail);
        }

        return $params;
    }

    private static function isValidParamName(string $name): bool
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            return false;
        }
        return true;
    }
}
