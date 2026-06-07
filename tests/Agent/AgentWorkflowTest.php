<?php

declare(strict_types=1);

namespace SearchGateway\Tests\Agent;

use PHPUnit\Framework\TestCase;
use SearchGateway\Agent\AgentWorkflow;
use SearchGateway\Contract\SearchGatewayInterface;
use SearchGateway\Infrastructure\LLMClientInterface;
use SearchGateway\Tool\SearchTool;

final class AgentWorkflowTest extends TestCase
{
    public function testRunBuildsPromptAndCallsLlm(): void
    {
        $gateway = $this->createMock(SearchGatewayInterface::class);
        $gateway->method('llmContext')->willReturn([
            ['url' => 'https://foo.com', 'title' => 'Foo', 'passage' => 'Bar'],
        ]);

        $llm = $this->createMock(LLMClientInterface::class);
        $llm->expects($this->once())
            ->method('generate')
            ->with($this->stringContains('Foo'))
            ->willReturn('Generated answer');

        $agent = new AgentWorkflow(new SearchTool($gateway), $llm);
        $result = $agent->run('What is Foo?');

        $this->assertSame('Generated answer', $result);
    }

    public function testStepPipelineModifiesContext(): void
    {
        $gateway = $this->createMock(SearchGatewayInterface::class);
        $gateway->method('llmContext')->willReturn([]);

        $llm = $this->createMock(LLMClientInterface::class);
        $llm->method('generate')->willReturnCallback(function (string $prompt): string {
            return str_contains($prompt, 'INJECTED') ? 'OK' : 'FAIL';
        });

        $agent = new AgentWorkflow(new SearchTool($gateway), $llm);
        $agent->addStep(function (array $ctx): array {
            $ctx['extra'] = 'INJECTED';
            return $ctx;
        });

        $this->assertSame('OK', $agent->run('test'));
    }
}
