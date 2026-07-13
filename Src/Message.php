<?php

declare(strict_types=1);

namespace Temant\HttpCore;

use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use InvalidArgumentException;

/**
 * Shared plumbing for {@see Request} and {@see Response}: protocol version,
 * headers, and the body stream.
 *
 * Header lookups (`hasHeader()`, `getHeader()`, ...) are case-insensitive,
 * as PSR-7 requires, via a small lowercase-name index kept alongside the
 * real storage. `getHeaders()` returns the headers keyed by the *exact*
 * casing they were last set with (also a PSR-7 requirement, easy to miss
 * since it's easy to accidentally normalize away) - `withHeader('Content-Type', ...)`
 * means `getHeaders()` comes back with a `Content-Type` key, not `content-type`.
 *
 * Everything here is `readonly`; every `with*()` method returns a fresh
 * instance rather than mutating the one it was called on.
 *
 * @link https://www.php-fig.org/psr/psr-7/ PSR-7 Specification
 */
abstract class Message implements MessageInterface
{
    private const string PROTOCOL_PATTERN = '/^(1\.[01]|2(?:\.0)?)$/';

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

    protected readonly StreamInterface $body;

    /**
     * HTTP protocol version (e.g., '1.0', '1.1', '2')
     */
    protected readonly string $protocolVersion;

    /**
     * @param array<string, string[]> $headers
     * @throws InvalidArgumentException For invalid protocol versions
     * @throws RuntimeException When no body is given and a default stream cannot be created
     */
    protected function __construct(array $headers, ?StreamInterface $body, string $protocolVersion)
    {
        $normalizedHeaders = [];
        $headerNames = [];
        foreach ($headers as $name => $value) {
            $name = (string) $name;
            $normalizedHeaders[$name] = $value;
            $headerNames[strtolower($name)] = $name;
        }

        $this->headers = $normalizedHeaders;
        $this->headerNames = $headerNames;
        $this->body = $body ?? $this->createDefaultBodyStream();
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
        return isset($this->headerNames[strtolower($name)]);
    }

    #[\Override]
    public function getHeader(string $name): array
    {
        $exact = $this->headerNames[strtolower($name)] ?? null;
        return $exact !== null ? $this->headers[$exact] : [];
    }

    #[\Override]
    public function getHeaderLine(string $name): string
    {
        $header = $this->getHeader($name);
        return $header ? implode(', ', $header) : '';
    }

    #[\Override]
    public function withHeader(string $name, $value): static
    {
        $lower = strtolower($name);
        $value = $this->filterHeaderValue($value);
        $existing = $this->headerNames[$lower] ?? null;

        // Prevent unnecessary cloning
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

    #[\Override]
    public function withAddedHeader(string $name, $value): static
    {
        $lower = strtolower($name);
        $value = $this->filterHeaderValue($value);
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

    #[\Override]
    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    #[\Override]
    public function withBody(StreamInterface $body): static
    {
        if ($this->body === $body) {
            return $this;
        }

        return clone($this, ['body' => $body]);
    }

    /**
     * Filter and validate header values.
     *
     * Normalizes to a list of strings in a single pass (rather than
     * validating and then mapping separately) and rejects anything
     * containing a CR or LF, which would otherwise allow header injection.
     *
     * @param string|string[] $value
     * @return string[]
     * @throws InvalidArgumentException For invalid header values
     */
    protected function filterHeaderValue(array|string $value): array
    {
        $values = \is_array($value) ? $value : [$value];

        if (empty($values) || $value === '') {
            throw new InvalidArgumentException('Header value cannot be empty');
        }

        $normalized = [];
        foreach ($values as $item) {
            $item = (string) $item;
            if (preg_match(self::HEADER_VALUE_PATTERN, $item)) {
                throw new InvalidArgumentException(
                    'Header values cannot contain CR or LF characters'
                );
            }
            $normalized[] = $item;
        }

        return $normalized;
    }

    /**
     * Validate protocol version
     *
     * @param string $version
     * @return string
     * @throws InvalidArgumentException For invalid protocol versions
     */
    protected function filterProtocolVersion(string $version): string
    {
        if (!preg_match(self::PROTOCOL_PATTERN, $version)) {
            throw new InvalidArgumentException(
                'Unsupported HTTP protocol version. Must be one of: 1.0, 1.1, 2, 2.0'
            );
        }

        return $version;
    }

    /**
     * Create the default body stream.
     *
     * @return StreamInterface
     * @throws RuntimeException if stream creation fails
     */
    protected function createDefaultBodyStream(): StreamInterface
    {
        $resource = @fopen('php://temp', 'r+');
        if ($resource === false) {
            // @codeCoverageIgnoreStart
            throw new RuntimeException('Failed to create temporary stream');
            // @codeCoverageIgnoreEnd
        }
        return new Stream($resource);
    }
}