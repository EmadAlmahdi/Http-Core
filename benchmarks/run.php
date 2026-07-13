<?php

declare(strict_types=1);

/**
 * Standalone throughput benchmark for the hot paths of this library:
 * URI parsing/mutation, and Request/Response construction and header
 * manipulation. Not part of the test suite or any autoload path -
 * run it directly with `php benchmarks/run.php` after `composer install`.
 *
 * This exists so performance claims about this library are backed by a
 * number you can reproduce on your own machine, not a guess.
 */

require __DIR__ . '/../vendor/autoload.php';

use Temant\HttpCore\Request;
use Temant\HttpCore\Response;
use Temant\HttpCore\Uri;

/**
 * @param callable(): void $fn
 */
function bench(string $label, int $iterations, callable $fn): void
{
    // Warm up (opcache/JIT, and to avoid measuring lazy first-call costs).
    for ($i = 0; $i < 1_000; $i++) {
        $fn();
    }

    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $fn();
    }
    $elapsedNs = hrtime(true) - $start;

    $perOpNs = $elapsedNs / $iterations;
    $opsPerSec = 1_000_000_000 / $perOpNs;

    printf(
        "%-42s %10s ops  %8.1f ns/op  %12s ops/sec\n",
        $label,
        number_format($iterations),
        $perOpNs,
        number_format($opsPerSec, 0)
    );
}

echo "PHP " . PHP_VERSION . " | ext-uri: " . (extension_loaded('uri') ? 'yes' : 'no') . "\n";
echo str_repeat('-', 100) . "\n";

bench('Uri: construct from string', 200_000, function (): void {
    new Uri('https://user:pass@example.com:8443/api/v1/users/123?sort=name&order=asc#top');
});

bench('Uri: construct + with*() chain', 200_000, function (): void {
    new Uri('https://example.com/api')
        ->withPath('/api/v2/users')
        ->withQuery('page=2')
        ->withFragment('results');
});

bench('Uri: withPath() clean path (fast path)', 500_000, function (): void {
    new Uri('https://example.com')->withPath('/api/v1/users/123');
});

bench('Uri: withPath() needing encoding', 500_000, function (): void {
    new Uri('https://example.com')->withPath('/a path/with spaces');
});

$baseUri = new Uri('https://example.com/api/v1/users');

bench('Request: construct', 200_000, function () use ($baseUri): void {
    new Request('GET', $baseUri);
});

$request = new Request('GET', $baseUri, ['Accept' => ['application/json']]);

bench('Request: withHeader()', 200_000, function () use ($request): void {
    $request->withHeader('X-Request-Id', 'abc-123');
});

bench('Request: withHeader() x5 chained', 100_000, function () use ($request): void {
    $request
        ->withHeader('X-A', '1')
        ->withHeader('X-B', '2')
        ->withHeader('X-C', '3')
        ->withHeader('X-D', '4')
        ->withHeader('X-E', '5');
});

bench('Response: construct + getReasonPhrase()', 200_000, function (): void {
    new Response(404)->getReasonPhrase();
});
