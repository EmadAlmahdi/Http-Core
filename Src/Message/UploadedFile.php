<?php

declare(strict_types=1);

namespace Temant\HttpCore\Message;

use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use Temant\HttpCore\Factory\UploadedFileFactory;

use function fclose;
use function fopen;
use function fwrite;
use function is_file;
use function is_string;
use function move_uploaded_file;
use function rename;
use function trim;

use const PHP_SAPI;
use const UPLOAD_ERR_EXTENSION;
use const UPLOAD_ERR_OK;

/**
 * PSR-7 Uploaded File implementation.
 *
 * Wraps one entry from a file upload, whether it came from `$_FILES` (via
 * {@see UploadedFileFactory}) or was built directly from a stream or path.
 * A file can only be moved once: {@see moveTo()} marks it as moved, and
 * both `moveTo()` and {@see getStream()} refuse to run again afterward,
 * mirroring how PHP's own `move_uploaded_file()` behaves for a real
 * upload.
 *
 * When the wrapped stream is backed by a real file on disk, {@see moveTo()}
 * moves it with `move_uploaded_file()` (or `rename()` under a CLI SAPI,
 * where `move_uploaded_file()` always fails since there's no upload to
 * verify). This isn't just an optimization over copying bytes by hand:
 * PSR-7 calls out that `is_uploaded_file()`/`move_uploaded_file()` SHOULD
 * be used specifically because they verify the file genuinely arrived via
 * an HTTP upload - without that check, a request that lied about
 * `tmp_name` could trick an application into moving (and thereby
 * exposing, via whatever the destination is served as) an arbitrary file
 * it otherwise has no business touching.
 *
 * @see UploadedFileInterface The PSR-7 contract this class implements.
 * @link https://www.php-fig.org/psr/psr-7/ PSR-7 Specification
 */
final class UploadedFile implements UploadedFileInterface
{
    private readonly StreamInterface $stream;
    private bool $moved = false;

    /**
     * @param StreamInterface|string $stream Underlying stream, or a path to open one from.
     * @param ?string $clientFilename The filename sent by the client.
     * @param ?string $clientMediaType The media type sent by the client.
     * @param ?int $size The file size in bytes, if known.
     * @param int $error One of PHP's `UPLOAD_ERR_*` constants.
     * @throws InvalidArgumentException if `$error` is invalid, or `$stream` is a path that can't be opened.
     */
    public function __construct(
        StreamInterface|string $stream,
        private readonly ?string $clientFilename,
        private readonly ?string $clientMediaType,
        private readonly ?int $size,
        private readonly int $error
    ) {
        if ($error < UPLOAD_ERR_OK || $error > UPLOAD_ERR_EXTENSION) {
            throw new InvalidArgumentException('Invalid upload error code.');
        }

        $this->stream = is_string($stream) ? self::openFile($stream) : $stream;
    }

    /**
     * @throws InvalidArgumentException if the file can't be opened.
     */
    private static function openFile(string $path): Stream
    {
        $resource = @fopen($path, 'rb');
        if ($resource === false) {
            throw new InvalidArgumentException("Unable to open file: {$path}.");
        }

        return new Stream($resource);
    }

    /**
     * @inheritDoc
     * @see UploadedFileInterface::getStream()
     * @throws RuntimeException if the file has already been moved, or the upload itself failed.
     */
    public function getStream(): StreamInterface
    {
        if ($this->moved) {
            throw new RuntimeException('Uploaded file has already been moved.');
        }

        if ($this->error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Cannot retrieve stream due to upload error.');
        }

        return $this->stream;
    }

    /**
     * @inheritDoc
     * @see UploadedFileInterface::moveTo()
     * @throws InvalidArgumentException if `$targetPath` is empty.
     * @throws RuntimeException if the file has already been moved, the upload failed, or the move itself fails.
     */
    public function moveTo(string $targetPath): void
    {
        if ($this->moved) {
            throw new RuntimeException('Uploaded file has already been moved.');
        }

        if (trim($targetPath) === '') {
            throw new InvalidArgumentException('Invalid target path.');
        }

        if ($this->error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Cannot move file due to upload error.');
        }

        $stream = $this->getStream();
        $sourcePath = $stream->getMetadata('uri');

        if (is_string($sourcePath) && $sourcePath !== '' && is_file($sourcePath)) {
            $this->moveRealFile($sourcePath, $targetPath);
        } else {
            $this->copyStreamTo($stream, $targetPath);
        }

        $this->moved = true;
    }

    /**
     * Moves a stream backed by a real file on disk, using the
     * SAPI-appropriate native function so the original is both verified
     * (in a real request) and removed as part of the same call - see the
     * class docblock for why that verification matters.
     *
     * @throws RuntimeException if the move fails.
     */
    private function moveRealFile(string $sourcePath, string $targetPath): void
    {
        $moved = PHP_SAPI === 'cli'
            ? @rename($sourcePath, $targetPath)
            : @move_uploaded_file($sourcePath, $targetPath);

        if ($moved === false) {
            throw new RuntimeException("Unable to move uploaded file to: {$targetPath}.");
        }
    }

    /**
     * Falls back to a plain byte-for-byte copy for a stream that isn't
     * backed by a real file (e.g. one built directly on `php://memory` for
     * testing) - there's no filesystem move available for that, and
     * nothing else to remove afterward besides the stream itself.
     *
     * @throws RuntimeException if the destination can't be opened.
     */
    private function copyStreamTo(StreamInterface $stream, string $targetPath): void
    {
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $dest = @fopen($targetPath, 'wb');
        if ($dest === false) {
            throw new RuntimeException("Unable to open destination: {$targetPath}.");
        }

        while (!$stream->eof()) {
            fwrite($dest, $stream->read(8192));
        }
        fclose($dest);
    }

    /**
     * @inheritDoc
     * @see UploadedFileInterface::getSize()
     */
    public function getSize(): ?int
    {
        return $this->size;
    }

    /**
     * @inheritDoc
     * @see UploadedFileInterface::getError()
     */
    public function getError(): int
    {
        return $this->error;
    }

    /**
     * @inheritDoc
     * @see UploadedFileInterface::getClientFilename()
     */
    public function getClientFilename(): ?string
    {
        return $this->clientFilename;
    }

    /**
     * @inheritDoc
     * @see UploadedFileInterface::getClientMediaType()
     */
    public function getClientMediaType(): ?string
    {
        return $this->clientMediaType;
    }
}
