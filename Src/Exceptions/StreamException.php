<?php

declare(strict_types=1);

namespace Temant\HttpCore\Exceptions;

use RuntimeException;
use Temant\HttpCore\Stream;

/**
 * Base type for every exception this library throws while operating on a
 * {@see Stream}.
 *
 * Catch this when the only thing you care about is "something failed while
 * reading, writing, or seeking this stream" without needing to distinguish
 * *why* - each of the more specific precondition failures below
 * ({@see StreamDetachedException}, {@see StreamNotReadableException}, etc.)
 * extends it, so a single `catch (StreamException)` covers all of them.
 */
class StreamException extends RuntimeException
{
}
