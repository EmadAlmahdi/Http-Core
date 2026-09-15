<?php

declare(strict_types=1);

namespace Temant\HttpCore\Factory;

use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Temant\HttpCore\Message\Stream;

use function fopen;
use function fwrite;
use function rewind;

/**
 * PSR-17 factory for {@see Stream} instances, backed by `php://memory`
 * (for in-memory content) or a real file handle.
 *
 * @see StreamFactoryInterface The PSR-17 contract this class implements.
 */
class StreamFactory implements StreamFactoryInterface
{
    /**
     * @inheritDoc
     * @see StreamFactoryInterface::createStream()
     */
    public function createStream(string $content = ''): StreamInterface
    {
        $resource = fopen('php://memory', 'r+');
        if ($resource === false) {
            // @codeCoverageIgnoreStart
            throw new RuntimeException("Couldn't create a stream.");
            // @codeCoverageIgnoreEnd
        }

        fwrite($resource, $content);
        rewind($resource);

        return new Stream($resource);
    }

    /**
     * @inheritDoc
     * @see StreamFactoryInterface::createStreamFromFile()
     */
    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        if ($filename === '') {
            throw new RuntimeException('Filename must not be empty.');
        }

        $resource = @fopen($filename, $mode);
        if ($resource === false) {
            throw new RuntimeException("Unable to open file: {$filename}.");
        }

        return new Stream($resource);
    }

    /**
     * @inheritDoc
     * @see StreamFactoryInterface::createStreamFromResource()
     */
    public function createStreamFromResource($resource): StreamInterface
    {
        return new Stream($resource);
    }
}
