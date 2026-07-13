<?php

declare(strict_types=1); 

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;

interface Adapter
{
    public static function name(): string;

    public static function uri(string $uri): UriInterface;

    public static function request(
        string $method,
        UriInterface $uri
    ): RequestInterface;
} 

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Uri;

final class GuzzleAdapter implements Adapter
{
    public static function name(): string
    {
        return 'Guzzle PSR-7';
    }

    public static function uri(string $uri): Uri
    {
        return new Uri($uri);
    }

    public static function request(
        string $method,
        UriInterface $uri
    ): Request {
        return new Request($method, $uri);
    }
}

<?php

namespace Bench;

use Nyholm\Psr7\Request;
use Nyholm\Psr7\Uri;

final class NyholmAdapter implements Adapter
{
    public static function name(): string
    {
        return 'Nyholm PSR-7';
    }

    public static function uri(string $uri): Uri
    {
        return new Uri($uri);
    }

    public static function request(
        string $method,
        UriInterface $uri
    ): Request {
        return new Request($method, $uri);
    }
}

<?php

namespace Bench;

use Laminas\Diactoros\Request;
use Laminas\Diactoros\Uri;

final class LaminasAdapter implements Adapter
{
    public static function name(): string
    {
        return 'Laminas Diactoros';
    }

    public static function uri(string $uri): Uri
    {
        return new Uri($uri);
    }

    public static function request(
        string $method,
        UriInterface $uri
    ): Request {
        return new Request($uri, $method);
    }
}

Slim adapter