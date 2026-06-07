<?php

declare(strict_types=1);

namespace SearchGateway\Router;

interface YamlParserInterface
{
    /**
     * @return mixed
     */
    public function parse(string $yaml);
}
