<?php

declare(strict_types=1);

namespace Temant\HttpCore\Exceptions;

use Temant\HttpCore\Message\Stream;
use Throwable;

/**
 * Thrown by {@see Stream::seek()} - and therefore {@see Stream::rewind()},
 * which is just `seek(0)` - when the wrapped resource reports itself as
 * non-seekable via `stream_get_meta_data()`.
 *
 * True for things like a pipe or a `php://output` stream, where "go back
 * to a byte already passed" isn't a meaningful operation.
 */
class StreamNotSeekableException extends StreamException
{
    public function __construct(string $message = 'Stream is not seekable.', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
