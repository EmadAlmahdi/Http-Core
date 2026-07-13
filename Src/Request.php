<?php

declare(strict_types=1);

namespace Temant\HttpCore;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use InvalidArgumentException;

/**
 * PSR-7 HTTP Request implementation.
 *
 * Represents an outgoing HTTP request: method, target URI, headers, body,
 * and protocol version. If the given headers don't already include a
 * `Host` (checked case-insensitively), one is derived from the URI and
 * added automatically - both here and whenever {@see withUri()} swaps in a
 * new URI - matching what a real HTTP client does when it puts a request
 * on the wire.
 */
class Request extends Message implements RequestInterface
{
    private const string METHOD_PATTERN = '/^[!#$%&\'*+.^_`|~0-9a-z-]+$/i';
    private const string REQUEST_TARGET_PATTERN = '/\s/';

    private readonly string $method;
    private readonly UriInterface $uri;
    private readonly string $requestTarget;

    /**
     * @param string|HttpMethod $method HTTP request method (e.g., GET, POST)
     * @param UriInterface $uri URI of the request
     * @param array<string, array<string>> $headers Request headers
     * @param StreamInterface|null $body Request body
     * @param string $protocolVersion HTTP protocol version
     *
     * @throws InvalidArgumentException For invalid method or protocol version
     */
    public function __construct(
        string|HttpMethod $method,
        UriInterface $uri,
        array $headers = [],
        ?StreamInterface $body = null,
        string $protocolVersion = '1.1'
    ) {
        $method = $method instanceof HttpMethod ? $method->value : $method;
        $this->validateMethod($method);

        $host = $uri->getHost();
        if ($host !== '' && !self::hasHeaderNamed($headers, 'host')) {
            $headers['Host'] = $this->hostHeaderValue($uri);
        }

        parent::__construct($headers, $body, $protocolVersion);

        $this->method = strtoupper($method);
        $this->uri = $uri;
        $this->requestTarget = '';
    }

    #[\Override]
    public function getRequestTarget(): string
    {
        /** @phpstan-ignore notIdentical.alwaysFalse (phpstan doesn't yet track PHP 8.5 clone-with reinitialization of readonly properties) */
        if ($this->requestTarget !== '') {
            return $this->requestTarget;
        }

        $target = $this->uri->getPath();
        if ($target === '') {
            $target = '/';
        }

        $query = $this->uri->getQuery();
        if ($query !== '') {
            $target .= "?{$query}";
        }

        return $target;
    }

    #[\Override]
    public function withRequestTarget(string $requestTarget): static
    {
        if (preg_match(self::REQUEST_TARGET_PATTERN, $requestTarget)) {
            throw new InvalidArgumentException('Request target cannot contain whitespace');
        }

        return clone($this, ['requestTarget' => $requestTarget]);
    }

    #[\Override]
    public function getMethod(): string
    {
        return $this->method;
    }

    #[\Override]
    public function withMethod(string $method): static
    {
        $this->validateMethod($method);
        $method = strtoupper($method);

        if ($this->method === $method) {
            return $this;
        }

        return clone($this, ['method' => $method]);
    }

    #[\Override]
    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    #[\Override]
    public function withUri(UriInterface $uri, bool $preserveHost = false): static
    {
        if ($uri === $this->uri) {
            return $this;
        }

        $new = clone($this, ['uri' => $uri]);

        if ($preserveHost && $this->hasHeader('Host')) {
            return $new;
        }

        $host = $uri->getHost();
        return $host !== '' ? $new->withHeader('Host', $this->hostHeaderValue($uri)) : $new;
    }

    /**
     * @return string[]
     */
    private function hostHeaderValue(UriInterface $uri): array
    {
        $host = $uri->getHost();
        $port = $uri->getPort();
        return [$port !== null ? "{$host}:{$port}" : $host];
    }

    /**
     * Case-insensitive check for whether a raw (not yet normalized) headers
     * array already has an entry for the given name.
     *
     * @param array<array-key, mixed> $headers
     */
    private static function hasHeaderNamed(array $headers, string $name): bool
    {
        foreach (array_keys($headers) as $key) {
            if (strtolower((string) $key) === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validates the HTTP method
     *
     * @throws InvalidArgumentException If method is invalid
     */
    private function validateMethod(string $method): void
    {
        if ($method === '') {
            throw new InvalidArgumentException('HTTP method cannot be empty');
        }

        if (!preg_match(self::METHOD_PATTERN, $method)) {
            throw new InvalidArgumentException("Invalid HTTP method: {$method}");
        }
    }
}