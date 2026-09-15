# Temant HTTP Core

Temant HTTP Core is a [PSR-7](https://www.php-fig.org/psr/psr-7/), [PSR-17](https://www.php-fig.org/psr/psr-17/), and [PSR-15](https://www.php-fig.org/psr/psr-15/) implementation built for PHP 8.5. It gives you immutable request, response, stream, URI, and uploaded-file objects, plus a middleware dispatcher to run them through, with no dependencies beyond the PSR interfaces themselves.

Unlike most PSR-7 packages still targeting PHP 7-era baselines, this one is written *for* PHP 8.5: every `with*()` method is a one-liner built on the new `clone with` syntax, and the properties behind it are all `readonly`. The result is less code, fewer places for a bug to hide, and a measurably fast implementation (see [Performance](#performance) below).

---

## Features

- Fully compliant with [PSR-7](https://www.php-fig.org/psr/psr-7/), [PSR-17](https://www.php-fig.org/psr/psr-17/), and [PSR-15](https://www.php-fig.org/psr/psr-15/)
- Truly immutable: every value object property is `readonly`
- `HttpMethod` and `HttpStatus` enums for working with verbs and status codes without magic strings/ints
- `MiddlewareDispatcher` runs a PSR-15 middleware queue immutably - safe even if a middleware calls the next handler more than once
- Benchmarked faster than Guzzle, Nyholm, Laminas Diactoros, and Slim on URI construction (see [Performance](#performance))
- Tested (PHPUnit) and statically analyzed at PHPStan `level: max`
- Zero runtime dependencies beyond the `psr/*` interface packages (`http-message`, `http-factory`, `http-server-handler`, `http-server-middleware`)

---

## Requirements

- PHP `8.5` or higher
- `psr/http-message` ^2.0
- `psr/http-factory` ^1.1
- `psr/http-server-handler` ^1.0
- `psr/http-server-middleware` ^1.0

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
use Temant\HttpCore\Enum\HttpMethod;
use Temant\HttpCore\Enum\HttpStatus;
use Temant\HttpCore\Message\Request;
use Temant\HttpCore\Message\Response;

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

### Middleware (PSR-15)

`MiddlewareDispatcher` runs a fixed queue of [PSR-15](https://www.php-fig.org/psr/psr-15/) `MiddlewareInterface` middleware ahead of a final fallback handler (typically your router's dispatch handler):

```php
use Temant\HttpCore\Middleware\MiddlewareDispatcher;
use Temant\HttpCore\Factory\ResponseFactory;

$dispatcher = new MiddlewareDispatcher(
    [$authMiddleware, $loggingMiddleware],
    $router, // any Psr\Http\Server\RequestHandlerInterface
);

$response = $dispatcher->handle($serverRequest);
```

Each middleware gets a fresh dispatcher covering only the middleware still after it, rather than one shared, mutable "advance to the next one" handler - so calling `$handler->handle($request)` more than once inside a middleware (a retry, or fanning a request out) re-runs the same remaining queue correctly every time, instead of silently skipping whatever a previous call already consumed. There's no default fallback handler; forgetting to supply a real one is a bug worth a loud constructor-time requirement, not something worth silently defaulting to a 404.

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
- `Request`'s constructor - this library's single hottest call site -
  checks the method against a small lookup table of the nine standard
  verbs before ever reaching a regex, and inlines the "does this Host
  header already exist" scan instead of going through extra function
  calls. Neither `preg_match()` nor a helper-method call happens at all
  for the overwhelmingly common case of `new Request('GET', $uri)`.
- The method is validated (PSR-7 requires throwing on an invalid one) but
  never case-normalized. `psr/http-message`'s own docblock for
  `withMethod()` says implementations "SHOULD NOT modify the given
  string" since method names are case-sensitive - so the `strtoupper()`
  call this used to have wasn't just extra work, it was a spec deviation.
  Removing it fixed correctness and performance at the same time.

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
  Request: withHeader()           100,000 ops      330.7 ns/op     3,023,827 ops/sec   1.00x
  Response: construct + getReasonPhrase()    100,000 ops      400.9 ns/op     2,494,419 ops/sec   1.21x
  Request: construct              100,000 ops      426.1 ns/op     2,346,835 ops/sec   1.29x
  ...

=== Comparison: Temant HttpCore (this library), Guzzle PSR-7, Nyholm PSR-7, Laminas Diactoros, Slim PSR-7 ===

createUri()
-----------
  Temant HttpCore (this library)    100,000 ops      474.4 ns/op     2,108,113 ops/sec   1.00x
  Nyholm PSR-7                    100,000 ops      675.5 ns/op     1,480,376 ops/sec   1.42x
  Slim PSR-7                      100,000 ops     1034.9 ns/op       966,289 ops/sec   2.18x
  Laminas Diactoros               100,000 ops     1970.4 ns/op       507,503 ops/sec   4.15x
  Guzzle PSR-7                    100,000 ops     2794.3 ns/op       357,866 ops/sec   5.89x

createRequest()
---------------
  Nyholm PSR-7                    100,000 ops      301.6 ns/op     3,315,207 ops/sec   1.00x
  Temant HttpCore (this library)    100,000 ops      369.7 ns/op     2,704,784 ops/sec   1.23x
  Guzzle PSR-7                    100,000 ops      548.9 ns/op     1,821,946 ops/sec   1.82x
  Laminas Diactoros               100,000 ops      755.0 ns/op     1,324,507 ops/sec   2.50x
  Slim PSR-7                      100,000 ops     1397.7 ns/op       715,439 ops/sec   4.63x

withHeader() on the resulting request
-------------------------------------
  Nyholm PSR-7                    100,000 ops      319.5 ns/op     3,129,615 ops/sec   1.00x
  Temant HttpCore (this library)    100,000 ops      334.7 ns/op     2,987,644 ops/sec   1.05x
  Guzzle PSR-7                    100,000 ops      502.1 ns/op     1,991,791 ops/sec   1.57x
  Laminas Diactoros               100,000 ops      538.8 ns/op     1,855,887 ops/sec   1.69x
  Slim PSR-7                      100,000 ops      786.5 ns/op     1,271,375 ops/sec   2.46x
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

We're fastest on URI construction, essentially tied with Nyholm on header
manipulation, and close 2nd on request construction. That remaining
`createRequest()` gap is a known, deliberate trade rather than an
unexplained one: Nyholm's `Request` constructor does not validate the
HTTP method at all - any string, including a genuinely invalid one, is
accepted as-is. This library validates the method against RFC 7230's
token grammar and throws `InvalidArgumentException` for anything else,
per PSR-7's `@throws` contract on `withMethod()`. That validation is
real work Nyholm simply skips, and we'd rather keep it than shave off
the last fraction of a microsecond. (We used to also normalize the
method's case, which cost even more - but that turned out to be a PSR-7
deviation, not a feature, so it's gone; see above.) We don't claim
blanket "fastest on the market" either - that's a moving target and
depends on your workload - but every number above came from the
benchmark in this repo, which you can run yourself
and check against whatever else you're evaluating.

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

This library is compatible with the official `http-interop/http-factory-tests`.

---

## Project Structure

```
Src/
  Message/                  PSR-7 message value objects (Temant\HttpCore\Message\)
    Message.php               Abstract base shared by Request and Response
    Request.php
    Response.php
    ServerRequest.php
    Stream.php
    Uri.php
    UploadedFile.php
  Factory/                  PSR-17 factories (Temant\HttpCore\Factory\)
  Middleware/               PSR-15 middleware dispatcher (Temant\HttpCore\Middleware\)
  Enum/                     HttpMethod/HttpStatus convenience enums (Temant\HttpCore\Enum\)
  Exceptions/                Typed Stream exceptions (Temant\HttpCore\Exceptions\)
Tests/                      PHPUnit test suite (PSR-4: Temant\HttpCore\Tests\), mirrors Src/
composer.json
```

---

## Contributing

Contributions are welcome. Please ensure that new code includes relevant tests. Bug reports and improvement suggestions are appreciated.

---

## License

Temant HTTP Core is open-sourced software licensed under the MIT license.

---
