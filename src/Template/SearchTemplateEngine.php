<?php

declare(strict_types=1);

namespace SearchGateway\Template;

/**
 * Template engine для search-специфичных шаблонов.
 * Поддерживает переменные, loops, conditionals.
 */
final class SearchTemplateEngine
{
    /**
     * Render template with variables.
     *
     * @param array<string, mixed> $vars
     */
    public function render(string $template, array $vars): string
    {
        $result = $template;

        $result = $this->runVarSubstitution($result, $vars);
        $result = $this->runLoops($result, $vars);
        $result = $this->runConditionals($result, $vars);

        return $result;
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function runVarSubstitution(string $template, array $vars): string
    {
        $callback = function (array $m) use ($vars): string {
            $keys = explode('.', $m[1]);
            $val = $vars;
            foreach ($keys as $key) {
                if (is_array($val) && array_key_exists($key, $val)) {
                    $val = $val[$key];
                } else {
                    return '';
                }
            }
            if (is_string($val) || is_numeric($val)) {
                return (string) $val;
            }
            $encoded = json_encode($val);
            return $encoded === false ? '' : $encoded;
        };

        $replaced = preg_replace_callback('/\{\{\s*(\w+(?:\.\w+)*)\s*\}\}/', $callback, $template);
        return is_string($replaced) ? $replaced : $template;
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function runLoops(string $template, array $vars): string
    {
        $callback = function (array $m) use ($vars): string {
            $itemName = $m[1];
            $listName = $m[2];
            $body = $m[3];
            $items = $vars[$listName] ?? [];
            if (!is_array($items)) {
                return '';
            }
            $parts = [];
            foreach ($items as $item) {
                $parts[] = $this->render($body, [$itemName => $item]);
            }
            return implode('', $parts);
        };

        $replaced = preg_replace_callback(
            '/{%\s*for\s+(\w+)\s+in\s+(\w+)\s*%}(.*?){%\s*endfor\s*%}/s',
            $callback,
            $template
        );
        return is_string($replaced) ? $replaced : $template;
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function runConditionals(string $template, array $vars): string
    {
        $callback = function (array $m) use ($vars): string {
            $condition = $m[1];
            $ifBody = $m[2];
            $elseBody = $m[3] ?? '';
            return !empty($vars[$condition]) ? $ifBody : $elseBody;
        };

        $replaced = preg_replace_callback(
            '/{%\s*if\s+(\w+)\s*%}(.*?)(?:{%\s*else\s*%}(.*?))?{%\s*endif\s*%}/s',
            $callback,
            $template
        );
        return is_string($replaced) ? $replaced : $template;
    }
}
