<?php

declare(strict_types=1);

namespace Temant\HttpCore\Exceptions;

use Temant\HttpCore\Stream;
use Throwable;

/**
 * Thrown by {@see Stream::read()} and {@see Stream::getContents()} when
 * the wrapped resource wasn't opened in a readable mode.
 *
 * Readability is fixed once, at construction, from the resource's own mode
 * string - so this always means the stream was opened for the wrong
 * purpose, never a transient condition worth retrying.
 */
class StreamNotReadableException extends StreamException
{
    public function __construct(string $message = 'Stream is not readable.', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
