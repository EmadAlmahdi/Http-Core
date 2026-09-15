<?php

declare(strict_types=1);

namespace Temant\HttpCore\Factory;

use InvalidArgumentException;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;
use Temant\HttpCore\Request;

/**
 * PSR-17 factory for outgoing {@see Request} instances.
 *
 * Accepts either a URI string or an existing `UriInterface`; a string is
 * turned into one via the injected {@see UriFactoryInterface}. The body is
 * deliberately left unset - {@see Request}'s own default is lazy, so
 * there's no reason to force a stream resource open for every request
 * when most callers never touch the body of a `GET`.
 */
class RequestFactory implements RequestFactoryInterface
{
    public function __construct(
        private readonly UriFactoryInterface $uriFactory = new UriFactory()
    ) {
    }

    /**
     * @param string $method
     * @param UriInterface|string $uri
     * @throws InvalidArgumentException if `$uri` is neither a string nor a `UriInterface`.
     */
    #[\Override]
    public function createRequest(string $method, $uri): RequestInterface
    {
        return new Request($method, $this->resolveUri($uri));
    }

    /**
     * @throws InvalidArgumentException if `$uri` is neither a string nor a `UriInterface`.
     */
    private function resolveUri(mixed $uri): UriInterface
    {
        if (\is_string($uri)) {
            return $this->uriFactory->createUri($uri);
        }

        if ($uri instanceof UriInterface) {
            return $uri;
        }

        throw new InvalidArgumentException(
            'Parameter 2 of RequestFactory::createRequest() must be a string or a compatible UriInterface.'
        );
    }
}
