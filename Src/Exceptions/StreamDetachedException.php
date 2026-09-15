<?php

declare(strict_types=1);

namespace Temant\HttpCore\Exceptions;

use Temant\HttpCore\Stream;
use Throwable;

/**
 * Thrown when an operation needs the resource a {@see Stream} was
 * constructed with, but {@see Stream::detach()} has already handed it to
 * someone else.
 *
 * A detached stream has nothing left to operate on, so every method that
 * touches the resource throws this instead of segfaulting on a null handle
 * or silently no-oping.
 */
class StreamDetachedException extends StreamException
{
    public function __construct(string $message = 'Stream is detached and cannot be used.', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
