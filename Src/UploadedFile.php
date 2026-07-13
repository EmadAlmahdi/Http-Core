<?php

declare(strict_types=1);

namespace Temant\HttpCore;

use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use InvalidArgumentException;

/**
 * PSR-7 Uploaded File implementation.
 *
 * Wraps one entry from a file upload, whether it came from `$_FILES`
 * (via {@see \Temant\HttpCore\Factory\UploadedFileFactory}) or was built
 * directly from a stream or path. A file can only be moved once:
 * {@see moveTo()} marks it as moved, and both `moveTo()` and
 * {@see getStream()} refuse to run again afterward, mirroring how PHP's
 * own `move_uploaded_file()` behaves for a real upload.
 *
 * @link https://www.php-fig.org/psr/psr-7/ PSR-7 Specification
 */
final class UploadedFile implements UploadedFileInterface
{
    private readonly StreamInterface $stream;
    private bool $moved = false;

    /**
     * Construct a new UploadedFile instance.
     *
     * @param StreamInterface|string $stream Underlying stream or file path
     * @param ?int $size The file size in bytes
     * @param int $error PHP file upload error code
     * @param ?string $clientFilename The filename sent by the client
     * @param ?string $clientMediaType The media type sent by the client
     *
     * @throws InvalidArgumentException If the error code is invalid or stream cannot be created
     */
    public function __construct(
        StreamInterface|string $stream,
        private readonly ?string $clientFilename,
        private readonly ?string $clientMediaType,
        private readonly ?int $size,
        private readonly int $error
    ) {
        if ($error < \UPLOAD_ERR_OK || $error > \UPLOAD_ERR_EXTENSION) {
            throw new InvalidArgumentException('Invalid upload error code.');
        }

        if (\is_string($stream)) {
            $resource = @\fopen($stream, 'rb');
            if ($resource === false) {
                throw new InvalidArgumentException("Unable to open file: {$stream}");
            }
            $this->stream = new Stream($resource);
        } else {
            $this->stream = $stream;
        }
    }

    #[\Override]
    public function getStream(): StreamInterface
    {
        if ($this->moved) {
            throw new RuntimeException('Uploaded file has already been moved.');
        }
        if ($this->error !== \UPLOAD_ERR_OK) {
            throw new RuntimeException('Cannot retrieve stream due to upload error.');
        }
        return $this->stream;
    }

    #[\Override]
    public function moveTo(string $targetPath): void
    {
        if ($this->moved) {
            throw new RuntimeException('Uploaded file has already been moved.');
        }
        if (\trim($targetPath) === '') {
            throw new InvalidArgumentException('Invalid target path.');
        }
        if ($this->error !== \UPLOAD_ERR_OK) {
            throw new RuntimeException('Cannot move file due to upload error.');
        }

        $stream = $this->getStream();
        $stream->rewind();

        $dest = @\fopen($targetPath, 'wb');
        if ($dest === false) {
            throw new RuntimeException("Unable to open destination: {$targetPath}");
        }

        while (!$stream->eof()) {
            \fwrite($dest, $stream->read(8192));
        }
        \fclose($dest);

        $this->moved = true;
    }

    #[\Override]
    public function getSize(): ?int
    {
        return $this->size;
    }

    #[\Override]
    public function getError(): int
    {
        return $this->error;
    }

    #[\Override]
    public function getClientFilename(): ?string
    {
        return $this->clientFilename;
    }

    #[\Override]
    public function getClientMediaType(): ?string
    {
        return $this->clientMediaType;
    }
}