<?php

declare(strict_types=1);

namespace SearchGateway\Router;

final class RoutePresets
{
    /**
     * @return list<Route>
     */
    public static function webSearch(): array
    {
        return [
            new Route(
                name: 'v1.search.web',
                method: Route::METHOD_POST,
                path: '/v1/search/web',
                action: Route::ACTION_SEARCH_WEB,
                requiredScopes: ['search:web'],
                rateLimit: ['limit' => 100, 'window' => 60],
            ),
            new Route(
                name: 'v1.search.news',
                method: Route::METHOD_POST,
                path: '/v1/search/news',
                action: Route::ACTION_SEARCH_NEWS,
                requiredScopes: ['search:news'],
                rateLimit: ['limit' => 60, 'window' => 60],
            ),
            new Route(
                name: 'v1.search.images',
                method: Route::METHOD_POST,
                path: '/v1/search/images',
                action: Route::ACTION_SEARCH_IMAGES,
                requiredScopes: ['search:images'],
                rateLimit: ['limit' => 60, 'window' => 60],
            ),
        ];
    }

    /**
     * @return list<Route>
     */
    public static function generative(): array
    {
        return [
            new Route(
                name: 'v1.search.gen',
                method: Route::METHOD_POST,
                path: '/v1/search/gen',
                action: Route::ACTION_SEARCH_GEN,
                requiredScopes: ['llm:generate'],
                rateLimit: ['limit' => 30, 'window' => 60],
            ),
            new Route(
                name: 'v1.llm.context',
                method: Route::METHOD_POST,
                path: '/v1/llm/context',
                action: Route::ACTION_LLM_CONTEXT,
                requiredScopes: ['llm:context'],
            ),
            new Route(
                name: 'v1.hybrid',
                method: Route::METHOD_POST,
                path: '/v1/hybrid',
                action: Route::ACTION_HYBRID,
                requiredScopes: ['llm:hybrid'],
            ),
        ];
    }

    /**
     * @return list<Route>
     */
    public static function analytics(): array
    {
        return [
            new Route(
                name: 'v1.wordstat',
                method: Route::METHOD_POST,
                path: '/v1/wordstat',
                action: Route::ACTION_WORDSTAT,
                requiredScopes: ['analytics:wordstat'],
            ),
        ];
    }

    /**
     * @return list<Route>
     */
    public static function streaming(): array
    {
        return [
            new Route(
                name: 'v1.stream.chat',
                method: Route::METHOD_POST,
                path: '/v1/stream/chat',
                action: 'stream',
                requiredScopes: ['llm:stream'],
                rateLimit: ['limit' => 20, 'window' => 60],
                config: ['description' => 'SSE streaming chat completion'],
            ),
            new Route(
                name: 'v1.stream.gen',
                method: Route::METHOD_POST,
                path: '/v1/stream/gen',
                action: 'stream',
                requiredScopes: ['llm:stream'],
                config: ['description' => 'SSE streaming generation'],
            ),
        ];
    }

    /**
     * @return list<Route>
     */
    public static function browserHistory(): array
    {
        return [
            new Route(
                name: 'v1.browser.history',
                method: Route::METHOD_GET,
                path: '/v1/browser/history',
                action: Route::ACTION_SEARCH_WEB,
                requiredScopes: ['browser:history'],
                rateLimit: ['limit' => 60, 'window' => 60],
            ),
        ];
    }

    /**
     * @return list<Route>
     */
    public static function all(): array
    {
        return array_merge(
            self::webSearch(),
            self::generative(),
            self::analytics(),
            self::streaming(),
            self::browserHistory(),
        );
    }
}
