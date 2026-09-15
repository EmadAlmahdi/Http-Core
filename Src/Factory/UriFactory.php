<?php

declare(strict_types=1);

namespace Temant\HttpCore\Factory;

use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;
use Temant\HttpCore\Message\Uri;

use function filter_var;
use function is_int;
use function is_scalar;
use function is_string;
use function preg_match;
use function rawurlencode;
use function strtok;
use function strtolower;

use const FILTER_VALIDATE_INT;

/**
 * PSR-17 factory for {@see Uri} instances.
 *
 * @see UriFactoryInterface The PSR-17 contract this class implements.
 */
class UriFactory implements UriFactoryInterface
{
    /**
     * @inheritDoc
     * @see UriFactoryInterface::createUri()
     */
    public function createUri(string $uri = ''): UriInterface
    {
        return new Uri($uri);
    }

    /**
     * Reconstructs the URI a client actually requested, from `$_SERVER`.
     *
     * There's no single `$_SERVER` key with "the URL" in it - it has to be
     * assembled from scheme (`HTTPS`, falling back to `REQUEST_SCHEME` and
     * then `X-Forwarded-Proto` for anything behind a reverse proxy), host
     * (`HTTP_HOST`, falling back to `SERVER_NAME`), port, and path+query
     * (`REQUEST_URI`). This does that assembly once so the rest of the
     * library never has to think about `$_SERVER`'s quirks directly.
     *
     * @param mixed[] $server Typically `$_SERVER`.
     */
    public static function createUriFromGlobals(array $server): UriInterface
    {
        $scheme = self::resolveScheme($server);
        [$host, $port] = self::resolveHostAndPort($server);
        $path = self::resolveString($server, 'REQUEST_URI');
        $path = $path !== null ? (strtok($path, '?') ?: '/') : '/';
        $query = self::resolveString($server, 'QUERY_STRING') ?? '';
        $user = self::resolveString($server, 'PHP_AUTH_USER') ?? '';
        $pass = self::resolveString($server, 'PHP_AUTH_PW') ?? '';

        $uriString = "{$scheme}://";

        if ($user !== '') {
            $uriString .= rawurlencode($user);
            if ($pass !== '') {
                $uriString .= ':' . rawurlencode($pass);
            }
            $uriString .= '@';
        }

        $uriString .= $host;

        $defaultPort = $scheme === 'https' ? 443 : 80;
        if ($port !== null && $port !== $defaultPort) {
            $uriString .= ":{$port}";
        }

        $uriString .= $path;

        if ($query !== '') {
            $uriString .= "?{$query}";
        }

        return new Uri($uriString);
    }

    /**
     * @param mixed[] $server
     */
    private static function resolveScheme(array $server): string
    {
        if (isset($server['HTTPS']) && $server['HTTPS'] !== 'off') {
            return 'https';
        }

        $scheme = self::resolveString($server, 'REQUEST_SCHEME') ?? self::resolveString($server, 'HTTP_X_FORWARDED_PROTO');

        return $scheme !== null ? strtolower($scheme) : 'http';
    }

    /**
     * Determines host and port together, since a `Host: host:port` header
     * value has to win over `SERVER_PORT` once the two are split apart.
     *
     * @param mixed[] $server
     * @return array{0: string, 1: ?int}
     */
    private static function resolveHostAndPort(array $server): array
    {
        $host = self::resolveString($server, 'HTTP_HOST') ?? self::resolveString($server, 'SERVER_NAME') ?? 'localhost';
        $host = strtolower($host);

        $serverPort = null;
        if (isset($server['SERVER_PORT']) && (is_int($server['SERVER_PORT']) || is_string($server['SERVER_PORT']))) {
            $validated = filter_var((string) $server['SERVER_PORT'], FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => 65535],
            ]);
            $serverPort = $validated !== false ? $validated : null;
        }

        if (preg_match('/^(\[[0-9a-f:.]+\]|[^:]+):(\d+)$/iD', $host, $matches)) {
            return [$matches[1], (int) $matches[2]];
        }

        return [$host, $serverPort];
    }

    /**
     * @param mixed[] $server
     */
    private static function resolveString(array $server, string $key): ?string
    {
        return isset($server[$key]) && is_scalar($server[$key]) ? (string) $server[$key] : null;
    }
}
