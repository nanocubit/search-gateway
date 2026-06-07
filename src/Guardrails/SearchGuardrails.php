<?php

declare(strict_types=1);

namespace SearchGateway\Guardrails;

use SearchGateway\Contract\GenerativeSearchResultDTO;

/**
 * Output guardrails for generative search: hallucination detection, citation
 * enforcement, blocked domain filtering, length / source / PII checks.
 *
 * Inspired by Guardrails AI: each validator is a callable that receives the
 * prepared `$data` array and returns null (ok) or an error string.
 */
final class SearchGuardrails
{
    /** @var list<callable(array<string, mixed>): ?string> */
    private array $validators = [];

    public function addValidator(callable $fn): self
    {
        $this->validators[] = $fn;
        return $this;
    }

    /**
     * @return list<string>
     */
    public function validate(GenerativeSearchResultDTO $dto): array
    {
        $violations = [];
        $data = [
            'answer' => $dto->answer,
            'sources' => $dto->sources,
            'meta' => $dto->meta,
        ];

        foreach ($this->validators as $validator) {
            $error = $validator($data);
            if ($error !== null) {
                $violations[] = $error;
            }
        }

        return $violations;
    }

    public static function noEmptyAnswer(): callable
    {
        return static function (array $data): ?string {
            $answer = self::scalarString($data, 'answer');
            return trim($answer) === '' ? 'Answer is empty' : null;
        };
    }

    public static function minSources(int $min = 1): callable
    {
        return static function (array $data) use ($min): ?string {
            $sources = self::asList($data, 'sources');
            return count($sources) < $min ? "Minimum {$min} sources required" : null;
        };
    }

    public static function maxAnswerLength(int $maxChars): callable
    {
        return static function (array $data) use ($maxChars): ?string {
            $answer = self::scalarString($data, 'answer');
            return strlen($answer) > $maxChars ? "Answer exceeds {$maxChars} characters" : null;
        };
    }

    /**
     * Block specific domains OR full URLs (substring match on host/url).
     *
     * @param list<string> $blocked Each entry is treated as a substring of domain OR URL.
     */
    public static function noBlockedDomains(array $blocked): callable
    {
        return static function (array $data) use ($blocked): ?string {
            $sources = self::asList($data, 'sources');
            foreach ($sources as $src) {
                if (!is_array($src)) {
                    continue;
                }
                $url = self::scalarString($src, 'url');
                $domainRaw = parse_url($url, PHP_URL_HOST);
                $domain = is_string($domainRaw) ? $domainRaw : '';
                foreach ($blocked as $b) {
                    $needle = (string) $b;
                    if ($needle === '') {
                        continue;
                    }
                    if (str_contains($domain, $needle) || str_contains($url, $needle)) {
                        return "Source from blocked domain: {$domain}";
                    }
                }
            }
            return null;
        };
    }

    public static function answerContainsCitations(): callable
    {
        return static function (array $data): ?string {
            $answer = self::scalarString($data, 'answer');
            if ($answer === '') {
                return null;
            }
            return preg_match('/\[\d+\]/', $answer) === 1
                ? null
                : 'Answer must contain citations [1], [2], etc.';
        };
    }

    /**
     * Hallucination guard: every [N] citation in the answer must map to a real
     * source. Fails if any citation index >= number of sources.
     */
    public static function noHallucinations(): callable
    {
        return static function (array $data): ?string {
            $answer = self::scalarString($data, 'answer');
            $sources = self::asList($data, 'sources');
            if ($answer === '' || $sources === []) {
                return null;
            }
            if (preg_match_all('/\[(\d+)\]/', $answer, $matches) === false) {
                return null;
            }
            $maxIndex = count($sources);
            $bad = [];
            foreach ($matches[1] as $idx) {
                $n = (int) $idx;
                if ($n < 1 || $n > $maxIndex) {
                    $bad[] = $n;
                }
            }
            if ($bad !== []) {
                $unique = array_values(array_unique($bad));
                return 'Answer cites non-existent sources: [' . implode('], [', $unique) . ']';
            }
            return null;
        };
    }

    /**
     * Require specific URLs (full match) or domains (host match) to appear in
     * the answer or as a source. Fails if any required entry is missing.
     *
     * @param list<string> $required Each entry is either a full URL or a domain
     *                               (e.g. "https://php.net" or "php.net").
     * @param bool $checkSources If true, also accept required entries via the
     *                           sources list, not just literal mention in answer.
     */
    public static function requiredUrls(array $required, bool $checkSources = true): callable
    {
        return static function (array $data) use ($required, $checkSources): ?string {
            $answer = self::scalarString($data, 'answer');
            $sources = self::asList($data, 'sources');
            $haystack = $answer;
            if ($checkSources) {
                foreach ($sources as $src) {
                    if (!is_array($src)) {
                        continue;
                    }
                    $haystack .= ' ' . self::scalarString($src, 'url');
                }
            }

            $missing = [];
            foreach ($required as $needle) {
                $n = (string) $needle;
                if ($n === '' || !str_contains($haystack, $n)) {
                    $missing[] = $n;
                }
            }
            if ($missing !== []) {
                return 'Required URLs/domains not found: ' . implode(', ', $missing);
            }
            return null;
        };
    }

    /**
     * Minimum citation coverage: at least N% of the cited indices in the answer
     * must point to existing sources. Useful together with noHallucinations() to
     * also enforce a minimum density of citations.
     */
    public static function minCitationCoverage(float $ratio = 0.5): callable
    {
        return static function (array $data) use ($ratio): ?string {
            $answer = self::scalarString($data, 'answer');
            $sources = self::asList($data, 'sources');
            if ($answer === '' || $sources === []) {
                return null;
            }
            $maxIndex = count($sources);
            $valid = 0;
            $total = 0;
            if (preg_match_all('/\[(\d+)\]/', $answer, $matches) !== false) {
                foreach ($matches[1] as $idx) {
                    $n = (int) $idx;
                    $total++;
                    if ($n >= 1 && $n <= $maxIndex) {
                        $valid++;
                    }
                }
            }
            if ($total === 0) {
                return null;
            }
            $coverage = $valid / $total;
            if ($coverage < $ratio) {
                $pct = round($coverage * 100, 1);
                $reqPct = round($ratio * 100, 1);
                return "Citation coverage {$pct}% below required {$reqPct}%";
            }
            return null;
        };
    }

    /**
     * PII guard: block answers that contain patterns resembling emails, phone
     * numbers, or credit-card numbers.
     *
     * @param list<string>|null $extraPatterns Additional regex patterns.
     */
    public static function noPii(?array $extraPatterns = null): callable
    {
        $defaultPatterns = [
            '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i' => 'email',
            '/(?:\+?\d{1,3}[\s.-]?)?\(?\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4}/' => 'phone',
            '/\b(?:\d[ -]?){13,16}\b/' => 'credit-card',
        ];
        $patterns = $extraPatterns === null
            ? $defaultPatterns
            : array_merge($defaultPatterns, array_fill_keys($extraPatterns, 'pii'));

        return static function (array $data) use ($patterns): ?string {
            $answer = self::scalarString($data, 'answer');
            if ($answer === '') {
                return null;
            }
            foreach ($patterns as $pattern => $kind) {
                if (preg_match($pattern, $answer) === 1) {
                    return "Answer contains PII ({$kind})";
                }
            }
            return null;
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function getKey(array $data, string $key): mixed
    {
        return $data[$key] ?? null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function scalarString(array $data, string $key): string
    {
        $raw = self::getKey($data, $key);
        return is_scalar($raw) ? (string) $raw : '';
    }

    /**
     * @param array<string, mixed> $data
     * @return list<mixed>
     */
    private static function asList(array $data, string $key): array
    {
        $raw = self::getKey($data, $key);
        if (!is_array($raw)) {
            return [];
        }
        return array_values($raw);
    }
}
