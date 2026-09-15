<?php

declare(strict_types=1);

namespace Temant\HttpCore;

use InvalidArgumentException;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Shared plumbing for {@see Request} and {@see Response}: protocol version,
 * headers, and the body stream.
 *
 * Header lookups (`hasHeader()`, `getHeader()`, ...) are case-insensitive,
 * as PSR-7 requires, via a small lowercase-name index kept alongside the
 * real storage. `getHeaders()` returns the headers keyed by the *exact*
 * casing they were last set with - also a PSR-7 requirement, easy to miss
 * since it's easy to accidentally normalize away: `withHeader('Content-Type', ...)`
 * means `getHeaders()` comes back with a `Content-Type` key, not `content-type`.
 *
 * Both the header name and value are validated on every write. The name
 * must be a valid RFC 7230 `token`; the value must not contain a bare CR
 * or LF. Together these are what stop a caller-controlled value from
 * smuggling an extra header, or an entirely separate request, into the
 * message - the classic "header/response splitting" injection.
 *
 * Everything here is `readonly`; every `with*()` method returns a fresh
 * instance rather than mutating the one it was called on.
 *
 * The body stream is the one exception to "everything is set up in the
 * constructor": if none is supplied, no stream resource is opened until
 * something actually calls {@see getBody()}. Constructing a request or
 * response is one of the hottest paths in this library, and most of the
 * time nobody ever reads the (empty) body of a `GET` request - paying for
 * an `fopen()` call that's thrown away unread is pure waste.
 *
 * @link https://www.php-fig.org/psr/psr-7/ PSR-7 Specification
 */
abstract readonly class Message implements MessageInterface
{
    private const string PROTOCOL_PATTERN = '/^(1\.[01]|2(?:\.0)?)$/';

    /** RFC 7230 `token` grammar: one or more of the characters below. */
    private const string HEADER_NAME_PATTERN = '/^[!#$%&\'*+.^_`|~0-9a-zA-Z-]+$/';

    private const string HEADER_VALUE_PATTERN = "/[\r\n]/";

    /**
     * Header values, keyed by the exact name they were last set with.
     *
     * @var array<string, string[]>
     */
    protected readonly array $headers;

    /**
     * Case-insensitive lookup index: lowercased name => the exact-case key
     * currently used for it in {@see $headers}.
     *
     * @var array<string, string>
     */
    protected readonly array $headerNames;

    /**
     * Left uninitialized until {@see getBody()} is first called, unless an
     * explicit body was given to the constructor or {@see withBody()}.
     *
     * @phpstan-ignore property.uninitializedReadonly (intentionally lazy - see getBody())
     */
    protected readonly StreamInterface $body;

    /** HTTP protocol version, e.g. `'1.0'`, `'1.1'`, `'2'`. */
    protected readonly string $protocolVersion;

    /**
     * @param array<string, string|string[]> $headers
     * @throws InvalidArgumentException for an invalid header name/value, or protocol version.
     */
    protected function __construct(array $headers, ?StreamInterface $body, string $protocolVersion)
    {
        $normalizedHeaders = [];
        $headerNames = [];
        foreach ($headers as $name => $value) {
            $name = $this->filterHeaderName((string) $name);
            $normalizedHeaders[$name] = $this->filterHeaderValue($value);
            $headerNames[\strtolower($name)] = $name;
        }

        $this->headers = $normalizedHeaders;
        $this->headerNames = $headerNames;
        if ($body !== null) {
            $this->body = $body;
        }
        $this->protocolVersion = $this->filterProtocolVersion($protocolVersion);
    }

    #[\Override]
    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    #[\Override]
    public function withProtocolVersion(string $version): static
    {
        if ($this->protocolVersion === $version) {
            return $this;
        }

        return clone($this, ['protocolVersion' => $this->filterProtocolVersion($version)]);
    }

    #[\Override]
    public function getHeaders(): array
    {
        return $this->headers;
    }

    #[\Override]
    public function hasHeader(string $name): bool
    {
        return isset($this->headerNames[\strtolower($name)]);
    }

    #[\Override]
    public function getHeader(string $name): array
    {
        $exact = $this->headerNames[\strtolower($name)] ?? null;

        return $exact !== null ? $this->headers[$exact] : [];
    }

    #[\Override]
    public function getHeaderLine(string $name): string
    {
        $header = $this->getHeader($name);

        return $header === [] ? '' : \implode(', ', $header);
    }

    /**
     * @param string|string[] $value
     * @throws InvalidArgumentException for an invalid header name or value.
     */
    #[\Override]
    public function withHeader(string $name, $value): static
    {
        $name = $this->filterHeaderName($name);
        $value = $this->filterHeaderValue($value);
        $lower = \strtolower($name);
        $existing = $this->headerNames[$lower] ?? null;

        // Avoid an unnecessary clone when setting a header to what it already is.
        if ($existing === $name && ($this->headers[$existing] ?? null) === $value) {
            return $this;
        }

        $headers = $this->headers;
        if ($existing !== null) {
            unset($headers[$existing]);
        }
        $headers[$name] = $value;

        return clone($this, [
            'headers' => $headers,
            'headerNames' => [...$this->headerNames, $lower => $name],
        ]);
    }

    /**
     * @param string|string[] $value
     * @throws InvalidArgumentException for an invalid header name or value.
     */
    #[\Override]
    public function withAddedHeader(string $name, $value): static
    {
        $name = $this->filterHeaderName($name);
        $value = $this->filterHeaderValue($value);
        $lower = \strtolower($name);
        $existing = $this->headerNames[$lower] ?? null;

        if ($existing !== null) {
            return clone($this, ['headers' => [
                ...$this->headers,
                $existing => [...$this->headers[$existing], ...$value],
            ]]);
        }

        return clone($this, [
            'headers' => [...$this->headers, $name => $value],
            'headerNames' => [...$this->headerNames, $lower => $name],
        ]);
    }

    #[\Override]
    public function withoutHeader(string $name): static
    {
        $lower = \strtolower($name);
        $existing = $this->headerNames[$lower] ?? null;

        if ($existing === null) {
            return $this;
        }

        $headers = $this->headers;
        unset($headers[$existing]);

        $headerNames = $this->headerNames;
        unset($headerNames[$lower]);

        return clone($this, ['headers' => $headers, 'headerNames' => $headerNames]);
    }

    #[\Override]
    public function getBody(): StreamInterface
    {
        if (!isset($this->body)) {
            /** @phpstan-ignore property.readOnlyAssignNotInConstructor (guarded by isset() above - assigned exactly once) */
            $this->body = $this->createDefaultBodyStream();
        }

        return $this->body;
    }

    #[\Override]
    public function withBody(StreamInterface $body): static
    {
        if (isset($this->body) && $this->body === $body) {
            return $this;
        }

        return clone($this, ['body' => $body]);
    }

    /**
     * Validates a header name against RFC 7230's `token` grammar - the set
     * of characters a header field-name is allowed to contain.
     *
     * @throws InvalidArgumentException if `$name` is empty or contains a disallowed character.
     */
    protected function filterHeaderName(string $name): string
    {
        if ($name === '' || !\preg_match(self::HEADER_NAME_PATTERN, $name)) {
            throw new InvalidArgumentException("Invalid header name: \"{$name}\".");
        }

        return $name;
    }

    /**
     * Normalizes a header value (or list of values) to a list of strings in
     * a single pass, rejecting anything containing a CR or LF - which would
     * otherwise allow header injection.
     *
     * @param string|string[] $value
     * @return string[]
     * @throws InvalidArgumentException for an empty or invalid header value.
     */
    protected function filterHeaderValue(array|string $value): array
    {
        $values = \is_array($value) ? $value : [$value];

        if ($values === [] || $value === '') {
            throw new InvalidArgumentException('Header value cannot be empty.');
        }

        $normalized = [];
        foreach ($values as $item) {
            $item = (string) $item;
            if (\preg_match(self::HEADER_VALUE_PATTERN, $item)) {
                throw new InvalidArgumentException('Header values cannot contain CR or LF characters.');
            }
            $normalized[] = $item;
        }

        return $normalized;
    }

    /**
     * @throws InvalidArgumentException for an unsupported protocol version.
     */
    protected function filterProtocolVersion(string $version): string
    {
        // '1.1' is the default and overwhelmingly common case - skip the
        // regex entirely for it instead of matching on every construction.
        if ($version === '1.1' || \preg_match(self::PROTOCOL_PATTERN, $version)) {
            return $version;
        }

        throw new InvalidArgumentException(
            "Unsupported HTTP protocol version \"{$version}\". Must be one of: 1.0, 1.1, 2, 2.0."
        );
    }

    /**
     * @throws RuntimeException if the temporary stream can't be opened.
     */
    protected function createDefaultBodyStream(): StreamInterface
    {
        $resource = @\fopen('php://temp', 'r+');
        if ($resource === false) {
            // @codeCoverageIgnoreStart
            throw new RuntimeException('Failed to create temporary stream.');
            // @codeCoverageIgnoreEnd
        }

        return new Stream($resource);
    }
}
