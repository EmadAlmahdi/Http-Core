# Temant HTTP Core

Temant HTTP Core is a [PSR-7](https://www.php-fig.org/psr/psr-7/) and [PSR-17](https://www.php-fig.org/psr/psr-17/) implementation built for PHP 8.5. It gives you immutable request, response, stream, URI, and uploaded-file objects with no dependencies beyond the PSR interfaces themselves.

Unlike most PSR-7 packages still targeting PHP 7-era baselines, this one is written *for* PHP 8.5: every `with*()` method is a one-liner built on the new `clone with` syntax, and the properties behind it are all `readonly`. The result is less code, fewer places for a bug to hide, and a measurably fast implementation (see [Performance](#performance) below).

---

## Features

- Fully compliant with [PSR-7](https://www.php-fig.org/psr/psr-7/) and [PSR-17](https://www.php-fig.org/psr/psr-17/)
- Truly immutable: every value object property is `readonly`
- `HttpMethod` and `HttpStatus` enums for working with verbs and status codes without magic strings/ints
- Benchmarked faster than Guzzle, Nyholm, Laminas Diactoros, and Slim on URI construction (see [Performance](#performance))
- Tested (PHPUnit) and statically analyzed at PHPStan `level: max`
- Zero runtime dependencies beyond `psr/http-message` and `psr/http-factory`

---

## Requirements

- PHP `8.5` or higher
- `psr/http-message` ^2.0
- `psr/http-factory` ^1.1

---

## Installation

Install via Composer:

```bash
composer require temant/http-core
```

---

## Usage

### Basic request/response

```php
<?php

declare(strict_types=1);

require_once __DIR__ . "/vendor/autoload.php";

use Temant\HttpCore\Factory\RequestFactory;
use Temant\HttpCore\Factory\ResponseFactory;
use Temant\HttpCore\Factory\StreamFactory;

$request = new RequestFactory()
    ->createRequest('GET', 'https://example.com');

$stream = new StreamFactory()
    ->createStream('Hello Temant');

$response = new ResponseFactory()
    ->createResponse()
    ->withBody($stream);

echo $response->getStatusCode(); // 200
echo $response->getBody();       // Hello Temant
```

### Headers

Every message is immutable, so `with*()` calls return a new instance rather
than mutating the original:

```php
$request = $request
    ->withHeader('Accept', 'application/json')
    ->withAddedHeader('Accept', 'application/vnd.api+json');

$request->getHeaderLine('Accept'); // "application/json, application/vnd.api+json"
```

### `HttpMethod` and `HttpStatus` enums

These are additive - every method/factory still accepts plain strings and
ints as PSR-7 requires - but they read better and give you IDE autocomplete
plus a couple of useful classification helpers:

```php
use Temant\HttpCore\HttpMethod;
use Temant\HttpCore\HttpStatus;
use Temant\HttpCore\Request;
use Temant\HttpCore\Response;

$request = new Request(HttpMethod::Post, $uri);

HttpMethod::Get->isSafe();        // true - GET never changes server state
HttpMethod::Put->isIdempotent();  // true - calling it twice has the same effect as once

$response = new Response(HttpStatus::NotFound);
$response->getReasonPhrase(); // "Not Found"

HttpStatus::NotFound->isClientError(); // true
```

### Server requests, uploaded files, and streams

```php
use Temant\HttpCore\Factory\ServerRequestFactory;

// Builds a ServerRequest from $_SERVER/$_GET/$_POST/$_COOKIE/$_FILES
$serverRequest = ServerRequestFactory::fromGlobals();

$serverRequest->getQueryParams();
$serverRequest->getUploadedFiles();
$serverRequest = $serverRequest->withAttribute('user_id', 42);

foreach ($serverRequest->getUploadedFiles() as $file) {
    $file->moveTo('/var/uploads/' . $file->getClientFilename());
}
```

---

## Performance

This library favors plain, C-optimized string operations over allocating
objects on hot paths, and avoids doing work nobody asked for. Some concrete
examples - each one is here because it was measured, not assumed:

- `Uri`'s constructor uses `parse_url()`, not PHP 8.5's native `Uri\Rfc3986\Uri`
  extension class. That native parser was tried first, since it's strictly
  more RFC-3986-correct - but benchmarking showed pulling parsed components
  back out through its object API costs about 5.5x what `parse_url()` costs
  end to end, for edge cases most real URLs never hit. For a value object
  constructed this often, that wasn't a trade worth making. See the `Uri`
  class docblock for the full measurement.
- `Uri::withPath()` skips `rawurlencode()` entirely when the path is already
  made up of unreserved characters (the common case for real API paths like
  `/api/v1/users/123`), instead of always encoding and comparing.
- A `Request`/`Response` body stream is never opened until something calls
  `getBody()`. Most requests never have their body read at all (a `GET`
  has none), so paying for an `fopen('php://temp', ...)` call up front on
  every single object is wasted work - it's created lazily, once, on
  first access.

### Running the benchmark

A reproducible benchmark is included - it's a plain script, not a test, so
you can run it yourself and see actual numbers for your machine:

```bash
composer bench
# or
php benchmarks/run.php
```

It prints this library's own hot-path numbers, and - if you've installed
dev dependencies - a side-by-side comparison against Guzzle, Nyholm,
Laminas Diactoros, and Slim, each exercised through its own official PSR-17
factory (never a raw constructor call, so it's a fair, real-world-shaped
comparison). Sample output (your numbers will vary by hardware, and are
sorted fastest-first):

```
=== Temant HttpCore: internal hot paths ===
  Request: withHeader()           100,000 ops      361.5 ns/op     2,765,961 ops/sec   1.00x
  Response: construct + getReasonPhrase()    100,000 ops      414.6 ns/op     2,411,840 ops/sec   1.15x
  Request: construct              100,000 ops      521.6 ns/op     1,917,321 ops/sec   1.44x
  ...

=== Comparison: Temant HttpCore (this library), Guzzle PSR-7, Nyholm PSR-7, Laminas Diactoros, Slim PSR-7 ===

createUri()
-----------
  Temant HttpCore (this library)    100,000 ops      457.0 ns/op     2,188,344 ops/sec   1.00x
  Nyholm PSR-7                    100,000 ops      667.7 ns/op     1,497,660 ops/sec   1.46x
  Slim PSR-7                      100,000 ops     1058.0 ns/op       945,212 ops/sec   2.32x
  Laminas Diactoros               100,000 ops     1768.0 ns/op       565,623 ops/sec   3.87x
  Guzzle PSR-7                    100,000 ops     2776.5 ns/op       360,168 ops/sec   6.08x

createRequest()
---------------
  Nyholm PSR-7                    100,000 ops      301.1 ns/op     3,321,318 ops/sec   1.00x
  Temant HttpCore (this library)    100,000 ops      523.8 ns/op     1,909,011 ops/sec   1.74x
  Guzzle PSR-7                    100,000 ops      569.2 ns/op     1,756,803 ops/sec   1.89x
  Laminas Diactoros               100,000 ops      753.2 ns/op     1,327,735 ops/sec   2.50x
  Slim PSR-7                      100,000 ops     1444.0 ns/op       692,506 ops/sec   4.80x
```

The benchmark runner (`Temant\HttpCore\Benchmarks\Benchmark`) is a small,
dependency-free, standalone class - copy it into another project's
`benchmarks/` directory and it works as-is. It also checks for Xdebug and
prints a warning if an active mode (step debugging, coverage, profiling)
would be adding overhead to the numbers, since that's an easy thing to
forget and it silently invalidates any comparison:

```
!! Xdebug is active (mode: debug) - timings below include its overhead and
   are not representative. Re-run with XDEBUG_MODE=off for real numbers.
```

We're fastest on URI construction and competitive (2nd, close behind
Nyholm) on request construction and header manipulation - we haven't
pinned down exactly where that remaining gap comes from yet, so we're not
going to guess at a reason here. We don't claim blanket "fastest on the
market" either - that's a moving target and depends on your workload - but
every number above came from the benchmark in this repo, which you can run
yourself and check against whatever else you're evaluating.

---

## Why PHP 8.5-only

This library intentionally does not support older PHP versions. Supporting
8.0-8.4 as well would mean giving up `readonly` + `clone with` - which is
what makes every `with*()` method a one-liner instead of a
clone-then-mutate block - in exchange for compatibility this project
doesn't need. If you're on an older PHP version, mature alternatives like
`nyholm/psr7` or `guzzlehttp/psr7` remain excellent choices.

---

## Development

Run the test suite:

```bash
composer test
```

Run static analysis (PHPStan, `level: max`):

```bash
composer analyse
```

Run the performance benchmark:

```bash
composer bench
```

This library is compatible with the official `http-interop/http-factory-tests`.

---

## Project Structure

```
Src/                        Library source (PSR-4: Temant\HttpCore\)
Tests/                      PHPUnit test suite (PSR-4: Temant\HttpCore\Tests\)
benchmarks/                 Performance benchmark (PSR-4: Temant\HttpCore\Benchmarks\)
  Benchmark.php             Reusable, dependency-free microbenchmark runner
  BenchmarkResult.php       Result value object (ns/op, ops/sec, bytes/op)
  adapters.php              Guzzle/Nyholm/Laminas/Slim comparison adapters
  run.php                   Entry point (composer bench / php benchmarks/run.php)
composer.json
```

---

## Contributing

Contributions are welcome. Please ensure that new code includes relevant tests. Bug reports and improvement suggestions are appreciated.

---

## License

Temant HTTP Core is open-sourced software licensed under the MIT license.

---
