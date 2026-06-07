<?php

declare(strict_types=1);

namespace SearchGateway\Tests\Prompt;

use PHPUnit\Framework\TestCase;
use SearchGateway\Prompt\PromptBuilder;

final class PromptBuilderTest extends TestCase
{
    public function testBuildsMarkdownPromptWithCitations(): void
    {
        $prompt = (new PromptBuilder())
            ->system('You are an expert.')
            ->task('Explain PHP 8.4')
            ->sources([
                ['title' => 'PHP 8.4 RFC', 'url' => 'https://php.net', 'passage' => 'JIT improvements'],
            ])
            ->format('markdown')
            ->tone('technical')
            ->maxWords(200)
            ->build();

        $this->assertStringContainsString('You are an expert.', $prompt);
        $this->assertStringContainsString('Tone: technical', $prompt);
        $this->assertStringContainsString('Limit your answer to 200 words.', $prompt);
        $this->assertStringContainsString('[1] PHP 8.4 RFC', $prompt);
        $this->assertStringContainsString('Explain PHP 8.4', $prompt);
    }

    public function testBuildsWithoutCitations(): void
    {
        $prompt = (new PromptBuilder())
            ->task('Summarize')
            ->sources([['title' => 'T', 'url' => 'https://t.com', 'passage' => 'P']])
            ->citations(false)
            ->build();

        $this->assertStringContainsString('Title: T', $prompt);
        $this->assertStringNotContainsString('[1]', $prompt);
    }
}
