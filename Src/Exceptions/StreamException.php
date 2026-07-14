<?php

declare(strict_types=1);

namespace Temant\HttpCore\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Something went wrong operating on a {@see \Temant\HttpCore\Stream}.
 *
 * Catch this (rather than the more specific subclasses below) if you just
 * want "did something break while I was reading/writing this stream?"
 * without caring exactly which precondition failed - it's the parent of
 * every stream-related exception this library throws.
 */
class StreamException extends RuntimeException
{
    public function __construct(string $message = "Stream exception occurred", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}