<?php

declare(strict_types=1);

namespace Temant\HttpCore\Exceptions;

use Throwable;

/**
 * Thrown by {@see \Temant\HttpCore\Stream::write()} when the underlying
 * resource wasn't opened in a writable mode (e.g. plain `'r'`). Writability
 * is fixed for the life of the wrapper - it's read once from the
 * resource's mode string at construction - so this always means "you
 * opened this stream wrong for what you're trying to do with it," not a
 * transient condition worth retrying.
 */
class StreamNotWritableException extends StreamException
{
    public function __construct(
        string $message = "Stream is not writable.",
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}