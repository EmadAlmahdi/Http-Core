<?php

declare(strict_types=1);

namespace Temant\HttpCore\Factory;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Temant\HttpCore\Response;

/**
 * PSR-17 factory for {@see Response} instances.
 *
 * Leaving `$reasonPhrase` empty is intentional and PSR-7-correct: it makes
 * {@see Response::getReasonPhrase()} fall back to the standard phrase for
 * the given status code.
 *
 * @see ResponseFactoryInterface The PSR-17 contract this class implements.
 */
class ResponseFactory implements ResponseFactoryInterface
{
    /**
     * @inheritDoc
     * @see ResponseFactoryInterface::createResponse()
     */
    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
    {
        return new Response($code, [], null, '1.1', $reasonPhrase);
    }
}
