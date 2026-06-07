<?php

declare(strict_types=1);

namespace SearchGateway\Tests\Formatter;

use PHPUnit\Framework\TestCase;
use SearchGateway\Contract\GenerativeSearchResultDTO;
use SearchGateway\Formatter\ResponseFormatter;

final class ResponseFormatterTest extends TestCase
{
    public function testToMarkdown(): void
    {
        $dto = new GenerativeSearchResultDTO(
            answer: 'PHP 8.4 is great.',
            sources: [['title' => 'PHP.net', 'url' => 'https://php.net']],
            meta: []
        );

        $fmt = new ResponseFormatter();
        $md = $fmt->toMarkdown($dto);

        $this->assertStringContainsString('PHP 8.4 is great.', $md);
        $this->assertStringContainsString('[PHP.net](https://php.net)', $md);
    }

    public function testToJson(): void
    {
        $dto = new GenerativeSearchResultDTO(answer: 'A', sources: [], meta: ['k' => 'v']);
        $fmt = new ResponseFormatter();
        $json = $fmt->toJson($dto);

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertSame('A', $decoded['answer']);
        $meta = is_array($decoded['meta'] ?? null) ? $decoded['meta'] : [];
        $this->assertSame('v', $meta['k']);
    }

    public function testToTemplate(): void
    {
        $dto = new GenerativeSearchResultDTO(answer: 'Yes', sources: [['title' => 'T', 'url' => 'U']], meta: []);
        $fmt = new ResponseFormatter();
        $out = $fmt->toTemplate($dto, 'ANS: {answer} | SRC: {sources}');

        $this->assertSame('ANS: Yes | SRC: 1. T — U', $out);
    }
}
