<?php

declare(strict_types=1);

namespace SearchGateway\Plugin;

use SearchGateway\Request\SearchRequest;
use SearchGateway\Request\SearchResponse;

final class PluginPipeline
{
    /**
     * @var list<PluginInterface>
     */
    private array $plugins = [];

    public function add(PluginInterface $plugin): self
    {
        $this->plugins[] = $plugin;
        return $this;
    }

    public function withPlugin(PluginInterface $plugin): self
    {
        $clone = clone $this;
        $clone->plugins = $this->plugins;
        $clone->plugins[] = $plugin;
        return $clone;
    }

    /**
     * @param list<PluginInterface> $plugins
     */
    public function withPlugins(array $plugins): self
    {
        $clone = clone $this;
        $clone->plugins = array_values(array_filter($plugins, static fn ($p): bool => $p instanceof PluginInterface));
        return $clone;
    }

    /**
     * @return list<PluginInterface>
     */
    public function all(): array
    {
        return $this->plugins;
    }

    public function count(): int
    {
        return count($this->plugins);
    }

    public function clear(): self
    {
        $clone = clone $this;
        $clone->plugins = [];
        return $clone;
    }

    public function runBefore(SearchRequest $request, PluginContext $context): SearchRequest
    {
        $current = $request;
        foreach ($this->plugins as $plugin) {
            $current = $plugin->beforeSearch($current, $context);
        }
        return $current;
    }

    public function runAfter(SearchResponse $response, PluginContext $context): SearchResponse
    {
        $current = $response;
        foreach ($this->plugins as $plugin) {
            $current = $plugin->afterSearch($current, $context);
        }
        return $current;
    }

    public function runAfterReversed(SearchResponse $response, PluginContext $context): SearchResponse
    {
        $current = $response;
        foreach (array_reverse($this->plugins) as $plugin) {
            $current = $plugin->afterSearch($current, $context);
        }
        return $current;
    }
}
