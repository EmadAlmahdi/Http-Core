<?php

declare(strict_types=1);

namespace Temant\HttpCore\Tests;

use AllowDynamicProperties;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Temant\HttpCore\Exceptions\StreamDetachedException;
use Temant\HttpCore\Exceptions\StreamException;
use Temant\HttpCore\Message\Stream;

class StreamTest extends TestCase
{
    private function getReadableWritableStream(): Stream
    {
        $resource = fopen('php://memory', 'r+');
        if ($resource === false) {
            $this->markTestSkipped('Unable to open temporary stream');
        }
        return new Stream($resource);
    }

    public function testConstructorWithInvalidResourceType(): void
    {
        $this->expectException(StreamException::class);

        // Since PHP 8.0, curl_init() returns a CurlHandle object rather than
        // a resource, making it a convenient non-resource value to test with.
        new Stream(curl_init()); // @phpstan-ignore argument.type
    }

    public function testToStringAndEmptyAfterDetach(): void
    {
        $stream = $this->getReadableWritableStream();
        $stream->write("Hello");
        $this->assertSame("Hello", (string) $stream);

        $stream->detach();
        $this->assertSame('', (string) $stream);
    }

    public function testCloseAndDetachBehavior(): void
    {
        $stream = $this->getReadableWritableStream();
        $this->assertIsResource($stream->detach());
        $this->assertNull($stream->detach());
        $stream = $this->getReadableWritableStream();
        $stream->close();
        $this->assertFalse($stream->isReadable());
    }

    public function testGetSizeCachedAndAfterClose(): void
    {
        $stream = $this->getReadableWritableStream();
        $this->assertSame(0, $stream->getSize());
        // cached
        $this->assertSame(0, $stream->getSize());
        $stream->close();
        $this->assertNull($stream->getSize());
    }

    public function testTellAndTellFailure(): void
    {
        $stream = $this->getReadableWritableStream();
        $this->assertSame(0, $stream->tell());

        $detached = $this->getReadableWritableStream();
        $detached->detach();
        $this->expectException(StreamDetachedException::class);
        $detached->tell();
    }

    public function testEof(): void
    {
        $stream = $this->getReadableWritableStream();
        $this->assertFalse($stream->eof());
    }

    public function testSeekAndRewind(): void
    {
        $stream = $this->getReadableWritableStream();
        $stream->write("ABC");
        $stream->seek(0);
        $this->assertSame(0, $stream->tell());
        $stream->rewind();
        $this->assertSame(0, $stream->tell());
    }

    public function testSeekFailure(): void
    {
        $stream = $this->getReadableWritableStream();
        $this->expectException(StreamException::class);
        $stream->seek(999, 999);
    }

    public function testIsWritableReadableSeekable(): void
    {
        $stream = $this->getReadableWritableStream();
        $this->assertTrue($stream->isWritable());
        $this->assertTrue($stream->isReadable());
        $this->assertTrue($stream->isSeekable());
    }

    public function testWriteAndFailure(): void
    {
        $stream = $this->getReadableWritableStream();
        $this->assertSame(3, $stream->write('abc'));

        $detached = $this->getReadableWritableStream();
        $detached->detach();
        $this->expectException(StreamException::class);
        $detached->write('x');
    }

    public function testWriteNotWritable(): void
    {
        $resource = fopen('php://memory', 'r');
        if ($resource === false) {
            $this->markTestSkipped('Unable to open temporary stream');
        }

        $stream = new Stream($resource);
        $this->expectException(StreamException::class);
        $stream->write('abc');
    }

    public function testReadAndFailures(): void
    {
        $stream = $this->getReadableWritableStream();
        $stream->write('abc');
        $stream->rewind();
        $this->assertSame('ab', $stream->read(2));
        $this->assertSame('', $stream->read(0));

        $this->expectException(StreamException::class);
        $stream->read(-1);
    }

    public function testReadNotReadable(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'tst');

        $resource = fopen($file, 'w');
        if ($resource === false) {
            $this->markTestSkipped('Unable to open temporary stream');
        }

        $stream = new Stream($resource);

        unlink($file);
        $this->expectException(StreamException::class);
        $stream->read(1);
    }

    public function testGetContentsAndFailure(): void
    {
        $stream = $this->getReadableWritableStream();
        $stream->write('abc');
        $stream->rewind();
        $this->assertSame('abc', $stream->getContents());

        $detached = $this->getReadableWritableStream();
        $detached->detach();
        $this->expectException(StreamException::class);
        $detached->getContents();
    }

    public function testGetMetadata(): void
    {
        $stream = $this->getReadableWritableStream();
        $this->assertIsArray($stream->getMetadata());
        $this->assertIsString($stream->getMetadata('mode'));
        $this->assertNull($stream->getMetadata('nonexistent'));

        $detached = $this->getReadableWritableStream();
        $detached->detach();
        $this->assertSame([], $detached->getMetadata());
        $this->assertNull($detached->getMetadata('mode'));
    }

    public function testToStringWhenNotReadable(): void
    {
        $resource = fopen('php://memory', 'w');
        if ($resource === false) {
            $this->markTestSkipped('Unable to open temporary stream');
        }

        $stream = new Stream($resource);
        $this->assertSame('', (string) $stream);
    }

    public function testTellFailure(): void
    {
        /** @var resource[] $pipes */
        $pipes = [];
        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
        ];

        /** @var resource|false $proc */
        $proc = proc_open('echo "hello"', $descriptors, $pipes);
        if ($proc === false) {
            $this->fail('Failed to start process');
        }

        if (!isset($pipes[1]) || !is_resource($pipes[1])) {
            $this->fail('Stdout pipe is missing or invalid');
        }

        $stream = new Stream($pipes[1]);

        $this->expectException(StreamException::class);
        $stream->tell();

        // Close all pipes
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        proc_close($proc);
    }

    public function testEofReturnsTrueWhenResourceIsDetached(): void
    {
        $resource = fopen('php://temp', 'r+');
        if ($resource === false) {
            $this->markTestSkipped('Unable to open temporary stream');
        }

        $stream = new Stream($resource);
        $stream->detach(); // Now $this->resource is null
        $this->assertTrue($stream->eof());
    }

    public function testSeekNotSeekable(): void
    {
        $resource = fopen('php://output', 'w');
        if ($resource === false) {
            $this->markTestSkipped('Unable to open temporary stream');
        }

        $stream = new Stream($resource);
        $this->expectException(StreamException::class);
        $stream->seek(0);
    }

    public function testWriteFailure(): void
    {
        $resource = fopen('php://memory', 'r');
        if ($resource === false) {
            $this->markTestSkipped('Unable to open temporary stream');
        }

        $stream = new Stream($resource);
        $ref = ReflectionMethod::createFromMethodName($stream::class . '::write');
        $this->expectException(StreamException::class);
        $stream->write('data');
    }

    public function testWriteThrowsWhenFwriteFails(): void
    {
        stream_wrapper_register('broken-write', BrokenWriteStreamWrapper::class);

        $resource = fopen('broken-write://anything', 'w');
        if ($resource === false) {
            $this->markTestSkipped('Unable to open temporary stream');
        }

        $stream = new Stream($resource);
        $this->expectException(StreamException::class);
        $this->expectExceptionMessage('Unable to write to stream');

        $stream->write('foobar');

        stream_wrapper_unregister('broken-write');
    }

    public function testReadFailure(): void
    {
        $stream = $this->getReadableWritableStream();
        $res = $stream->detach();

        if (!$res) {
            $this->markTestSkipped('Unable to open temporary stream');
        }

        fclose($res);

        $resource = fopen('php://memory', 'r+');
        if ($resource === false) {
            $this->markTestSkipped('Unable to open temporary stream');
        }

        $detachedStream = new Stream($resource);
        $detachedStream->detach();
        $this->expectException(StreamException::class);
        $detachedStream->read(1);
    }

    public function testReadThrowsWhenFreadFails(): void
    {
        stream_wrapper_register('broken-read', BrokenStreamWrapper::class);

        $resource = fopen('broken-read://anything', 'r');
        if ($resource === false) {
            $this->markTestSkipped('Unable to open temporary stream');
        }

        $stream = new Stream($resource);

        $this->expectException(StreamException::class);
        $this->expectExceptionMessage('Unable to read from stream');

        $stream->read(10);

        stream_wrapper_unregister('broken-read');
    }

    public function testGetContentsFailure(): void
    {
        $resource = fopen('php://memory', 'r+');
        if ($resource === false) {
            $this->markTestSkipped('Unable to open temporary stream');
        }

        $detachedStream = new Stream($resource);
        $detachedStream->detach();
        $detachedStream->close();
        $this->expectException(StreamException::class);
        $detachedStream->getContents();
    }

    public function testToStringCatchesThrowableAndReturnsEmptyString(): void
    {
        $resource = fopen('php://temp', 'r+');
        if ($resource === false) {
            $this->markTestSkipped('Unable to open temporary stream');
        }

        fwrite($resource, 'Hello');
        $stream = new Stream($resource);
        fclose($resource);

        $this->assertSame('', (string) $stream);
    }

    public function testGetSizeReturnsNullWhenFstatFails(): void
    {
        $resource = fopen('php://input', 'r');
        if ($resource === false) {
            $this->markTestSkipped('Unable to open temporary stream');
        }

        $stream = new Stream($resource);
        $this->assertNull($stream->getSize());
    }

    public function testGetContentsThrowsWhenNotReadable(): void
    {
        $resource = fopen('php://output', 'w');
        if ($resource === false) {
            $this->markTestSkipped('Unable to open temporary stream');
        }

        $stream = new Stream($resource); // write-only
        $this->expectException(StreamException::class);
        $this->expectExceptionMessage('Stream is not readable');
        $stream->getContents();
    }

    public function testGetContentsThrowsWhenStreamGetContentsFails(): void
    {
        stream_wrapper_register('broken', BrokenStreamWrapper::class);

        $resource = fopen('broken://anything', 'r');
        if ($resource === false) {
            $this->markTestSkipped('Unable to open temporary stream');
        }

        $stream = new Stream($resource);

        $this->expectException(StreamException::class);
        $this->expectExceptionMessage('Unable to get stream contents');
        $stream->getContents();

        stream_wrapper_unregister('broken');
    }
}

#[AllowDynamicProperties()]
class BrokenStreamWrapper
{
    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        return true;
    }

    public function stream_read(int $count): bool
    {
        return false;
    }

    public function stream_eof(): bool
    {
        return false; // not really EOF
    }

    public function stream_stat(): false
    {
        return false; // force fstat() failure
    }
}

#[AllowDynamicProperties()]
class BrokenWriteStreamWrapper
{
    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        return true;
    }

    public function stream_write(string $data): bool
    {
        return false; // force fwrite() failure
    }

    public function stream_eof(): bool
    {
        return false;
    }
}
