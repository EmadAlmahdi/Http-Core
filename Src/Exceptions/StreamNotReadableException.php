<?php

declare(strict_types=1);

namespace Temant\HttpCore\Exceptions;
 
use Throwable;

/**
 * Thrown by {@see \Temant\HttpCore\Stream::read()} and {@see
 * \Temant\HttpCore\Stream::getContents()} when the underlying resource
 * wasn't opened in a readable mode (e.g. plain `'w'`, which truncates and
 * write-only opens the file). Like writability, readability is fixed at
 * construction from the resource's own mode string, so this means the
 * stream was opened for the wrong purpose, not that it's temporarily
 * unavailable.
 */
class StreamNotReadableException extends StreamException
{
    public function __construct(
        string $message = "Stream is not readable.",
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}