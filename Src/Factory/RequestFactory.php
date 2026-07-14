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
 * turned into one via the injected {@see UriFactoryInterface}. The body
 * is deliberately left unset - {@see Request}'s own default is lazy, so
 * there's no reason for the factory to force a stream resource open for
 * every request when most callers never touch the body of a `GET`.
 */
class RequestFactory implements RequestFactoryInterface
{
    public function __construct(
        private UriFactoryInterface $uriFactory = new UriFactory()
    ) {
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function createRequest(string $method, $uri): RequestInterface
    {
        if (is_string($uri)) {
            $uri = $this->uriFactory->createUri($uri);
        }

        /** @phpstan-ignore instanceof.alwaysTrue */
        if (!$uri instanceof UriInterface) {
            throw new InvalidArgumentException(
                'Parameter 2 of RequestFactory::createRequest() must be a string or a compatible UriInterface.'
            );
        }

        return new Request($method, $uri);
    }
}