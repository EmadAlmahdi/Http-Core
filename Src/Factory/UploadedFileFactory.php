<?php

declare(strict_types=1);

namespace Temant\HttpCore\Factory;

use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;
use Temant\HttpCore\UploadedFile;

/**
 * PSR-17 factory for {@see UploadedFile} instances, built from an
 * already-open, readable stream backed by a real file (so it can later be
 * moved with {@see UploadedFile::moveTo()}).
 */
class UploadedFileFactory implements UploadedFileFactoryInterface
{
    /**
     * @throws InvalidArgumentException if `$stream` isn't readable or isn't backed by a real file.
     */
    #[\Override]
    public function createUploadedFile(
        StreamInterface $stream,
        ?int $size = null,
        int $error = \UPLOAD_ERR_OK,
        ?string $clientFilename = null,
        ?string $clientMediaType = null
    ): UploadedFileInterface {
        if (!\is_string($stream->getMetadata('uri')) || !$stream->isReadable()) {
            throw new InvalidArgumentException('File is not readable.');
        }

        return new UploadedFile($stream, $clientFilename, $clientMediaType, $size ?? $stream->getSize(), $error);
    }
}
