<?php

declare(strict_types=1);

namespace Temant\HttpCore;

/**
 * Standard HTTP request methods (RFC 9110).
 *
 * This is an additive convenience API; {@see Request} accepts any
 * extension-token method string regardless of whether it has a case here.
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
     * A safe method is one that doesn't alter server state (RFC 9110 §9.2.1).
     */
    public function isSafe(): bool
    {
        return match ($this) {
            self::Get, self::Head, self::Options, self::Trace => true,
            default => false,
        };
    }

    /**
     * An idempotent method produces the same server state whether called once or many times (RFC 9110 §9.2.2).
     */
    public function isIdempotent(): bool
    {
        return match ($this) {
            self::Get, self::Head, self::Options, self::Trace, self::Put, self::Delete => true,
            default => false,
        };
    }
}
