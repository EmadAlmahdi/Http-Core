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
 *
 * The method string is validated against RFC 7230's token grammar (and
 * {@see withMethod()}/the constructor throw `InvalidArgumentException` for
 * anything that doesn't match, per PSR-7's contract) but is otherwise
 * stored exactly as given - PSR-7's `RequestInterface::withMethod()` is
 * explicit that "HTTP method names are case-sensitive and thus
 * implementations SHOULD NOT modify the given string", so `new
 * Request('get', $uri)` keeps `getMethod() === 'get'` rather than
 * silently uppercasing it.
 */
class Request extends Message implements RequestInterface
{
    private const string METHOD_PATTERN = '/^[!#$%&\'*+.^_`|~0-9a-z-]+$/i';
    private const string REQUEST_TARGET_PATTERN = '/\s/';

    /**
     * Fast-path lookup for the standard verbs, checked before falling back
     * to {@see METHOD_PATTERN} - avoids a regex match on every request for
     * the overwhelming common case of an already-uppercase standard method.
     */
    private const array STANDARD_METHODS = [
        'GET' => true, 'POST' => true, 'PUT' => true, 'PATCH' => true,
        'DELETE' => true, 'HEAD' => true, 'OPTIONS' => true, 'TRACE' => true,
        'CONNECT' => true,
    ];

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

        // Inlined fast path for the constructor specifically, since it's
        // this library's hottest call site: skips the validateMethod()
        // call and the Host-header lookup's function-call overhead for
        // the common case (a standard verb, no caller-supplied Host header).
        if (!isset(self::STANDARD_METHODS[$method])) {
            $this->validateMethod($method);
        }

        $host = $uri->getHost();
        if ($host !== '') {
            $hasHost = false;
            foreach ($headers as $key => $ignored) {
                if (strtolower((string) $key) === 'host') {
                    $hasHost = true;
                    break;
                }
            }
            if (!$hasHost) {
                $port = $uri->getPort();
                $headers['Host'] = [$port !== null ? "{$host}:{$port}" : $host];
            }
        }

        parent::__construct($headers, $body, $protocolVersion);

        $this->method = $method;
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
        if ($this->method === $method) {
            return $this;
        }

        $this->validateMethod($method);

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
        return $host !== '' ? $new->withHeader('Host', self::hostHeaderValue($host, $uri->getPort())) : $new;
    }

    /**
     * @return string[]
     */
    private static function hostHeaderValue(string $host, ?int $port): array
    {
        return [$port !== null ? "{$host}:{$port}" : $host];
    }

    /**
     * Validates the HTTP method
     *
     * @throws InvalidArgumentException If method is invalid
     */
    private function validateMethod(string $method): void
    {
        if (isset(self::STANDARD_METHODS[$method])) {
            return;
        }

        if ($method === '') {
            throw new InvalidArgumentException('HTTP method cannot be empty');
        }

        if (!preg_match(self::METHOD_PATTERN, $method)) {
            throw new InvalidArgumentException("Invalid HTTP method: {$method}");
        }
    }
}