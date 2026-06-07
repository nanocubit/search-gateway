<?php

declare(strict_types=1);

namespace SearchGateway\Plugin;

use SearchGateway\Request\SearchRequest;
use SearchGateway\Request\SearchResponse;

interface PluginInterface
{
    public function name(): string;

    /**
     * Transform a request before it reaches the gateway. Return the (possibly modified) request.
     */
    public function beforeSearch(SearchRequest $request, PluginContext $context): SearchRequest;

    /**
     * Transform a response after the gateway has produced it. Return the (possibly modified) response.
     */
    public function afterSearch(SearchResponse $response, PluginContext $context): SearchResponse;
}
