<?php

declare(strict_types=1);

namespace Temant\HttpCore;

/**
 * The standard HTTP request methods defined by RFC 9110 §9.
 *
 * This is a convenience API, not a constraint: {@see Request} accepts any
 * extension-token method string (`PURGE`, `LOCK`, ...) whether or not it
 * has a case here - see {@see Request::__construct()}.
 */
enum HttpMethod: string
{
    case Get = 'GET';
    case Head = 'HEAD';
    case Post = 'POST';
    case Put = 'PUT';
    case Patch = 'PATCH';
    case Delete = 'DELETE';
    case Options = 'OPTIONS';
    case Trace = 'TRACE';
    case Connect = 'CONNECT';

    /**
     * Whether this method is defined as "safe" - it only retrieves data
     * and is not expected to change server state (RFC 9110 §9.2.1).
     */
    public function isSafe(): bool
    {
        return match ($this) {
            self::Get, self::Head, self::Options, self::Trace => true,
            self::Post, self::Put, self::Patch, self::Delete, self::Connect => false,
        };
    }

    /**
     * Whether repeating this request one time has the same effect on
     * server state as repeating it many times (RFC 9110 §9.2.2).
     */
    public function isIdempotent(): bool
    {
        return match ($this) {
            self::Get, self::Head, self::Options, self::Trace, self::Put, self::Delete => true,
            self::Post, self::Patch, self::Connect => false,
        };
    }
}
