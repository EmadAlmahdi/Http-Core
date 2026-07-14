<?php

declare(strict_types=1);

namespace Temant\HttpCore;

use InvalidArgumentException;
use Psr\Http\Message\UriInterface;
use Stringable;

/**
 * A PSR-7 compatible, immutable URI value object.
 *
 * Every `with*()` method returns a new instance (via PHP 8.5's `clone with`
 * syntax) instead of mutating the current one, and every getter returns an
 * already-normalized value, exactly as PSR-7's `UriInterface` requires.
 *
 * ## Why this uses `parse_url()`, not PHP 8.5's native `Uri` extension
 *
 * PHP 8.5 ships two native URI parsers (`Uri\Rfc3986\Uri`, `Uri\WhatWg\Url`),
 * and this class was built around `Uri\Rfc3986\Uri::parse()` at first - it's
 * a real RFC 3986 parser and strictly more correct than `parse_url()`
 * (better handling of IPv6 literals, malformed input, etc.). Measuring it
 * ended that experiment: parsing itself is only ~1.7x slower than
 * `parse_url()`, but pulling the components back out through the
 * extension's object API (`getRawHost()`, `getPort()`, `getScheme()`, ...)
 * turned out to cost far more than the parse - about 5.5x slower than
 * `parse_url()` end to end, benchmarked in `benchmarks/run.php`. For a
 * value object constructed as often as this one, that's not a trade worth
 * making for edge cases most real URLs don't hit, so parsing stays on
 * `parse_url()`.
 *
 * Neither native class is used for mutating a single component
 * (`withPath()`, `withUserInfo()`, ...) either, for a separate reason:
 * neither one matches what PSR-7 requires here.
 * - `Uri\Rfc3986\Uri`'s `with*()` methods *reject* raw, unencoded input
 *   (they throw on a literal space instead of encoding it), whereas PSR-7
 *   requires `withPath()` etc. to accept raw input and encode it.
 * - `Uri\WhatWg\Url`'s `with*()` methods do auto-encode, but the WHATWG
 *   URL Standard also silently resolves `.`/`..` path segments and cannot
 *   represent a bare relative reference (a path with no scheme) at all -
 *   both of which PSR-7's `UriInterface` explicitly requires this class to
 *   support unchanged.
 *
 * So component encoding is hand-rolled here instead: a fast path for the
 * common case where a component is already made up entirely of allowed
 * characters, and a `preg_replace_callback()` fallback for everything else
 * that encodes only what actually needs it - critically, one that leaves
 * an already-valid `%XX` triplet alone rather than re-encoding its `%`,
 * which is what PSR-7 means by "MUST NOT double-encode any characters."
 * (An earlier version of this code got that wrong via a plain
 * `rawurlencode()` call, which happily turned a caller-supplied `%20`
 * into `%2520`.)
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

    /**
     * Fast-path check for the query/fragment case: every character RFC 3986
     * allows unescaped there (the unreserved set, sub-delims, `:`/`@`/`/`,
     * plus a bare `?`). If the whole string already matches, there's
     * nothing to encode.
     */
    private const string QUERY_FRAGMENT_SAFE_PATTERN = "/^[A-Za-z0-9\\-._~!$&'()*+,;=:@\\/?]*$/";

    /**
     * Matches a run of characters that need percent-encoding in a path: a
     * bare `%` not already part of a valid `%XX` triplet, or anything
     * outside the allowed path character set. `%` is excluded from the
     * first alternative's negated class deliberately - otherwise every
     * `%` would greedily match there instead of ever reaching the second
     * alternative's "is this actually a valid triplet?" lookahead, which
     * is what keeps this from double-encoding input the caller already
     * encoded themselves.
     */
    private const string PATH_ENCODE_PATTERN = "/(?:[^A-Za-z0-9\\-._~!$&'()*+,;=:@\\/%]++|%(?![A-Fa-f0-9]{2}))/";

    /** As {@see PATH_ENCODE_PATTERN}, but a bare `?` is also left alone. */
    private const string QUERY_FRAGMENT_ENCODE_PATTERN = "/(?:[^A-Za-z0-9\\-._~!$&'()*+,;=:@\\/?%]++|%(?![A-Fa-f0-9]{2}))/";

    /** As {@see PATH_ENCODE_PATTERN}, restricted to what's valid in a userinfo sub-component (no `/`, `?`, `@`, `:` is the user/pass separator so it's excluded too). */
    private const string USERINFO_ENCODE_PATTERN = "/(?:[^A-Za-z0-9\\-._~!$&'()*+,;=%]++|%(?![A-Fa-f0-9]{2}))/";

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

        $parts = parse_url($uri);
        if ($parts === false || (!isset($parts['host']) && !isset($parts['path']))) {
            throw new InvalidArgumentException("Invalid URI: {$uri}");
        }

        $port = $parts['port'] ?? null;
        $this->validatePort($port);

        $this->scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
        $this->userInfo = $this->encodeUserInfo($parts['user'] ?? null, $parts['pass'] ?? null);
        $this->host = isset($parts['host']) ? strtolower($parts['host']) : '';
        $this->port = $port;
        $this->path = isset($parts['path']) ? $this->filterPath($parts['path']) : '';
        $this->query = isset($parts['query']) ? $this->filterQueryOrFragment($parts['query']) : '';
        $this->fragment = isset($parts['fragment']) ? $this->filterQueryOrFragment($parts['fragment']) : '';
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
     * skipping the regex entirely when the value is already unreserved-only.
     */
    private function encodeUserComponent(string $value): string
    {
        if ($value === '' || preg_match(self::UNRESERVED_PATTERN, $value) === 1) {
            return $value;
        }

        return self::encodeExcept(self::USERINFO_ENCODE_PATTERN, $value);
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
        $filtered = $this->filterQueryOrFragment(ltrim($query, '?'));
        if ($filtered === $this->query) {
            return $this;
        }

        return clone($this, ['query' => $filtered]);
    }

    #[\Override]
    public function withFragment(string $fragment): static
    {
        $filtered = $this->filterQueryOrFragment(ltrim($fragment, '#'));
        if ($filtered === $this->fragment) {
            return $this;
        }

        return clone($this, ['fragment' => $filtered]);
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
     * Percent-encodes a raw path, leaving "/" and everything else RFC 3986
     * allows unescaped (sub-delims, ":", "@", and any `%XX` triplet the
     * caller already encoded themselves) intact.
     *
     * Most real-world paths (`/api/v1/users/123`) are already made up
     * entirely of unreserved characters, so the common case bails out
     * before ever touching a regex.
     */
    private function filterPath(string $path): string
    {
        if ($path === '' || preg_match(self::PATH_SAFE_PATTERN, $path) === 1) {
            return $path;
        }

        return self::encodeExcept(self::PATH_ENCODE_PATTERN, $path);
    }

    /**
     * Percent-encodes a raw query string or fragment (the two components
     * share the same allowed character set, differing only in leading
     * delimiter, which callers strip before calling this).
     */
    private function filterQueryOrFragment(string $value): string
    {
        if ($value === '' || preg_match(self::QUERY_FRAGMENT_SAFE_PATTERN, $value) === 1) {
            return $value;
        }

        return self::encodeExcept(self::QUERY_FRAGMENT_ENCODE_PATTERN, $value);
    }

    /**
     * Runs `preg_replace_callback()` with the given "what needs escaping"
     * pattern, rawurlencode-ing each match. `preg_replace_callback()` is
     * typed to return `string|null`, with `null` reserved for a PCRE engine
     * failure (a backtrack/recursion limit, or invalid UTF-8 under the `/u`
     * modifier) - neither of which applies to the plain byte-oriented
     * patterns used here, so a `null` here would mean something is
     * seriously wrong rather than a normal failure to handle gracefully.
     */
    private static function encodeExcept(string $pattern, string $value): string
    {
        return preg_replace_callback($pattern, self::rawurlencodeMatch(...), $value)
            ?? throw new \RuntimeException('Unexpected failure while percent-encoding a URI component');
    }

    /**
     * `preg_replace_callback()` callback shared by every encode path here:
     * each match is either a run of characters that need escaping, or a
     * lone "%" that isn't part of a valid `%XX` triplet - either way,
     * `rawurlencode()` is the right thing to do to it. Never applied to an
     * already-valid `%XX` triplet, which is what keeps these methods from
     * double-encoding input that arrives pre-encoded.
     *
     * @param string[] $match
     */
    private static function rawurlencodeMatch(array $match): string
    {
        return rawurlencode($match[0]);
    }
}