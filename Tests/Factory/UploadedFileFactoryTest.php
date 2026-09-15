<?php

declare(strict_types=1);

namespace Temant\HttpCore\Tests\Factory;

use Interop\Http\Factory\UploadedFileFactoryTestCase;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use Temant\HttpCore\Factory\StreamFactory;
use Temant\HttpCore\Factory\UploadedFileFactory;
use Temant\HttpCore\Message\Stream;

class UploadedFileFactoryTest extends UploadedFileFactoryTestCase
{
    protected function createUploadedFileFactory(): UploadedFileFactory
    {
        return new UploadedFileFactory();
    }

    /**
     * @param string $content
     * @return StreamInterface
     */
    protected function createStream($content): StreamInterface
    {
        return new StreamFactory()->createStream($content);
    }

    public function testStreamNotReadableThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('File is not readable.');


        $file = tempnam(sys_get_temp_dir(), '__TEMANT__');
        $resource = fopen($file, 'w');
        if ($resource === false) {
            $this->markTestSkipped('Unable to open temporary stream');
        }

        $upload = new Stream($resource);
        $error = UPLOAD_ERR_OK;
        $clientFilename = 'test.txt';
        $clientMediaType = 'text/plain';

        $this->factory->createUploadedFile($upload, null, $error, $clientFilename, $clientMediaType);
    }
}