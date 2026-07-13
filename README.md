# Temant HTTP Core

Temant HTTP Core is a [PSR-7](https://www.php-fig.org/psr/psr-7/) and [PSR-17](https://www.php-fig.org/psr/psr-17/) implementation built for PHP 8.5. It gives you immutable request, response, stream, URI, and uploaded-file objects with no dependencies beyond the PSR interfaces themselves.

Unlike most PSR-7 packages still targeting PHP 7-era baselines, this one is written *for* PHP 8.5: URI parsing is delegated to the native `ext-uri` extension, every `with*()` method is a one-liner built on the new `clone with` syntax, and the properties behind it are all `readonly`. The result is less code, fewer places for a bug to hide, and a measurably fast implementation (see [Performance](#performance) below).

---

## Features

- Fully compliant with [PSR-7](https://www.php-fig.org/psr/psr-7/) and [PSR-17](https://www.php-fig.org/psr/psr-17/)
- Truly immutable: every value object property is `readonly`
- URI parsing backed by PHP 8.5's native `Uri\Rfc3986\Uri` parser instead of `parse_url()`
- `HttpMethod` and `HttpStatus` enums for working with verbs and status codes without magic strings/ints
- Tested (PHPUnit) and statically analyzed at PHPStan `level: max`
- Zero runtime dependencies beyond `psr/http-message` and `psr/http-factory`

---

## Requirements

- PHP `8.5` or higher, with the `uri` extension enabled (bundled with PHP 8.5)
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
objects on hot paths. Two concrete examples:

- `Uri`'s constructor uses the native `ext-uri` parser once, to split a full
  URI string correctly (better than `parse_url()` at handling edge cases
  like IPv6 literals or malformed input). Every `with*()` call after that is
  a cheap string operation on an already-parsed value - it does **not**
  allocate a new native URI object per call.
- `Uri::withPath()` skips `rawurlencode()` entirely when the path is already
  made up of unreserved characters (the common case for real API paths like
  `/api/v1/users/123`), instead of always encoding and comparing.

A reproducible benchmark is included - it's a plain script, not a test, so
you can run it yourself and see actual numbers for your machine:

```bash
php benchmarks/run.php
```

Sample output (your numbers will vary by hardware):

```
Uri: construct from string                    200,000 ops    3824.4 ns/op       261,480 ops/sec
Uri: construct + with*() chain                200,000 ops    4588.9 ns/op       217,915 ops/sec
Uri: withPath() clean path (fast path)        500,000 ops    3203.7 ns/op       312,139 ops/sec
Uri: withPath() needing encoding              500,000 ops    3626.3 ns/op       275,765 ops/sec
Request: construct                            200,000 ops    4242.4 ns/op       235,714 ops/sec
Request: withHeader()                         200,000 ops    1280.4 ns/op       780,978 ops/sec
Response: construct + getReasonPhrase()       200,000 ops    3792.4 ns/op       263,685 ops/sec
```

We don't claim to be "the fastest PSR-7 implementation on the market" -
that's a moving target and depends heavily on your workload. What we do
claim is that every hot path here has been looked at, the obviously wasteful
work has been removed, and the benchmark above is exactly how we checked -
so you can check it too, and compare it against whatever else you're
evaluating.

---

## Why PHP 8.5-only

This library intentionally does not support older PHP versions. Supporting
8.0-8.4 as well would mean giving up `readonly` + `clone with` (which is
what makes every `with*()` method a one-liner instead of a
clone-then-mutate block) and the native `ext-uri` parser, in exchange for
compatibility this project doesn't need. If you're on an older PHP version,
mature alternatives like `nyholm/psr7` or `guzzlehttp/psr7` remain excellent
choices.

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
php benchmarks/run.php
```

This library is compatible with the official `http-interop/http-factory-tests`.

---

## Project Structure

```
Src/           Library source (PSR-4: Temant\HttpCore\)
Tests/         PHPUnit test suite (PSR-4: Temant\HttpCore\Tests\)
benchmarks/    Standalone performance benchmark (not part of any autoload path)
composer.json
```

---

## Contributing

Contributions are welcome. Please ensure that new code includes relevant tests. Bug reports and improvement suggestions are appreciated.

---

## License

Temant HTTP Core is open-sourced software licensed under the MIT license.

---
