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
 * Unlike the other classes in this library, a `Stream` is not an immutable
 * value object - it's a thin, stateful wrapper around whatever `fopen()`
 * gave you, since that's what a stream actually is. `detach()` releases the
 * underlying resource without closing it (the caller takes ownership),
 * while `close()` releases and closes it. Readability/writability/
 * seekability are all determined once, from the resource's own mode
 * string, and cached for the lifetime of the wrapper.
 *
 * @link https://www.php-fig.org/psr/psr-7/ PSR-7 Specification
 */
final class Stream implements StreamInterface
{
    /** 
     * @var array<string, true> Readable stream modes 
     */
    private const array READABLE_MODES = [
        'r' => true,
        'r+' => true,
        'w+' => true,
        'a+' => true,
        'x+' => true,
        'c+' => true
    ];

    /** 
     * @var array<string, true> Writable stream modes 
     */
    private const array WRITABLE_MODES = [
        'w' => true,
        'w+' => true,
        'rw' => true,
        'r+' => true,
        'a' => true,
        'a+' => true,
        'x' => true,
        'x+' => true,
        'c' => true,
        'c+' => true
    ];

    /** @var resource|null The underlying stream resource */
    private $resource;

    /** @var int|null The size of the stream in bytes if known */
    private ?int $size = null;

    /** @var bool Whether the stream is seekable */
    private bool $seekable = false;

    /** @var bool Whether the stream is readable */
    private bool $readable = false;

    /** @var bool Whether the stream is writable */
    private bool $writable = false;

    /**
     * @var array{
     *     timed_out?: bool,
     *     blocked?: bool,
     *     eof?: bool,
     *     unread_bytes?: int,
     *     stream_type?: string,
     *     wrapper_type?: string,
     *     wrapper_data?: mixed,
     *     mode?: string,
     *     seekable?: bool,
     *     uri?: string,
     *     media_type?: string,
     *     base64?: bool
     * } Stream metadata
     */
    private array $metadata = [];

    /**
     * Stream constructor.
     *
     * @param resource $stream Open stream resource
     * @throws StreamException If $stream is not a valid stream resource
     */
    public function __construct($stream)
    {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new StreamException('Stream must be a valid resource of type stream');
        }

        $this->resource = $stream;
        $this->metadata = stream_get_meta_data($this->resource);

        $this->seekable = $this->metadata['seekable'];

        // Cache mode without 'b' and 't' flags for repeated use
        $mode = str_replace(['b', 't'], '', $this->metadata['mode']);
        $this->readable = isset(self::READABLE_MODES[$mode]);
        $this->writable = isset(self::WRITABLE_MODES[$mode]);
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function __toString(): string
    {
        try {
            if ($this->isReadable()) {
                $this->rewind();
                return $this->getContents();
            }
        } catch (Throwable $exception) {
            // Intentionally empty - return empty string on any error as per PSR-7
        }

        return '';
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function close(): void
    {
        if (!isset($this->resource) || !is_resource($this->resource)) {
            return;
        }

        $resource = $this->detach();
        if (is_resource($resource)) {
            fclose($resource);
        }
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function detach()
    {
        if (!isset($this->resource) || !is_resource($this->resource)) {
            return null;
        }

        $resource = $this->resource;

        // Reset all properties to detached state
        $this->resource = null;
        $this->size = null;
        $this->readable = false;
        $this->writable = false;
        $this->seekable = false;
        $this->metadata = [];

        return $resource;
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getSize(): ?int
    {
        if ($this->size !== null) {
            return $this->size;
        }

        if (!isset($this->resource) || !is_resource($this->resource)) {
            return null;
        }

        // Clear stat cache to ensure we get current data
        clearstatcache(true, $this->metadata['uri'] ?? '');

        /** @var array{size?: int}|false $stats */
        $stats = fstat($this->resource);

        return $this->size = ($stats !== false && isset($stats['size']))
            ? $stats['size']
            : null;
    }

    /**
     * @inheritDoc
     * @throws StreamDetachedException if stream is detached
     * @throws StreamException If unable to determine position
     */
    #[\Override]
    public function tell(): int
    {
        if (!isset($this->resource)) {
            throw new StreamDetachedException;
        }

        $position = ftell($this->resource);
        if ($position === false) {
            throw new StreamException('Unable to determine stream position');
        }

        return $position;
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function eof(): bool
    {
        return !isset($this->resource) || feof($this->resource);
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function isSeekable(): bool
    {
        return $this->seekable;
    }

    /**
     * @inheritDoc
     * @throws StreamDetachedException if stream is detached
     * @throws StreamNotSeekableException if stream is not seekable
     * @throws StreamException If seek operation fails
     */
    #[\Override]
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        if (!isset($this->resource) || !is_resource($this->resource)) {
            throw new StreamDetachedException;
        }

        if (!$this->seekable) {
            throw new StreamNotSeekableException;
        }

        if (fseek($this->resource, $offset, $whence) === -1) {
            throw new StreamException(
                sprintf('Unable to seek to offset %d with whence %d', $offset, $whence)
            );
        }
    }

    /**
     * @inheritDoc
     * 
     * @throws StreamDetachedException if stream is detached
     * @throws StreamNotSeekableException if stream is not seekable
     * @throws StreamException if seek operation fails
     */
    #[\Override]
    public function rewind(): void
    {
        $this->seek(0);
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function isWritable(): bool
    {
        return $this->writable;
    }

    /**
     * @inheritDoc
     * 
     * @throws StreamDetachedException if stream is detached
     * @throws StreamNotWritableException if stream is not writable
     * @throws StreamException If write operation fails
     */
    #[\Override]
    public function write(string $string): int
    {
        if (!isset($this->resource)) {
            throw new StreamDetachedException;
        }

        if (!$this->writable) {
            throw new StreamNotWritableException;
        }

        $bytesWritten = fwrite($this->resource, $string);

        if ($bytesWritten === false) {
            throw new StreamException('Unable to write to stream');
        }

        // Invalidate cached size as it may have changed
        $this->size = null;

        return $bytesWritten;
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function isReadable(): bool
    {
        return $this->readable;
    }

    /**
     * @inheritDoc
     * 
     * @throws StreamDetachedException if stream is detached
     * @throws StreamNotReadableException if stream is not readable
     * @throws StreamException If length is negative
     * @throws StreamException If read operation fails
     */
    #[\Override]
    public function read(int $length): string
    {
        if (!isset($this->resource)) {
            throw new StreamDetachedException;
        }

        if (!$this->readable) {
            throw new StreamNotReadableException;
        }

        if ($length < 0) {
            throw new StreamException('Length parameter cannot be negative');
        }

        if ($length === 0) {
            return '';
        }

        $data = fread($this->resource, $length);

        if ($data === false) {
            throw new StreamException('Unable to read from stream');
        }

        return $data;
    }

    /**
     * @inheritDoc
     * 
     * @throws StreamDetachedException if stream is detached
     * @throws StreamNotReadableException if stream is not readable
     * @throws StreamException If unable to get contents
     */
    #[\Override]
    public function getContents(): string
    {
        if (!isset($this->resource)) {
            throw new StreamDetachedException;
        }

        if (!$this->readable) {
            throw new StreamNotReadableException;
        }

        $contents = stream_get_contents($this->resource);

        if ($contents === false || ($contents === '' && !feof($this->resource))) {
            throw new StreamException('Unable to get stream contents');
        }

        return $contents;
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getMetadata(?string $key = null)
    {
        if (!isset($this->resource)) {
            return $key === null ? [] : null;
        }

        if ($key === null) {
            return $this->metadata;
        }

        return $this->metadata[$key] ?? null;
    }
}