<?php

declare(strict_types=1);

namespace Temant\HttpCore;

use InvalidArgumentException;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

use function fopen;
use function implode;
use function is_array;
use function preg_match;
use function strtolower;

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
 * @see MessageInterface The PSR-7 contract this class implements.
 * @link https://www.php-fig.org/psr/psr-7/ PSR-7 Specification
 */
abstract readonly class Message implements MessageInterface
{
    /**
     * `D` matters here as much as it does on {@see HEADER_VALUE_PATTERN}:
     * without it, a version string ending in a single `\n` (e.g. `"1.1\n"`)
     * would satisfy `$` before that trailing newline and pass validation
     * unnoticed.
     */
    private const string PROTOCOL_PATTERN = '/^(1\.[01]|2(?:\.0)?)$/D';

    /**
     * RFC 7230 `token` grammar: one or more of the characters below. `D`
     * for the same reason as {@see PROTOCOL_PATTERN} - a header name
     * ending in a single `\n` would otherwise pass.
     */
    private const string HEADER_NAME_PATTERN = '/^[!#$%&\'*+.^_`|~0-9a-zA-Z-]+$/D';

    /**
     * RFC 7230 `field-value` grammar: visible US-ASCII, space, tab, and the
     * obs-text range (0x80-0xFF, for legacy non-UTF-8 values) - an
     * allowlist, not just a CR/LF blocklist, so it also catches other
     * control characters (a bare NUL, BEL, vertical tab, ...) that a
     * blocklist limited to CR/LF would let through. The `D` modifier is
     * load-bearing: without it, PCRE's `$` matches just *before* a
     * trailing `\n` rather than requiring the whole string be consumed,
     * which would let a value ending in exactly one newline slip past
     * unmatched by the character class - reopening the very injection
     * this pattern exists to close.
     */
    private const string HEADER_VALUE_PATTERN = '/^[ \t\x21-\x7E\x80-\xFF]*$/D';

    /**
     * Header values, keyed by the exact name they were last set with.
     *
     * @var array<string, string[]>
     */
    protected array $headers;

    /**
     * Case-insensitive lookup index: lowercased name => the exact-case key
     * currently used for it in {@see $headers}.
     *
     * @var array<string, string>
     */
    protected array $headerNames;

    /**
     * Left uninitialized until {@see getBody()} is first called, unless an
     * explicit body was given to the constructor or {@see withBody()}.
     *
     * @phpstan-ignore property.uninitializedReadonly (intentionally lazy - see getBody())
     */
    protected StreamInterface $body;

    /** HTTP protocol version, e.g. `'1.0'`, `'1.1'`, `'2'`. */
    protected string $protocolVersion;

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
            $headerNames[strtolower($name)] = $name;
        }

        $this->headers = $normalizedHeaders;
        $this->headerNames = $headerNames;
        if ($body !== null) {
            $this->body = $body;
        }
        $this->protocolVersion = $this->filterProtocolVersion($protocolVersion);
    }

    /**
     * @inheritDoc
     * @see MessageInterface::getProtocolVersion()
     */
    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    /**
     * @inheritDoc
     * @see MessageInterface::withProtocolVersion()
     * @throws InvalidArgumentException for an unsupported protocol version.
     */
    public function withProtocolVersion(string $version): static
    {
        if ($this->protocolVersion === $version) {
            return $this;
        }

        return clone($this, ['protocolVersion' => $this->filterProtocolVersion($version)]);
    }

    /**
     * @inheritDoc
     * @see MessageInterface::getHeaders()
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * @inheritDoc
     * @see MessageInterface::hasHeader()
     */
    public function hasHeader(string $name): bool
    {
        return isset($this->headerNames[strtolower($name)]);
    }

    /**
     * @inheritDoc
     * @see MessageInterface::getHeader()
     */
    public function getHeader(string $name): array
    {
        $exact = $this->headerNames[strtolower($name)] ?? null;

        return $exact !== null ? $this->headers[$exact] : [];
    }

    /**
     * @inheritDoc
     * @see MessageInterface::getHeaderLine()
     */
    public function getHeaderLine(string $name): string
    {
        $header = $this->getHeader($name);

        return $header === [] ? '' : implode(', ', $header);
    }

    /**
     * @inheritDoc
     * @see MessageInterface::withHeader()
     * @param string|string[] $value
     * @throws InvalidArgumentException for an invalid header name or value.
     */
    public function withHeader(string $name, $value): static
    {
        $name = $this->filterHeaderName($name);
        $value = $this->filterHeaderValue($value);
        $lower = strtolower($name);
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
     * @inheritDoc
     * @see MessageInterface::withAddedHeader()
     * @param string|string[] $value
     * @throws InvalidArgumentException for an invalid header name or value.
     */
    public function withAddedHeader(string $name, $value): static
    {
        $name = $this->filterHeaderName($name);
        $value = $this->filterHeaderValue($value);
        $lower = strtolower($name);
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

    /**
     * @inheritDoc
     * @see MessageInterface::withoutHeader()
     */
    public function withoutHeader(string $name): static
    {
        $lower = strtolower($name);
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

    /**
     * @inheritDoc
     * @see MessageInterface::getBody()
     */
    public function getBody(): StreamInterface
    {
        if (!isset($this->body)) {
            /** @phpstan-ignore property.readOnlyAssignNotInConstructor (guarded by isset() above - assigned exactly once) */
            $this->body = $this->createDefaultBodyStream();
        }

        return $this->body;
    }

    /**
     * @inheritDoc
     * @see MessageInterface::withBody()
     */
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
        if ($name === '' || !preg_match(self::HEADER_NAME_PATTERN, $name)) {
            throw new InvalidArgumentException("Invalid header name: \"{$name}\".");
        }

        return $name;
    }

    /**
     * Normalizes a header value (or list of values) to a list of strings in
     * a single pass, rejecting anything outside {@see HEADER_VALUE_PATTERN}'s
     * allowlist - most importantly a raw CR or LF, which would otherwise
     * allow header injection ("response splitting"), but also any other
     * control character no legitimate header value needs.
     *
     * An empty *list* of values (`[]`) is rejected - there's nothing to set
     * the header to - but a single empty string is a valid value (e.g. an
     * `ETag: ` with nothing after the colon) and is left alone, matching
     * every other PSR-7 implementation.
     *
     * @param string|string[] $value
     * @return string[]
     * @throws InvalidArgumentException if `$value` is an empty array, or any value fails the allowlist.
     */
    protected function filterHeaderValue(array|string $value): array
    {
        $values = is_array($value) ? $value : [$value];

        if ($values === []) {
            throw new InvalidArgumentException('Header value cannot be an empty array.');
        }

        $normalized = [];
        foreach ($values as $item) {
            $item = (string) $item;
            if (!preg_match(self::HEADER_VALUE_PATTERN, $item)) {
                throw new InvalidArgumentException("Invalid header value: \"{$item}\".");
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
        if ($version === '1.1' || preg_match(self::PROTOCOL_PATTERN, $version)) {
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
        $resource = @fopen('php://temp', 'r+');
        if ($resource === false) {
            // @codeCoverageIgnoreStart
            throw new RuntimeException('Failed to create temporary stream.');
            // @codeCoverageIgnoreEnd
        }

        return new Stream($resource);
    }
}
