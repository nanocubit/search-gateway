<?php

declare(strict_types=1);

namespace SearchGateway\Tests\Guardrails;

use PHPUnit\Framework\TestCase;
use SearchGateway\Contract\GenerativeSearchResultDTO;
use SearchGateway\Guardrails\SearchGuardrails;

final class SearchGuardrailsTest extends TestCase
{
    public function testNoViolationsWhenNoValidatorsRegistered(): void
    {
        $guard = new SearchGuardrails();
        $dto = new GenerativeSearchResultDTO(answer: 'Hello', sources: [], meta: []);

        $this->assertSame([], $guard->validate($dto));
    }

    public function testNoEmptyAnswerDetectsEmpty(): void
    {
        $guard = (new SearchGuardrails())->addValidator(SearchGuardrails::noEmptyAnswer());

        $empty = $guard->validate(new GenerativeSearchResultDTO(answer: '', sources: [], meta: []));
        $whitespace = $guard->validate(new GenerativeSearchResultDTO(answer: "   \n  ", sources: [], meta: []));
        $ok = $guard->validate(new GenerativeSearchResultDTO(answer: 'real answer', sources: [], meta: []));

        $this->assertSame(['Answer is empty'], $empty);
        $this->assertSame(['Answer is empty'], $whitespace);
        $this->assertSame([], $ok);
    }

    public function testMinSources(): void
    {
        $guard = (new SearchGuardrails())->addValidator(SearchGuardrails::minSources(2));

        $noSources = $guard->validate(new GenerativeSearchResultDTO(answer: 'a', sources: [], meta: []));
        $oneSource = $guard->validate(new GenerativeSearchResultDTO(answer: 'a', sources: [['url' => 'u']], meta: []));
        $twoSources = $guard->validate(new GenerativeSearchResultDTO(
            answer: 'a',
            sources: [['url' => 'u1'], ['url' => 'u2']],
            meta: []
        ));

        $this->assertCount(1, $noSources);
        $this->assertStringContainsString('Minimum 2 sources', $noSources[0]);
        $this->assertCount(1, $oneSource);
        $this->assertSame([], $twoSources);
    }

    public function testMaxAnswerLength(): void
    {
        $guard = (new SearchGuardrails())->addValidator(SearchGuardrails::maxAnswerLength(10));

        $short = $guard->validate(new GenerativeSearchResultDTO(answer: 'short', sources: [], meta: []));
        $long = $guard->validate(new GenerativeSearchResultDTO(answer: 'a much longer answer here', sources: [], meta: []));

        $this->assertSame([], $short);
        $this->assertCount(1, $long);
        $this->assertStringContainsString('exceeds 10', $long[0]);
    }

    public function testNoBlockedDomainsByHostSubstring(): void
    {
        $guard = (new SearchGuardrails())->addValidator(SearchGuardrails::noBlockedDomains(['spam.com']));

        $dto = new GenerativeSearchResultDTO(
            answer: 'a',
            sources: [['url' => 'https://news.spam.com/article', 'title' => 't']],
            meta: []
        );

        $this->assertCount(1, $guard->validate($dto));
        $this->assertStringContainsString('spam.com', $guard->validate($dto)[0]);
    }

    public function testNoBlockedDomainsByUrlSubstring(): void
    {
        $guard = (new SearchGuardrails())->addValidator(SearchGuardrails::noBlockedDomains(['forbidden-path']));

        $dto = new GenerativeSearchResultDTO(
            answer: 'a',
            sources: [['url' => 'https://good.com/forbidden-path/x', 'title' => 't']],
            meta: []
        );

        $this->assertCount(1, $guard->validate($dto));
    }

    public function testNoBlockedDomainsAllowsCleanSources(): void
    {
        $guard = (new SearchGuardrails())->addValidator(SearchGuardrails::noBlockedDomains(['spam.com']));

        $dto = new GenerativeSearchResultDTO(
            answer: 'a',
            sources: [['url' => 'https://news.good.com/a', 'title' => 't']],
            meta: []
        );

        $this->assertSame([], $guard->validate($dto));
    }

    public function testAnswerContainsCitations(): void
    {
        $guard = (new SearchGuardrails())->addValidator(SearchGuardrails::answerContainsCitations());

        $cited = $guard->validate(new GenerativeSearchResultDTO(answer: 'See [1] and [2].', sources: [], meta: []));
        $uncited = $guard->validate(new GenerativeSearchResultDTO(answer: 'No citations here.', sources: [], meta: []));
        $empty = $guard->validate(new GenerativeSearchResultDTO(answer: '', sources: [], meta: []));

        $this->assertSame([], $cited);
        $this->assertCount(1, $uncited);
        $this->assertStringContainsString('citations', $uncited[0]);
        $this->assertSame([], $empty, 'empty answer is exempt');
    }

    public function testNoHallucinationsCatchesInvalidIndices(): void
    {
        $guard = (new SearchGuardrails())->addValidator(SearchGuardrails::noHallucinations());

        $bad = $guard->validate(new GenerativeSearchResultDTO(
            answer: 'According to [1] and [3], ...',
            sources: [['url' => 'u1']],
            meta: []
        ));
        $good = $guard->validate(new GenerativeSearchResultDTO(
            answer: 'According to [1].',
            sources: [['url' => 'u1']],
            meta: []
        ));
        $uncited = $guard->validate(new GenerativeSearchResultDTO(
            answer: 'No citations here.',
            sources: [['url' => 'u1']],
            meta: []
        ));
        $empty = $guard->validate(new GenerativeSearchResultDTO(answer: '', sources: [], meta: []));

        $this->assertCount(1, $bad);
        $this->assertStringContainsString('[3]', $bad[0]);
        $this->assertSame([], $good);
        $this->assertSame([], $uncited, 'uncited answer has no hallucination');
        $this->assertSame([], $empty);
    }

    public function testNoHallucinationsIgnoresZeroIndex(): void
    {
        $guard = (new SearchGuardrails())->addValidator(SearchGuardrails::noHallucinations());

        $dto = new GenerativeSearchResultDTO(
            answer: 'Citation [0] and [1].',
            sources: [['url' => 'u1']],
            meta: []
        );

        $violations = $guard->validate($dto);
        $this->assertCount(1, $violations);
        $this->assertStringContainsString('[0]', $violations[0]);
    }

    public function testRequiredUrlsEnforcesAllListed(): void
    {
        $guard = (new SearchGuardrails())->addValidator(SearchGuardrails::requiredUrls(['php.net', 'github.com']));

        $both = $guard->validate(new GenerativeSearchResultDTO(
            answer: 'See php.net and github.com for details.',
            sources: [],
            meta: []
        ));
        $one = $guard->validate(new GenerativeSearchResultDTO(
            answer: 'Only php.net here.',
            sources: [],
            meta: []
        ));
        $none = $guard->validate(new GenerativeSearchResultDTO(
            answer: 'No URLs.',
            sources: [],
            meta: []
        ));

        $this->assertSame([], $both);
        $this->assertCount(1, $one);
        $this->assertStringContainsString('github.com', $one[0]);
        $this->assertCount(1, $none);
    }

    public function testRequiredUrlsCanCheckSources(): void
    {
        $guard = (new SearchGuardrails())->addValidator(
            SearchGuardrails::requiredUrls(['required.example.com'], checkSources: true)
        );

        $onlyInSources = $guard->validate(new GenerativeSearchResultDTO(
            answer: 'No URL in answer.',
            sources: [['url' => 'https://required.example.com/page']],
            meta: []
        ));
        $noMatch = $guard->validate(new GenerativeSearchResultDTO(
            answer: 'Nothing relevant.',
            sources: [['url' => 'https://other.com']],
            meta: []
        ));

        $this->assertSame([], $onlyInSources);
        $this->assertCount(1, $noMatch);
    }

    public function testRequiredUrlsWithoutSourceCheck(): void
    {
        $guard = (new SearchGuardrails())->addValidator(
            SearchGuardrails::requiredUrls(['required.example.com'], checkSources: false)
        );

        $dto = new GenerativeSearchResultDTO(
            answer: 'No URL.',
            sources: [['url' => 'https://required.example.com/page']],
            meta: []
        );

        // checkSources=false: must be in answer
        $this->assertCount(1, $guard->validate($dto));
    }

    public function testMinCitationCoverage(): void
    {
        $guard = (new SearchGuardrails())->addValidator(SearchGuardrails::minCitationCoverage(0.5));

        $allValid = $guard->validate(new GenerativeSearchResultDTO(
            answer: '[1] [2]',
            sources: [['url' => 'u1'], ['url' => 'u2']],
            meta: []
        ));
        $partialValid = $guard->validate(new GenerativeSearchResultDTO(
            answer: '[1] [3] [5] [7]',
            sources: [['url' => 'u1'], ['url' => 'u2']],
            meta: []
        ));
        $noCitations = $guard->validate(new GenerativeSearchResultDTO(
            answer: 'no citations',
            sources: [['url' => 'u1']],
            meta: []
        ));

        $this->assertSame([], $allValid);
        $this->assertCount(1, $partialValid);
        $this->assertStringContainsString('25%', $partialValid[0]);
        $this->assertSame([], $noCitations, 'no citations: skipped');
    }

    public function testNoPiiDetectsEmail(): void
    {
        $guard = (new SearchGuardrails())->addValidator(SearchGuardrails::noPii());

        $dto = new GenerativeSearchResultDTO(
            answer: 'Contact us at admin@example.com for details.',
            sources: [],
            meta: []
        );

        $violations = $guard->validate($dto);
        $this->assertCount(1, $violations);
        $this->assertStringContainsString('email', $violations[0]);
    }

    public function testNoPiiDetectsPhone(): void
    {
        $guard = (new SearchGuardrails())->addValidator(SearchGuardrails::noPii());

        $dto = new GenerativeSearchResultDTO(
            answer: 'Call +1 (555) 123-4567 now.',
            sources: [],
            meta: []
        );

        $this->assertCount(1, $guard->validate($dto));
    }

    public function testNoPiiDetectsCreditCard(): void
    {
        $guard = (new SearchGuardrails())->addValidator(SearchGuardrails::noPii());

        $dto = new GenerativeSearchResultDTO(
            answer: 'Card: 4111 1111 1111 1111',
            sources: [],
            meta: []
        );

        $this->assertCount(1, $guard->validate($dto));
    }

    public function testNoPiiAllowsCleanText(): void
    {
        $guard = (new SearchGuardrails())->addValidator(SearchGuardrails::noPii());

        $dto = new GenerativeSearchResultDTO(
            answer: 'PHP 8.4 introduces property hooks and asymmetric visibility.',
            sources: [],
            meta: []
        );

        $this->assertSame([], $guard->validate($dto));
    }

    public function testNoPiiWithExtraPatterns(): void
    {
        $guard = (new SearchGuardrails())->addValidator(
            SearchGuardrails::noPii(extraPatterns: ['/\bSECRET-\d{4,}\b/'])
        );

        $dto = new GenerativeSearchResultDTO(
            answer: 'Token is SECRET-12345.',
            sources: [],
            meta: []
        );

        $this->assertCount(1, $guard->validate($dto));
        $this->assertStringContainsString('pii', $guard->validate($dto)[0]);
    }

    public function testMultipleValidatorsAccumulate(): void
    {
        $guard = (new SearchGuardrails())
            ->addValidator(SearchGuardrails::noEmptyAnswer())
            ->addValidator(SearchGuardrails::minSources(1))
            ->addValidator(SearchGuardrails::answerContainsCitations())
            ->addValidator(SearchGuardrails::noBlockedDomains(['bad.com']));

        $dto = new GenerativeSearchResultDTO(
            answer: 'No citations, no sources from bad.com',
            sources: [['url' => 'https://bad.com/x']],
            meta: []
        );

        $violations = $guard->validate($dto);
        $this->assertCount(2, $violations);
        $this->assertStringContainsString('citations', $violations[0]);
        $this->assertStringContainsString('bad.com', $violations[1]);
    }

    public function testCustomValidatorCanBeAdded(): void
    {
        $guard = (new SearchGuardrails())
            ->addValidator(static function (array $data): ?string {
                $a = is_scalar($data['answer'] ?? null) ? (string) $data['answer'] : '';
                return str_contains(strtolower($a), 'forbidden') ? 'Contains forbidden word' : null;
            });

        $clean = $guard->validate(new GenerativeSearchResultDTO(answer: 'clean text', sources: [], meta: []));
        $bad = $guard->validate(new GenerativeSearchResultDTO(answer: 'this is FORBIDDEN!', sources: [], meta: []));

        $this->assertSame([], $clean);
        $this->assertCount(1, $bad);
        $this->assertSame('Contains forbidden word', $bad[0]);
    }
}
