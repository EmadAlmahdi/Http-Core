<?php

declare(strict_types=1);

namespace Temant\HttpCore;

use InvalidArgumentException;
use Psr\Http\Message\UriInterface;
use Stringable;
use Uri\Rfc3986\Uri as NativeUri;

/**
 * A PSR-7 compatible, immutable URI value object.
 *
 * Every `with*()` method returns a new instance (via PHP 8.5's `clone with`
 * syntax) instead of mutating the current one, and every getter returns an
 * already-normalized value, exactly as PSR-7's `UriInterface` requires.
 *
 * ## Why this isn't a thin wrapper around PHP 8.5's native `Uri` extension
 *
 * PHP 8.5 ships two native URI parsers, and this class deliberately uses
 * only one of them, and only for one job:
 *
 * - **Parsing a full URI string** (the constructor) delegates to
 *   `Uri\Rfc3986\Uri::parse()`. That's a real RFC 3986 parser and is
 *   strictly more correct than the historically quirky `parse_url()` it
 *   replaces (better handling of IPv6 literals, malformed input, etc.),
 *   with no PSR-7 trade-off: parsing doesn't need to be forgiving.
 * - **Mutating a single component** (`withPath()`, `withUserInfo()`, ...)
 *   deliberately does *not* delegate to either native class, because
 *   neither one matches what PSR-7 requires here:
 *   - `Uri\Rfc3986\Uri`'s `with*()` methods *reject* raw, unencoded input
 *     (they throw on a literal space instead of encoding it), whereas
 *     PSR-7 requires `withPath()` etc. to accept raw input and encode it.
 *   - `Uri\WhatWg\Url`'s `with*()` methods do auto-encode, but the WHATWG
 *     URL Standard also silently resolves `.`/`..` path segments and
 *     cannot represent a bare relative reference (a path with no scheme)
 *     at all — both of which PSR-7's `UriInterface` explicitly requires
 *     this class to support unchanged.
 *
 *   So component encoding stays hand-rolled here (`filterPath()`,
 *   userinfo encoding, the scheme regex). It's also cheaper: these are
 *   plain string operations on an already-parsed value, versus
 *   constructing and validating a whole new native URI object per call.
 */
final class Uri implements UriInterface, Stringable
{
    /**
     * Default port per scheme; {@see getPort()} hides the port when it
     * matches this table, per PSR-7's "normalized" port semantics.
     */
    private const array STANDARD_PORTS = [
        'http' => 80,
        'https' => 443,
        'ftp' => 21,
    ];

    private const string SCHEME_PATTERN = '/^[a-z][a-z0-9+\-.]*$/i';

    /** Characters `rawurlencode()` never touches. */
    private const string UNRESERVED_PATTERN = '/^[A-Za-z0-9\-._~]*$/';

    /**
     * Same as {@see UNRESERVED_PATTERN}, plus the path separator. Used to
     * skip encoding entirely for the common case of an already-clean path
     * (e.g. `/api/v1/users/123`).
     */
    private const string PATH_SAFE_PATTERN = '/^[A-Za-z0-9\-._~\/]*$/';

    private readonly string $scheme;
    private readonly string $userInfo;
    private readonly string $host;
    private readonly ?int $port;
    private readonly string $path;
    private readonly string $query;
    private readonly string $fragment;

    public function __construct(string $uri = '')
    {
        if ($uri === '') {
            $this->scheme = '';
            $this->userInfo = '';
            $this->host = '';
            $this->port = null;
            $this->path = '';
            $this->query = '';
            $this->fragment = '';
            return;
        }

        $parsed = NativeUri::parse($uri);
        if ($parsed === null) {
            throw new InvalidArgumentException("Invalid URI: {$uri}");
        }

        $host = $parsed->getRawHost() ?? '';
        $path = $parsed->getRawPath();
        if ($host === '' && $path === '') {
            throw new InvalidArgumentException("Invalid URI: {$uri}");
        }

        $port = $parsed->getPort();
        $this->validatePort($port);

        $this->scheme = strtolower($parsed->getScheme() ?? '');
        $this->userInfo = $this->encodeUserInfo($parsed->getRawUsername(), $parsed->getRawPassword());
        $this->host = strtolower($host);
        $this->port = $port;
        $this->path = $this->filterPath($path);
        $this->query = $parsed->getQuery() ?? '';
        $this->fragment = $parsed->getFragment() ?? '';
    }

    /**
     * Builds the encoded userinfo component from raw username/password parts.
     */
    private function encodeUserInfo(?string $user, ?string $pass): string
    {
        if ($user === null || $user === '') {
            return '';
        }

        return $pass !== null && $pass !== ''
            ? $this->encodeUserComponent($user) . ':' . $this->encodeUserComponent($pass)
            : $this->encodeUserComponent($user);
    }

    /**
     * Percent-encodes a single userinfo sub-component (username or password),
     * skipping `rawurlencode()` when the value is already unreserved-only.
     */
    private function encodeUserComponent(string $value): string
    {
        return preg_match(self::UNRESERVED_PATTERN, $value) === 1 ? $value : rawurlencode($value);
    }

    /**
     * @throws InvalidArgumentException if port is invalid
     */
    private function validatePort(?int $port): void
    {
        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new InvalidArgumentException('Invalid port number in URI');
        }
    }

    #[\Override]
    public function getScheme(): string
    {
        return $this->scheme;
    }

    #[\Override]
    public function getAuthority(): string
    {
        if ($this->host === '') {
            return '';
        }

        $authority = $this->userInfo !== '' ? "{$this->userInfo}@" : '';
        $authority .= $this->host;

        $port = $this->getPort();
        if ($port !== null) {
            $authority .= ":{$port}";
        }

        return $authority;
    }

    #[\Override]
    public function getUserInfo(): string
    {
        return $this->userInfo;
    }

    #[\Override]
    public function getHost(): string
    {
        return $this->host;
    }

    #[\Override]
    public function getPort(): ?int
    {
        return $this->port !== null
            && (!isset(self::STANDARD_PORTS[$this->scheme])
                || $this->port !== self::STANDARD_PORTS[$this->scheme])
            ? $this->port
            : null;
    }

    #[\Override]
    public function getPath(): string
    {
        return $this->path;
    }

    #[\Override]
    public function getQuery(): string
    {
        return $this->query;
    }

    #[\Override]
    public function getFragment(): string
    {
        return $this->fragment;
    }

    /**
     * @throws InvalidArgumentException for invalid scheme
     */
    #[\Override]
    public function withScheme(string $scheme): static
    {
        $scheme = strtolower($scheme);
        if ($scheme !== '' && !preg_match(self::SCHEME_PATTERN, $scheme)) {
            throw new InvalidArgumentException("Invalid scheme \"{$scheme}\"");
        }
        if ($this->scheme === $scheme) {
            return $this;
        }

        return clone($this, ['scheme' => $scheme]);
    }

    #[\Override]
    public function withUserInfo(string $user, ?string $password = null): static
    {
        $encodedUser = $this->encodeUserComponent($user);
        $newUserInfo = $password !== null ? "{$encodedUser}:" . $this->encodeUserComponent($password) : $encodedUser;
        if ($newUserInfo === $this->userInfo) {
            return $this;
        }

        return clone($this, ['userInfo' => $newUserInfo]);
    }

    #[\Override]
    public function withHost(string $host): static
    {
        $host = strtolower($host);
        if ($host === $this->host) {
            return $this;
        }

        return clone($this, ['host' => $host]);
    }

    /**
     * @throws InvalidArgumentException on invalid port value
     */
    #[\Override]
    public function withPort(?int $port): static
    {
        $this->validatePort($port);
        if ($port === $this->port) {
            return $this;
        }

        return clone($this, ['port' => $port]);
    }

    #[\Override]
    public function withPath(string $path): static
    {
        $filtered = $this->filterPath($path);
        if ($filtered === $this->path) {
            return $this;
        }

        return clone($this, ['path' => $filtered]);
    }

    #[\Override]
    public function withQuery(string $query): static
    {
        $trimmed = ltrim($query, '?');
        if ($trimmed === $this->query) {
            return $this;
        }

        return clone($this, ['query' => $trimmed]);
    }

    #[\Override]
    public function withFragment(string $fragment): static
    {
        $trimmed = ltrim($fragment, '#');
        if ($trimmed === $this->fragment) {
            return $this;
        }

        return clone($this, ['fragment' => $trimmed]);
    }

    #[\Override]
    public function __toString(): string
    {
        $uri = $this->scheme !== '' ? "{$this->scheme}:" : '';

        $authority = $this->getAuthority();
        if ($authority !== '') {
            $uri .= "//{$authority}";
        }

        $uri .= $this->path;

        if ($this->query !== '') {
            $uri .= "?{$this->query}";
        }

        if ($this->fragment !== '') {
            $uri .= "#{$this->fragment}";
        }

        return $uri;
    }

    /**
     * Percent-encodes a raw path, leaving "/" separators intact.
     *
     * Most real-world paths (`/api/v1/users/123`) are already made up
     * entirely of unreserved characters, so the common case bails out
     * before ever calling `rawurlencode()`.
     */
    private function filterPath(string $path): string
    {
        if ($path === '' || preg_match(self::PATH_SAFE_PATTERN, $path) === 1) {
            return $path;
        }

        return str_replace('%2F', '/', rawurlencode($path));
    }
}