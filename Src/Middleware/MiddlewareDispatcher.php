<?php

declare(strict_types=1);

namespace Temant\HttpCore\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function array_slice;

/**
 * PSR-15 request handler that runs a fixed queue of {@see MiddlewareInterface}
 * middleware, in order, ahead of a final fallback handler.
 *
 * Each middleware gets a *new* dispatcher - covering only the middleware
 * still after it in the queue - as its `$handler` argument, rather than a
 * single shared, mutable "advance to the next one" handler. That's a
 * deliberate correctness choice: a PSR-15 middleware is free to call
 * `$handler->handle($request)` zero times (it short-circuits and returns
 * its own response), once (the common case), or more than once (retrying,
 * or fanning a request out to run the rest of the pipeline twice). A
 * shared mutable index breaks under that last case - calling `handle()`
 * a second time would resume from wherever the first call left off,
 * silently skipping middleware, instead of correctly re-running the same
 * remaining queue both times. Since each dispatcher instance here is an
 * immutable, self-contained slice of the queue, calling its `handle()`
 * any number of times always does the same thing.
 *
 * There's no default fallback handler - one must always be supplied. A
 * silently-defaulted "always 404" handler would hide the bug of forgetting
 * to wire up a real one (a router's dispatch handler, typically) far more
 * often than it would help.
 *
 * @see RequestHandlerInterface The PSR-15 contract this class implements.
 * @link https://www.php-fig.org/psr/psr-15/ PSR-15 Specification
 */
final readonly class MiddlewareDispatcher implements RequestHandlerInterface
{
    /**
     * @param list<MiddlewareInterface> $middleware Queue of middleware still to run, in order.
     * @param RequestHandlerInterface $fallbackHandler Runs once the queue is exhausted.
     */
    public function __construct(
        private array $middleware,
        private RequestHandlerInterface $fallbackHandler
    ) {
    }

    /**
     * @inheritDoc
     * @see RequestHandlerInterface::handle()
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->middleware === []) {
            return $this->fallbackHandler->handle($request);
        }

        $current = $this->middleware[0];
        $remaining = array_slice($this->middleware, 1);

        return $current->process($request, new self($remaining, $this->fallbackHandler));
    }
}
