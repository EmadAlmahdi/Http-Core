<?php

declare(strict_types=1);

namespace Temant\HttpCore\Exceptions;

use Throwable;

/**
 * Thrown when a {@see \Temant\HttpCore\Stream} is used after {@see
 * \Temant\HttpCore\Stream::detach()} has already handed its resource off
 * to someone else. Once detached, the wrapper has nothing left to operate
 * on - there's no resource to read from, write to, or seek in, so every
 * operation that needs one throws this instead of segfaulting on a null
 * resource or silently doing nothing.
 */
class StreamDetachedException extends StreamException
{
    public function __construct(
        string $message = "Stream is detached and cannot be used.",
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}