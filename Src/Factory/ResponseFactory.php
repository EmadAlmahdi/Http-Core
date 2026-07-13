<?php

declare(strict_types=1);

namespace Temant\HttpCore\Factory;

use Psr\Http\Message\ResponseFactoryInterface;
use Temant\HttpCore\Response;

/**
 * PSR-17 factory for {@see Response} instances.
 *
 * Leaving `$reasonPhrase` empty is intentional and PSR-7-correct: it
 * makes {@see Response::getReasonPhrase()} fall back to the standard
 * phrase for the given status code.
 */
class ResponseFactory implements ResponseFactoryInterface
{
    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function createResponse(int $code = 200, string $reasonPhrase = ''): \Psr\Http\Message\ResponseInterface
    {
        return new Response(
            $code,
            [],
            null,
            '1.1',
            $reasonPhrase
        );
    }
}