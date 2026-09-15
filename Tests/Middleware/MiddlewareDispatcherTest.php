<?php

declare(strict_types=1);

namespace Temant\HttpCore\Tests\Middleware;

use Closure;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Temant\HttpCore\Middleware\MiddlewareDispatcher;

final class MiddlewareDispatcherTest extends TestCase
{
    public function testEmptyQueueCallsFallbackHandlerDirectly(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $fallback = new ClosureRequestHandler(fn() => $response);

        $dispatcher = new MiddlewareDispatcher([], $fallback);

        $this->assertSame($response, $dispatcher->handle($request));
        $this->assertSame(1, $fallback->calls);
    }

    public function testSingleMiddlewareWrapsTheFallbackHandler(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $log = new LogCollector();
        $fallback = new ClosureRequestHandler(function () use ($log, $response) {
            $log->add('fallback');
            return $response;
        });

        $dispatcher = new MiddlewareDispatcher([new RecordingMiddleware('a', $log)], $fallback);
        $result = $dispatcher->handle($request);

        $this->assertSame($response, $result);
        $this->assertSame(['a:before', 'fallback', 'a:after'], $log->entries);
    }

    public function testMultipleMiddlewareRunInRegistrationOrderAroundTheFallbackHandler(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $log = new LogCollector();
        $fallback = new ClosureRequestHandler(function () use ($log, $response) {
            $log->add('fallback');
            return $response;
        });

        $dispatcher = new MiddlewareDispatcher([
            new RecordingMiddleware('a', $log),
            new RecordingMiddleware('b', $log),
        ], $fallback);
        $dispatcher->handle($request);

        $this->assertSame(['a:before', 'b:before', 'fallback', 'b:after', 'a:after'], $log->entries);
    }

    public function testMiddlewareCanShortCircuitWithoutCallingTheHandler(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $shortCircuitResponse = $this->createMock(ResponseInterface::class);
        $log = new LogCollector();

        $shortCircuit = new ClosureMiddleware(function () use ($shortCircuitResponse, $log) {
            $log->add('short-circuit');
            return $shortCircuitResponse;
        });
        $fallback = new ClosureRequestHandler(function () use ($log) {
            $log->add('fallback');
            return $this->createMock(ResponseInterface::class);
        });

        $dispatcher = new MiddlewareDispatcher([
            $shortCircuit,
            new RecordingMiddleware('never-reached', $log),
        ], $fallback);
        $result = $dispatcher->handle($request);

        $this->assertSame($shortCircuitResponse, $result);
        $this->assertSame(['short-circuit'], $log->entries);
        $this->assertSame(0, $fallback->calls);
    }

    /**
     * Regression test for the reason each step gets its own immutable
     * dispatcher instance instead of one shared, mutable "next" index: a
     * middleware calling `$handler->handle($request)` more than once must
     * re-run the *same* remaining queue every time, not silently skip
     * middleware a previous call already "consumed".
     */
    public function testHandlerCanBeInvokedMultipleTimesWithoutSkippingMiddleware(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $log = new LogCollector();
        $fallback = new ClosureRequestHandler(fn() => $response);

        $doubleCaller = new ClosureMiddleware(function (ServerRequestInterface $request, RequestHandlerInterface $handler) {
            $handler->handle($request);
            return $handler->handle($request);
        });

        $dispatcher = new MiddlewareDispatcher([
            $doubleCaller,
            new RecordingMiddleware('inner', $log),
        ], $fallback);
        $dispatcher->handle($request);

        $this->assertSame(['inner:before', 'inner:after', 'inner:before', 'inner:after'], $log->entries);
        $this->assertSame(2, $fallback->calls);
    }
}

/**
 * Shared, ordered call log the test middleware/handlers below write into.
 */
final class LogCollector
{
    /** @var string[] */
    public array $entries = [];

    public function add(string $entry): void
    {
        $this->entries[] = $entry;
    }
}

/**
 * Records "<name>:before"/"<name>:after" around delegating to the handler -
 * used to assert both that middleware ran and in what order.
 */
final class RecordingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly string $name,
        private readonly LogCollector $log
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->log->add("{$this->name}:before");
        $response = $handler->handle($request);
        $this->log->add("{$this->name}:after");

        return $response;
    }
}

/**
 * Wraps an arbitrary closure as a middleware, for behavior no reusable
 * fixture above covers (short-circuiting, calling the handler more than
 * once, ...).
 */
final class ClosureMiddleware implements MiddlewareInterface
{
    /**
     * @param Closure(ServerRequestInterface, RequestHandlerInterface): ResponseInterface $callback
     */
    public function __construct(
        private readonly Closure $callback
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return ($this->callback)($request, $handler);
    }
}

/**
 * Wraps a closure as the pipeline's fallback handler, counting how many
 * times it was actually invoked.
 */
final class ClosureRequestHandler implements RequestHandlerInterface
{
    public int $calls = 0;

    /**
     * @param Closure(ServerRequestInterface): ResponseInterface $callback
     */
    public function __construct(
        private readonly Closure $callback
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->calls++;

        return ($this->callback)($request);
    }
}
