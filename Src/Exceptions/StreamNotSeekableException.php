<?php

declare(strict_types=1);

namespace Temant\HttpCore\Exceptions;
 
use Throwable;

/**
 * Thrown by {@see \Temant\HttpCore\Stream::seek()} (and therefore {@see
 * \Temant\HttpCore\Stream::rewind()}, which just calls `seek(0)`) when the
 * underlying resource reports itself as non-seekable via PHP's own
 * `stream_get_meta_data()` - true for things like a pipe or a
 * `php://output` stream, where "go back to a byte you already passed"
 * isn't a meaningful operation.
 */
class StreamNotSeekableException extends StreamException
{
    public function __construct(
        string $message = "Stream is not seekable.",
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}