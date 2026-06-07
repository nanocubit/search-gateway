<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SearchGateway\Gateway\MockSearchGateway;
use SearchGateway\Ranker\SearchResultFilter;
use SearchGateway\Ranker\SearchResultRanker;
use SearchGateway\Tool\SearchTool;

$gateway = new MockSearchGateway([
    'searchWeb' => [
        ['type' => 'web', 'title' => 'Old PHP 7.4', 'url' => 'https://legacy.com/php74', 'passage' => 'php 7.4 old stuff', 'score' => 0.9, 'date' => '2020-01-01'],
        ['type' => 'web', 'title' => 'PHP 8.4 RFC', 'url' => 'https://php.net/rfc84', 'passage' => 'php 8.4 new features and php 8.4 benchmarks', 'score' => 0.85, 'date' => '2026-01-01'],
        ['type' => 'web', 'title' => 'Spam', 'url' => 'https://spam.com/php', 'passage' => 'click here', 'score' => 0.99],
        ['type' => 'web', 'title' => 'GitHub PHP 8.4', 'url' => 'https://github.com/php/php-src', 'passage' => 'php 8.4 source code', 'score' => 0.8, 'date' => '2026-03-01'],
    ],
]);

$tool = new SearchTool($gateway);
$docs = $tool->web('php 8.4 features');

// Filter: remove spam, old docs, keep only relevant domains
$filter = new SearchResultFilter();
$docs = $filter->filter($docs, [
    'exclude_domains' => ['spam.com'],
    'max_age_days' => 730,
    'min_score' => 0.5,
]);

// Rank: boost php.net and github.com, score by term density
$ranker = new SearchResultRanker();
$docs = $ranker->rank($docs, 'php 8.4 features', [
    'boost_domains' => ['php.net', 'github.com'],
    'recency_weight' => 0.3,
]);

foreach ($docs as $doc) {
    echo "{$doc['title']} ({$doc['url']}) — score: {$doc['score']}
";
}
