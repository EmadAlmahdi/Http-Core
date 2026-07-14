<?php

declare(strict_types=1);

/**
 * Performance benchmark for this library's hot paths, plus an optional
 * side-by-side comparison against other PSR-7 implementations (Guzzle,
 * Nyholm, Laminas Diactoros, Slim) via their own official PSR-17 factories.
 *
 * This is a plain script, not a test - nothing here asserts or fails a
 * build. Run it directly:
 *
 *     composer bench
 *     # or
 *     php benchmarks/run.php
 *
 * Xdebug adds substantial per-call overhead in any active mode (step
 * debugging, coverage, profiling) and will silently produce misleading
 * numbers if left on; this script detects that and warns instead of
 * quietly reporting bad data. If you see the warning, re-run with
 * XDEBUG_MODE=off.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/adapters.php';

use Temant\HttpCore\Benchmarks\Benchmark;
use Temant\HttpCore\Benchmarks\BenchmarkResult;
use Temant\HttpCore\Request;
use Temant\HttpCore\Response;
use Temant\HttpCore\Uri;

use function Temant\HttpCore\Benchmarks\availableAdapters;

const ITERATIONS = 100_000;

echo 'PHP ' . PHP_VERSION . ' | ext-uri: ' . (extension_loaded('uri') ? 'yes' : 'no') . "\n";

if ($warning = Benchmark::xdebugWarning()) {
    echo "\n!! {$warning}\n";
}

// ---------------------------------------------------------------------
// Part 1: this library's own hot paths.
// ---------------------------------------------------------------------

echo "\n=== Temant HttpCore: internal hot paths ===\n";

$internal = new Benchmark();

$internal->run('Uri: construct from string', ITERATIONS, function (): void {
    new Uri('https://user:pass@example.com:8443/api/v1/users/123?sort=name&order=asc#top');
});

$internal->run('Uri: construct + with*() chain', ITERATIONS, function (): void {
    new Uri('https://example.com/api')
        ->withPath('/api/v2/users')
        ->withQuery('page=2')
        ->withFragment('results');
});

$internal->run('Uri: withPath() clean path (fast path)', ITERATIONS, function (): void {
    new Uri('https://example.com')->withPath('/api/v1/users/123');
});

$internal->run('Uri: withPath() needing encoding', ITERATIONS, function (): void {
    new Uri('https://example.com')->withPath('/a path/with spaces');
});

$baseUri = new Uri('https://example.com/api/v1/users');

$internal->run('Request: construct', ITERATIONS, function () use ($baseUri): void {
    new Request('GET', $baseUri);
});

$request = new Request('GET', $baseUri, ['Accept' => ['application/json']]);

$internal->run('Request: withHeader()', ITERATIONS, function () use ($request): void {
    $request->withHeader('X-Request-Id', 'abc-123');
});

$internal->run('Request: withHeader() x5 chained', ITERATIONS, function () use ($request): void {
    $request
        ->withHeader('X-A', '1')
        ->withHeader('X-B', '2')
        ->withHeader('X-C', '3')
        ->withHeader('X-D', '4')
        ->withHeader('X-E', '5');
});

$internal->run('Response: construct + getReasonPhrase()', ITERATIONS, function (): void {
    new Response(404)->getReasonPhrase();
});

Benchmark::reportTable($internal->results());

// ---------------------------------------------------------------------
// Part 2: side-by-side comparison against other PSR-7 implementations,
// each exercised through its own official PSR-17 factory.
// ---------------------------------------------------------------------

$adapters = availableAdapters();

if (count($adapters) <= 1) {
    echo "\n(Comparison libraries not installed - run `composer install` with dev dependencies to compare against Guzzle/Nyholm/Laminas/Slim.)\n";
    exit;
}

echo "\n\n=== Comparison: " . implode(', ', array_map(static fn($a) => $a->name(), $adapters)) . " ===\n";

/** @var array<string, BenchmarkResult[]> $groups */
$groups = [
    'createUri()' => [],
    'createRequest()' => [],
    'withHeader() on the resulting request' => [],
];

foreach ($adapters as $adapter) {
    $bench = new Benchmark();
    $uriFactory = $adapter->uriFactory();
    $requestFactory = $adapter->requestFactory();

    $groups['createUri()'][] = $bench->run($adapter->name(), ITERATIONS, function () use ($uriFactory): void {
        $uriFactory->createUri('https://example.com/api/v1/users/123?sort=name#top');
    });

    $uri = $uriFactory->createUri('https://example.com/api/v1/users');

    $groups['createRequest()'][] = $bench->run($adapter->name(), ITERATIONS, function () use ($requestFactory, $uri): void {
        $requestFactory->createRequest('GET', $uri);
    });

    $builtRequest = $requestFactory->createRequest('GET', $uri);

    $groups['withHeader() on the resulting request'][] = $bench->run(
        $adapter->name(),
        ITERATIONS,
        function () use ($builtRequest): void {
            $builtRequest->withHeader('X-Request-Id', 'abc-123');
        }
    );
}

Benchmark::reportGroups($groups);

echo "\nLower ns/op and higher ops/sec is better. The rightmost column is speed relative to the fastest in each group.\n";
