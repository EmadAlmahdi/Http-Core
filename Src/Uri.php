<?php

declare(strict_types=1);

namespace Temant\HttpCore;

use InvalidArgumentException;
use Psr\Http\Message\UriInterface;
use RuntimeException;
use Stringable;

/**
 * A PSR-7 compatible, immutable URI value object.
 *
 * Every `with*()` method returns a new instance (built with `clone(...)`
 * and named arguments) instead of mutating the current one, and every
 * getter returns an already-normalized value, exactly as PSR-7's
 * `UriInterface` requires.
 *
 * ## Why `parse_url()`, not PHP's native `Uri` extension
 *
 * PHP ships two native URI parsers (`Uri\Rfc3986\Uri`, `Uri\WhatWg\Url`).
 * `Uri\Rfc3986\Uri` is a real RFC 3986 parser and strictly more correct
 * than `parse_url()` - better IPv6-literal and malformed-input handling -
 * but pulling components back out through its object API costs several
 * times more than `parse_url()` end to end for a value object constructed
 * as often as this one, for edge cases most real URLs never hit.
 *
 * Neither native class is used for mutating a single component either
 * (`withPath()`, `withUserInfo()`, ...), for a different reason: neither
 * one matches what PSR-7 requires here.
 * - `Uri\Rfc3986\Uri`'s `with*()` methods *reject* raw, unencoded input
 *   (throwing on a literal space), whereas PSR-7 requires `withPath()`
 *   etc. to accept raw input and encode it.
 * - `Uri\WhatWg\Url`'s `with*()` methods do auto-encode, but the WHATWG
 *   standard also silently resolves `.`/`..` path segments and can't
 *   represent a schemeless relative reference at all - both of which
 *   PSR-7 requires this class to preserve unchanged.
 *
 * So component encoding is hand-rolled: a fast path for the common case
 * where a component is already made up entirely of allowed characters, and
 * a `preg_replace_callback()` fallback that encodes only what actually
 * needs it - critically, one that leaves an already-valid `%XX` triplet
 * alone rather than re-encoding its `%`, which is what PSR-7 means by
 * "MUST NOT double-encode any characters."
 */
final readonly class Uri implements UriInterface, Stringable
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

    /**
     * The `D` modifier on this and the three patterns below is required,
     * not decorative: without it, PCRE's `$` is satisfied just *before* a
     * trailing `\n` rather than requiring the whole string to match, so a
     * value ending in exactly one newline would pass unmatched by the
     * character class. For the three "is this already safe to skip
     * encoding" fast paths below, that would mean returning a component
     * with a raw, unencoded newline still in it.
     */
    private const string SCHEME_PATTERN = '/^[a-z][a-z0-9+\-.]*$/iD';

    /**
     * Rejects a host containing a C0 control character, DEL, or a
     * character that has structural meaning in a URI authority or an HTTP
     * request line (`/ ? # @ \`). Unlike the path/query/fragment/userinfo
     * components, an invalid host isn't percent-encoded - a percent-encoded
     * hostname isn't something DNS or an HTTP client would resolve
     * meaningfully, so this rejects instead, matching every other PSR-7
     * implementation. This is a "contains" check (no `^`/`$` anchors), so
     * the PCRE trailing-newline anchor gotcha documented above doesn't
     * apply to it.
     */
    private const string HOST_INVALID_PATTERN = '/[\x00-\x20\x7F\/?#@\\\\]/';

    /** Characters `rawurlencode()` never touches. */
    private const string UNRESERVED_PATTERN = '/^[A-Za-z0-9\-._~]*$/D';

    /**
     * As {@see UNRESERVED_PATTERN}, plus the path separator - used to skip
     * encoding entirely for the common case of an already-clean path
     * (e.g. `/api/v1/users/123`).
     */
    private const string PATH_SAFE_PATTERN = '/^[A-Za-z0-9\-._~\/]*$/D';

    /**
     * Fast-path check for query/fragment: every character RFC 3986 allows
     * unescaped there (the unreserved set, sub-delims, `:`/`@`/`/`, plus a
     * bare `?`). If the whole string already matches, nothing needs
     * encoding.
     */
    private const string QUERY_FRAGMENT_SAFE_PATTERN = "/^[A-Za-z0-9\\-._~!$&'()*+,;=:@\\/?]*$/D";

    /**
     * Matches a run of characters that need percent-encoding in a path: a
     * bare `%` not already part of a valid `%XX` triplet, or anything
     * outside the allowed path character set. `%` is excluded from the
     * first alternative's negated class deliberately - otherwise every `%`
     * would greedily match there instead of ever reaching the second
     * alternative's "is this actually a valid triplet?" lookahead, which
     * is what keeps this from double-encoding input the caller already
     * encoded.
     */
    private const string PATH_ENCODE_PATTERN = "/(?:[^A-Za-z0-9\\-._~!$&'()*+,;=:@\\/%]++|%(?![A-Fa-f0-9]{2}))/";

    /** As {@see PATH_ENCODE_PATTERN}, but a bare `?` is also left alone. */
    private const string QUERY_FRAGMENT_ENCODE_PATTERN = "/(?:[^A-Za-z0-9\\-._~!$&'()*+,;=:@\\/?%]++|%(?![A-Fa-f0-9]{2}))/";

    /**
     * As {@see PATH_ENCODE_PATTERN}, restricted to what's valid in a
     * userinfo sub-component: no `/`, `?`, `@`, and `:` is excluded too
     * since it's the user/password separator.
     */
    private const string USERINFO_ENCODE_PATTERN = "/(?:[^A-Za-z0-9\\-._~!$&'()*+,;=%]++|%(?![A-Fa-f0-9]{2}))/";

    private readonly string $scheme;
    private readonly string $userInfo;
    private readonly string $host;
    private readonly ?int $port;
    private readonly string $path;
    private readonly string $query;
    private readonly string $fragment;

    /**
     * @throws InvalidArgumentException if `$uri` cannot be parsed, or has an out-of-range port.
     */
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

        $parts = \parse_url($uri);
        if ($parts === false || (!isset($parts['host']) && !isset($parts['path']))) {
            throw new InvalidArgumentException("Invalid URI: {$uri}");
        }

        $port = $parts['port'] ?? null;
        self::assertValidPort($port);

        $this->scheme = isset($parts['scheme']) ? \strtolower($parts['scheme']) : '';
        $this->userInfo = $this->buildUserInfo($parts['user'] ?? null, $parts['pass'] ?? null);
        $this->host = isset($parts['host']) ? self::filterHost(\strtolower($parts['host'])) : '';
        $this->port = $port;
        $this->path = isset($parts['path']) ? $this->filterPath($parts['path']) : '';
        $this->query = isset($parts['query']) ? $this->filterQueryOrFragment($parts['query']) : '';
        $this->fragment = isset($parts['fragment']) ? $this->filterQueryOrFragment($parts['fragment']) : '';
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
        $isStandard = isset(self::STANDARD_PORTS[$this->scheme]) && $this->port === self::STANDARD_PORTS[$this->scheme];

        return $this->port !== null && !$isStandard ? $this->port : null;
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
     * @throws InvalidArgumentException for a scheme that isn't a valid RFC 3986 scheme token.
     */
    #[\Override]
    public function withScheme(string $scheme): static
    {
        $scheme = \strtolower($scheme);
        if ($scheme !== '' && !\preg_match(self::SCHEME_PATTERN, $scheme)) {
            throw new InvalidArgumentException("Invalid scheme \"{$scheme}\".");
        }

        return $scheme === $this->scheme ? $this : clone($this, ['scheme' => $scheme]);
    }

    #[\Override]
    public function withUserInfo(string $user, ?string $password = null): static
    {
        $encodedUser = $this->encodeUserInfoComponent($user);
        $userInfo = $password !== null
            ? $encodedUser . ':' . $this->encodeUserInfoComponent($password)
            : $encodedUser;

        return $userInfo === $this->userInfo ? $this : clone($this, ['userInfo' => $userInfo]);
    }

    /**
     * @throws InvalidArgumentException if `$host` contains a control character or a URI-structural character.
     */
    #[\Override]
    public function withHost(string $host): static
    {
        $host = self::filterHost(\strtolower($host));

        return $host === $this->host ? $this : clone($this, ['host' => $host]);
    }

    /**
     * @throws InvalidArgumentException for a port outside the 1-65535 range.
     */
    #[\Override]
    public function withPort(?int $port): static
    {
        self::assertValidPort($port);

        return $port === $this->port ? $this : clone($this, ['port' => $port]);
    }

    #[\Override]
    public function withPath(string $path): static
    {
        $filtered = $this->filterPath($path);

        return $filtered === $this->path ? $this : clone($this, ['path' => $filtered]);
    }

    #[\Override]
    public function withQuery(string $query): static
    {
        $filtered = $this->filterQueryOrFragment(\ltrim($query, '?'));

        return $filtered === $this->query ? $this : clone($this, ['query' => $filtered]);
    }

    #[\Override]
    public function withFragment(string $fragment): static
    {
        $filtered = $this->filterQueryOrFragment(\ltrim($fragment, '#'));

        return $filtered === $this->fragment ? $this : clone($this, ['fragment' => $filtered]);
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
     * Builds the encoded userinfo component from the raw user/password
     * parts a constructor-time `parse_url()` call produced.
     */
    private function buildUserInfo(?string $user, ?string $pass): string
    {
        if ($user === null || $user === '') {
            return '';
        }

        return $pass !== null && $pass !== ''
            ? $this->encodeUserInfoComponent($user) . ':' . $this->encodeUserInfoComponent($pass)
            : $this->encodeUserInfoComponent($user);
    }

    /**
     * Percent-encodes a single userinfo sub-component (username or
     * password), skipping the regex entirely when it's already
     * unreserved-only.
     */
    private function encodeUserInfoComponent(string $value): string
    {
        if ($value === '' || \preg_match(self::UNRESERVED_PATTERN, $value) === 1) {
            return $value;
        }

        return self::encodeExcept(self::USERINFO_ENCODE_PATTERN, $value);
    }

    /**
     * Percent-encodes a raw path, leaving "/" and everything else RFC 3986
     * allows unescaped (sub-delims, ":", "@", and any `%XX` triplet the
     * caller already encoded) intact.
     *
     * Most real-world paths (`/api/v1/users/123`) are already made up
     * entirely of unreserved characters, so the common case returns
     * without ever touching a regex.
     */
    private function filterPath(string $path): string
    {
        if ($path === '' || \preg_match(self::PATH_SAFE_PATTERN, $path) === 1) {
            return $path;
        }

        return self::encodeExcept(self::PATH_ENCODE_PATTERN, $path);
    }

    /**
     * Percent-encodes a raw query string or fragment; the two components
     * share the same allowed character set and differ only in their
     * leading delimiter, which callers strip before calling this.
     */
    private function filterQueryOrFragment(string $value): string
    {
        if ($value === '' || \preg_match(self::QUERY_FRAGMENT_SAFE_PATTERN, $value) === 1) {
            return $value;
        }

        return self::encodeExcept(self::QUERY_FRAGMENT_ENCODE_PATTERN, $value);
    }

    /**
     * @throws InvalidArgumentException if `$port` is outside the 1-65535 range.
     */
    private static function assertValidPort(?int $port): void
    {
        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new InvalidArgumentException('Invalid port number in URI; must be between 1 and 65535.');
        }
    }

    /**
     * @throws InvalidArgumentException if `$host` contains a control character or a URI-structural character.
     */
    private static function filterHost(string $host): string
    {
        if ($host === '' || !\preg_match(self::HOST_INVALID_PATTERN, $host)) {
            return $host;
        }

        throw new InvalidArgumentException("Invalid host \"{$host}\".");
    }

    /**
     * Runs `preg_replace_callback()` with the given "what needs escaping"
     * pattern, rawurlencode-ing each match.
     */
    private static function encodeExcept(string $pattern, string $value): string
    {
        return \preg_replace_callback(
            $pattern,
            static fn(array $match): string => \rawurlencode($match[0]),
            $value,
        ) ?? throw new RuntimeException('Unexpected failure while percent-encoding a URI component.');
    }
}
