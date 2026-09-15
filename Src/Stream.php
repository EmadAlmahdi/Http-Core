<?php

declare(strict_types=1);

namespace Temant\HttpCore;

use Psr\Http\Message\StreamInterface;
use Temant\HttpCore\Exceptions\StreamDetachedException;
use Temant\HttpCore\Exceptions\StreamException;
use Temant\HttpCore\Exceptions\StreamNotReadableException;
use Temant\HttpCore\Exceptions\StreamNotSeekableException;
use Temant\HttpCore\Exceptions\StreamNotWritableException;
use Throwable;

/**
 * PSR-7 stream implementation wrapping a native PHP stream resource.
 *
 * Unlike every other class in this library, a `Stream` is a thin, stateful
 * wrapper around a resource rather than an immutable value object - that's
 * what a stream actually is. {@see detach()} releases the underlying
 * resource without closing it, handing ownership to the caller; {@see
 * close()} releases and closes it. Readability, writability, and
 * seekability are each determined once, from the resource's own mode
 * string at construction time, and cached for the wrapper's lifetime -
 * none of those three can change for an already-open PHP stream resource.
 *
 * @link https://www.php-fig.org/psr/psr-7/ PSR-7 Specification
 */
final class Stream implements StreamInterface
{
    /** Stream modes (with the `b`/`t` flags stripped) that permit reading. */
    private const array READABLE_MODES = [
        'r' => true, 'r+' => true, 'w+' => true,
        'a+' => true, 'x+' => true, 'c+' => true,
    ];

    /** Stream modes (with the `b`/`t` flags stripped) that permit writing. */
    private const array WRITABLE_MODES = [
        'w' => true, 'w+' => true, 'rw' => true, 'r+' => true,
        'a' => true, 'a+' => true, 'x' => true, 'x+' => true,
        'c' => true, 'c+' => true,
    ];

    /** @var resource|null Null once {@see detach()} has released it. */
    private $resource;

    private ?int $size = null;

    private bool $seekable;

    private bool $readable;

    private bool $writable;

    /**
     * @var array<string, mixed>
     */
    private array $metadata;

    /**
     * @param resource $stream An already-open stream resource.
     * @throws StreamException if `$stream` is not a valid stream resource.
     */
    public function __construct($stream)
    {
        if (!\is_resource($stream) || \get_resource_type($stream) !== 'stream') {
            throw new StreamException('Stream must be a valid resource of type stream.');
        }

        $this->resource = $stream;
        $this->metadata = \stream_get_meta_data($stream);

        /** @var bool $seekable */
        $seekable = $this->metadata['seekable'];
        $this->seekable = $seekable;

        /** @var string $rawMode */
        $rawMode = $this->metadata['mode'];
        $mode = \str_replace(['b', 't'], '', $rawMode);
        $this->readable = isset(self::READABLE_MODES[$mode]);
        $this->writable = isset(self::WRITABLE_MODES[$mode]);
    }

    #[\Override]
    public function __toString(): string
    {
        try {
            if ($this->isReadable()) {
                $this->rewind();
                return $this->getContents();
            }
        } catch (Throwable) {
            // PSR-7 requires __toString() to fail silently, returning ''.
        }

        return '';
    }

    #[\Override]
    public function close(): void
    {
        $resource = $this->detach();
        if ($resource !== null) {
            \fclose($resource);
        }
    }

    /**
     * @return resource|null
     */
    #[\Override]
    public function detach()
    {
        $resource = $this->resource;
        $this->resource = null;
        $this->size = null;
        $this->seekable = false;
        $this->readable = false;
        $this->writable = false;
        $this->metadata = [];

        return $resource;
    }

    #[\Override]
    public function getSize(): ?int
    {
        if ($this->size !== null) {
            return $this->size;
        }

        if ($this->resource === null) {
            return null;
        }

        \clearstatcache(true, is_string($this->metadata['uri'] ?? null) ? $this->metadata['uri'] : '');

        $stats = \fstat($this->resource);

        return $this->size = $stats !== false ? $stats['size'] : null;
    }

    /**
     * @throws StreamDetachedException if the stream is detached.
     * @throws StreamException if the position can't be determined.
     */
    #[\Override]
    public function tell(): int
    {
        if ($this->resource === null) {
            throw new StreamDetachedException();
        }

        $position = \ftell($this->resource);
        if ($position === false) {
            throw new StreamException('Unable to determine stream position.');
        }

        return $position;
    }

    #[\Override]
    public function eof(): bool
    {
        return $this->resource === null || \feof($this->resource);
    }

    #[\Override]
    public function isSeekable(): bool
    {
        return $this->seekable;
    }

    /**
     * @throws StreamDetachedException if the stream is detached.
     * @throws StreamNotSeekableException if the stream is not seekable.
     * @throws StreamException if the seek fails.
     */
    #[\Override]
    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        if ($this->resource === null) {
            throw new StreamDetachedException();
        }

        if (!$this->seekable) {
            throw new StreamNotSeekableException();
        }

        if (\fseek($this->resource, $offset, $whence) === -1) {
            throw new StreamException(\sprintf('Unable to seek to offset %d with whence %d.', $offset, $whence));
        }
    }

    /**
     * @throws StreamDetachedException if the stream is detached.
     * @throws StreamNotSeekableException if the stream is not seekable.
     * @throws StreamException if the seek fails.
     */
    #[\Override]
    public function rewind(): void
    {
        $this->seek(0);
    }

    #[\Override]
    public function isWritable(): bool
    {
        return $this->writable;
    }

    /**
     * @throws StreamDetachedException if the stream is detached.
     * @throws StreamNotWritableException if the stream is not writable.
     * @throws StreamException if the write fails.
     */
    #[\Override]
    public function write(string $string): int
    {
        if ($this->resource === null) {
            throw new StreamDetachedException();
        }

        if (!$this->writable) {
            throw new StreamNotWritableException();
        }

        $bytesWritten = \fwrite($this->resource, $string);
        if ($bytesWritten === false) {
            throw new StreamException('Unable to write to stream.');
        }

        $this->size = null;

        return $bytesWritten;
    }

    #[\Override]
    public function isReadable(): bool
    {
        return $this->readable;
    }

    /**
     * @throws StreamDetachedException if the stream is detached.
     * @throws StreamNotReadableException if the stream is not readable.
     * @throws StreamException if `$length` is negative, or the read fails.
     */
    #[\Override]
    public function read(int $length): string
    {
        if ($this->resource === null) {
            throw new StreamDetachedException();
        }

        if (!$this->readable) {
            throw new StreamNotReadableException();
        }

        if ($length < 0) {
            throw new StreamException('Length parameter cannot be negative.');
        }

        if ($length === 0) {
            return '';
        }

        $data = \fread($this->resource, $length);
        if ($data === false) {
            throw new StreamException('Unable to read from stream.');
        }

        return $data;
    }

    /**
     * @throws StreamDetachedException if the stream is detached.
     * @throws StreamNotReadableException if the stream is not readable.
     * @throws StreamException if reading to the end fails.
     */
    #[\Override]
    public function getContents(): string
    {
        if ($this->resource === null) {
            throw new StreamDetachedException();
        }

        if (!$this->readable) {
            throw new StreamNotReadableException();
        }

        $contents = \stream_get_contents($this->resource);
        if ($contents === false || ($contents === '' && !\feof($this->resource))) {
            throw new StreamException('Unable to get stream contents.');
        }

        return $contents;
    }

    #[\Override]
    public function getMetadata(?string $key = null)
    {
        if ($this->resource === null) {
            return $key === null ? [] : null;
        }

        if ($key === null) {
            return $this->metadata;
        }

        return $this->metadata[$key] ?? null;
    }
}
