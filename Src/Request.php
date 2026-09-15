<?php

declare(strict_types=1);

namespace Temant\HttpCore;

use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

use function preg_match;
use function strtolower;

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
 * The method string is validated against RFC 7230's `token` grammar (the
 * constructor and {@see withMethod()} throw `InvalidArgumentException` for
 * anything that doesn't match, per PSR-7's contract) but is otherwise
 * stored exactly as given - PSR-7's `RequestInterface::withMethod()` is
 * explicit that "HTTP method names are case-sensitive and thus
 * implementations SHOULD NOT modify the given string", so `new
 * Request('get', $uri)` keeps `getMethod() === 'get'` rather than silently
 * uppercasing it.
 *
 * @see RequestInterface The PSR-7 contract this class implements.
 * @link https://www.php-fig.org/psr/psr-7/ PSR-7 Specification
 */
readonly class Request extends Message implements RequestInterface
{
    /**
     * Same grammar as {@see Message::HEADER_NAME_PATTERN}: RFC 7230's
     * `token`. The `D` modifier is required, not decorative - without it,
     * PCRE's `$` matches just before a trailing `\n`, so a method ending
     * in exactly one newline (e.g. `"GET\n"`) would satisfy this pattern
     * unmatched by the character class and pass validation.
     */
    private const string METHOD_PATTERN = '/^[!#$%&\'*+.^_`|~0-9a-z-]+$/iD';

    private const string REQUEST_TARGET_PATTERN = '/\s/';

    /**
     * Fast-path lookup for the standard verbs, checked before falling back
     * to {@see METHOD_PATTERN} - skips a regex match on every request for
     * the overwhelmingly common case of an already-uppercase standard
     * method.
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
     * @param string|HttpMethod $method HTTP request method (e.g. `GET`, `POST`).
     * @param UriInterface $uri URI of the request.
     * @param array<string, string|string[]> $headers Request headers.
     * @param StreamInterface|null $body Request body; created lazily if omitted.
     * @param string $protocolVersion HTTP protocol version.
     * @throws InvalidArgumentException for an invalid method or protocol version.
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
        // call and the Host-header lookup's function-call overhead for the
        // common case (a standard verb, no caller-supplied Host header).
        if (!isset(self::STANDARD_METHODS[$method])) {
            self::assertValidMethod($method);
        }

        $host = $uri->getHost();
        if ($host !== '' && !self::hasHostHeader($headers)) {
            $headers['Host'] = [self::hostHeaderValue($host, $uri->getPort())];
        }

        parent::__construct($headers, $body, $protocolVersion);

        $this->method = $method;
        $this->uri = $uri;
        $this->requestTarget = '';
    }

    /**
     * @inheritDoc
     * @see RequestInterface::getRequestTarget()
     */
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

    /**
     * @inheritDoc
     * @see RequestInterface::withRequestTarget()
     * @throws InvalidArgumentException if `$requestTarget` contains whitespace.
     */
    public function withRequestTarget(string $requestTarget): static
    {
        if (preg_match(self::REQUEST_TARGET_PATTERN, $requestTarget)) {
            throw new InvalidArgumentException('Request target cannot contain whitespace.');
        }

        return clone($this, ['requestTarget' => $requestTarget]);
    }

    /**
     * @inheritDoc
     * @see RequestInterface::getMethod()
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * @inheritDoc
     * @see RequestInterface::withMethod()
     * @throws InvalidArgumentException for an invalid method.
     */
    public function withMethod(string $method): static
    {
        if ($this->method === $method) {
            return $this;
        }

        self::assertValidMethod($method);

        return clone($this, ['method' => $method]);
    }

    /**
     * @inheritDoc
     * @see RequestInterface::getUri()
     */
    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    /**
     * @inheritDoc
     * @see RequestInterface::withUri()
     */
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
     * @param array<string, mixed> $headers
     */
    private static function hasHostHeader(array $headers): bool
    {
        foreach ($headers as $name => $ignored) {
            if (strtolower((string) $name) === 'host') {
                return true;
            }
        }

        return false;
    }

    private static function hostHeaderValue(string $host, ?int $port): string
    {
        return $port !== null ? "{$host}:{$port}" : $host;
    }

    /**
     * @throws InvalidArgumentException if `$method` is empty or not a valid RFC 7230 token.
     */
    private static function assertValidMethod(string $method): void
    {
        if (isset(self::STANDARD_METHODS[$method])) {
            return;
        }

        if ($method === '') {
            throw new InvalidArgumentException('HTTP method cannot be empty.');
        }

        if (!preg_match(self::METHOD_PATTERN, $method)) {
            throw new InvalidArgumentException("Invalid HTTP method: {$method}.");
        }
    }
}
