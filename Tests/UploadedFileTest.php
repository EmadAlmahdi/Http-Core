<?php

declare(strict_types=1);

namespace Temant\HttpCore\Tests;

use PHPUnit\Framework\TestCase;
use Temant\HttpCore\UploadedFile;
use Temant\HttpCore\Stream;
use RuntimeException;
use InvalidArgumentException;

final class UploadedFileTest extends TestCase
{
    public function testConstructorThrowsExceptionIfStringIsNotValidPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $file = new UploadedFile(
            'Hello world',
            'hello.txt',
            'text/plain',
            11,
            UPLOAD_ERR_OK
        );
    }

    public function testConstructorAcceptsPathAsStream(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($tempFile, 'Hello world');

        $file = new UploadedFile(
            $tempFile,
            'hello.txt',
            'text/plain',
            11,
            UPLOAD_ERR_OK
        );

        $this->assertSame(11, $file->getSize());
        $this->assertSame('hello.txt', $file->getClientFilename());
        $this->assertSame('text/plain', $file->getClientMediaType());
        $this->assertSame('Hello world', (string) $file->getStream());

        unlink($tempFile);
    }

    public function testGettersWork(): void
    {
        $resource = fopen('php://memory', 'wb+');
        if ($resource === false) {
            return;
        }
        $stream = new Stream($resource);
        $file = new UploadedFile(
            $stream,
            'test.txt',
            'text/plain',
            123,
            UPLOAD_ERR_OK
        );

        $this->assertSame(123, $file->getSize());
        $this->assertSame(UPLOAD_ERR_OK, $file->getError());
        $this->assertSame('test.txt', $file->getClientFilename());
        $this->assertSame('text/plain', $file->getClientMediaType());
        $this->assertInstanceOf(Stream::class, $file->getStream());
    }

    public function testMoveToWritesFile(): void
    {
        $resource = fopen('php://memory', 'wb+');
        if ($resource === false) {
            return;
        }
        $stream = new Stream($resource);
        $stream->write('Hello world');

        $file = new UploadedFile(
            $stream,
            'hello.txt',
            'text/plain',
            11,
            UPLOAD_ERR_OK
        );

        $tmpTarget = tempnam(sys_get_temp_dir(), 'upload_');
        $file->moveTo($tmpTarget);

        $this->assertSame('Hello world', file_get_contents($tmpTarget));
    }

    public function testMoveToRemovesOriginalFileWhenBackedByRealPath(): void
    {
        // PSR-7's UploadedFileInterface::moveTo() docblock: "The original
        // file or stream MUST be removed on completion." When the upload
        // is backed by a real file (as it always is for a genuine $_FILES
        // entry), moving it must not leave the source temp file behind.
        $tempFile = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($tempFile, 'Hello world');

        $file = new UploadedFile($tempFile, 'hello.txt', 'text/plain', 11, UPLOAD_ERR_OK);

        $target = tempnam(sys_get_temp_dir(), 'upload_');
        $file->moveTo($target);

        $this->assertSame('Hello world', file_get_contents($target));
        $this->assertFileDoesNotExist($tempFile);

        unlink($target);
    }

    public function testMoveToTwiceThrows(): void
    {
        $resource = fopen('php://memory', 'wb+');
        if ($resource === false) {
            return;
        }
        $stream = new Stream($resource);
        $file = new UploadedFile(
            $stream,
            'x',
            'text/plain',
            0,
            UPLOAD_ERR_OK
        );
        $target = tempnam(sys_get_temp_dir(), 'upload_');
        $file->moveTo($target);
        $this->expectException(RuntimeException::class);
        $file->moveTo($target);
    }

    public function testMoveToNotExistsTargetThrows(): void
    {
        $this->expectException(RuntimeException::class);

        $resource = fopen('php://memory', 'wb+');
        if ($resource === false) {
            return;
        }
        $stream = new Stream($resource);
        $file = new UploadedFile(
            $stream,
            'x',
            'text/plain',
            0,
            UPLOAD_ERR_OK
        );

        $file->moveTo(sys_get_temp_dir() . '/nonexistent_dir/file.txt');
    }

    public function testGetStreamAfterMoveThrows(): void
    {
        $resource = fopen('php://memory', 'wb+');
        if ($resource === false) {
            return;
        }
        $stream = new Stream($resource);
        $file = new UploadedFile(
            $stream,
            'x',
            'text/plain',
            0,
            UPLOAD_ERR_OK
        );
        $target = tempnam(sys_get_temp_dir(), 'upload_');
        $file->moveTo($target);
        $this->expectException(RuntimeException::class);
        $file->getStream();
    }

    public function testErrorPreventsStream(): void
    {
        $resource = fopen('php://memory', 'wb+');
        if ($resource === false) {
            return;
        }
        $stream = new Stream($resource);
        $file = new UploadedFile(
            $stream,
            'x',
            'text/plain',
            0,
            UPLOAD_ERR_NO_FILE
        );
        $this->expectException(RuntimeException::class);
        $file->getStream();
    }

    public function testErrorPreventsMove(): void
    {
        $resource = fopen('php://memory', 'wb+');
        if ($resource === false) {
            return;
        }
        $stream = new Stream($resource);
        $file = new UploadedFile(
            $stream,
            'x',
            'text/plain',
            0,
            UPLOAD_ERR_NO_FILE
        );
        $this->expectException(RuntimeException::class);
        $file->moveTo('anywhere');
    }

    public function testInvalidErrorCodeThrows(): void
    {
        $resource = fopen('php://memory', 'wb+');
        if ($resource === false) {
            return;
        }
        $stream = new Stream($resource);
        $this->expectException(InvalidArgumentException::class);
        new UploadedFile(
            $stream,
            'x',
            'y',
            0,
            999
        );
    }

    public function testInvalidTargetPathThrows(): void
    {
        $resource = fopen('php://memory', 'wb+');
        if ($resource === false) {
            return;
        }
        $stream = new Stream($resource);
        $file = new UploadedFile(
            $stream,
            'x',
            'text/plain',
            0,
            UPLOAD_ERR_OK
        );
        $this->expectException(InvalidArgumentException::class);
        $file->moveTo('');
    }
}