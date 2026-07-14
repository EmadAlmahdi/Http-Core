<?php

declare(strict_types=1);

namespace Temant\HttpCore\Benchmarks;

use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;

/**
 * A benchmark subject: this library, or a competing PSR-7 implementation.
 *
 * Every adapter is measured through its own official PSR-17 factories
 * (never a raw `new Request(...)` constructor call) so the comparison in
 * {@see run.php} reflects how a real consumer would actually use each
 * library, not an implementation detail of its constructor signature.
 *
 * This file is `require`'d directly rather than PSR-4 autoloaded, since
 * it deliberately holds several small, throwaway classes in one place.
 */
interface Adapter
{
    /** A short, human-readable name for report output. */
    public function name(): string;

    public function uriFactory(): UriFactoryInterface;

    public function requestFactory(): RequestFactoryInterface;
}

final class TemantAdapter implements Adapter
{
    public function name(): string
    {
        return 'Temant HttpCore (this library)';
    }

    public function uriFactory(): UriFactoryInterface
    {
        return new \Temant\HttpCore\Factory\UriFactory();
    }

    public function requestFactory(): RequestFactoryInterface
    {
        return new \Temant\HttpCore\Factory\RequestFactory();
    }
}

final class GuzzleAdapter implements Adapter
{
    public function name(): string
    {
        return 'Guzzle PSR-7';
    }

    public function uriFactory(): UriFactoryInterface
    {
        return new \GuzzleHttp\Psr7\HttpFactory();
    }

    public function requestFactory(): RequestFactoryInterface
    {
        return new \GuzzleHttp\Psr7\HttpFactory();
    }
}

final class NyholmAdapter implements Adapter
{
    public function name(): string
    {
        return 'Nyholm PSR-7';
    }

    public function uriFactory(): UriFactoryInterface
    {
        return new \Nyholm\Psr7\Factory\Psr17Factory();
    }

    public function requestFactory(): RequestFactoryInterface
    {
        return new \Nyholm\Psr7\Factory\Psr17Factory();
    }
}

final class LaminasAdapter implements Adapter
{
    public function name(): string
    {
        return 'Laminas Diactoros';
    }

    public function uriFactory(): UriFactoryInterface
    {
        return new \Laminas\Diactoros\UriFactory();
    }

    public function requestFactory(): RequestFactoryInterface
    {
        return new \Laminas\Diactoros\RequestFactory();
    }
}

final class SlimAdapter implements Adapter
{
    public function name(): string
    {
        return 'Slim PSR-7';
    }

    public function uriFactory(): UriFactoryInterface
    {
        return new \Slim\Psr7\Factory\UriFactory();
    }

    public function requestFactory(): RequestFactoryInterface
    {
        return new \Slim\Psr7\Factory\RequestFactory();
    }
}

/**
 * All known adapters whose backing library is actually installed.
 *
 * The competing libraries are dev-only comparison dependencies (see
 * `require-dev`), so this degrades gracefully to "just us" if the
 * comparison libraries aren't installed for whatever reason - it never
 * fails the benchmark run.
 *
 * @return Adapter[]
 */
function availableAdapters(): array
{
    $candidates = [
        TemantAdapter::class => \Temant\HttpCore\Factory\RequestFactory::class,
        GuzzleAdapter::class => \GuzzleHttp\Psr7\HttpFactory::class,
        NyholmAdapter::class => \Nyholm\Psr7\Factory\Psr17Factory::class,
        LaminasAdapter::class => \Laminas\Diactoros\RequestFactory::class,
        SlimAdapter::class => \Slim\Psr7\Factory\RequestFactory::class,
    ];

    $adapters = [];
    foreach ($candidates as $adapterClass => $requiredClass) {
        if (class_exists($requiredClass)) {
            $adapters[] = new $adapterClass();
        }
    }

    return $adapters;
}
