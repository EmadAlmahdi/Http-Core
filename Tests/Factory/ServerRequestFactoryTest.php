<?php

declare(strict_types=1);

namespace Temant\HttpCore\Factory;

use Interop\Http\Factory\ServerRequestFactoryTestCase;
use InvalidArgumentException;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;

class ServerRequestFactoryTest extends ServerRequestFactoryTestCase
{
    /**
     * @var array<'_SERVER'|'_FILES'|'_GET'|'_POST'|'_COOKIE', mixed>
     */
    private array $originals = [];

    public function setUp(): void
    {
        parent::setUp();

        foreach (['_SERVER', '_FILES', '_GET', '_POST', '_COOKIE'] as $key) {
            $this->originals[$key] = $GLOBALS[$key];
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originals as $key => $value) {
            $GLOBALS[$key] = $value;
        }

        parent::tearDown();
    }

    /**
     * @return ServerRequestFactoryInterface
     */
    protected function createServerRequestFactory(): ServerRequestFactoryInterface
    {
        return new ServerRequestFactory();
    }

    /**
     * @param string $uri
     *
     * @return UriInterface
     */
    protected function createUri($uri): UriInterface
    {
        return new UriFactory()->createUri($uri);
    }

    public function testInValidUriThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);

        /** @phpstan-ignore argument.type */
        $this->factory->createServerRequest('GET', 123);
    }

    public function testFromGlobalsWithBasicRequest(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/test',
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'HTTP_HOST' => 'example.com',
            'HTTP_USER_AGENT' => 'TestAgent',
            'CONTENT_TYPE' => 'application/json'
        ];

        $request = ServerRequestFactory::fromGlobals();

        $this->assertInstanceOf(ServerRequestInterface::class, $request);
        $this->assertEquals('GET', $request->getMethod());
        $this->assertEquals('/test', $request->getUri()->getPath());
        $this->assertEquals('1.1', $request->getProtocolVersion());

        $headers = $request->getHeaders();
        $this->assertArrayHasKey('host', $headers);
        $this->assertEquals(['example.com'], $headers['host']);
        $this->assertArrayHasKey('user-agent', $headers);
        $this->assertEquals(['TestAgent'], $headers['user-agent']);
        $this->assertArrayHasKey('content-type', $headers);
        $this->assertEquals(['application/json'], $headers['content-type']);
    }

    public function testFromGlobalsWithPostRequest(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/submit',
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'HTTP_HOST' => 'example.com',
            'CONTENT_LENGTH' => '100'
        ];
        $_POST = ['name' => 'John', 'email' => 'john@example.com'];
        $_COOKIE = ['session' => 'abc123'];
        $_GET = ['page' => '1'];

        $request = ServerRequestFactory::fromGlobals();

        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals(['name' => 'John', 'email' => 'john@example.com'], $request->getParsedBody());
        $this->assertEquals(['session' => 'abc123'], $request->getCookieParams());
        $this->assertEquals(['page' => '1'], $request->getQueryParams());
        $this->assertArrayHasKey('content-length', $request->getHeaders());
        $this->assertEquals(['100'], $request->getHeader('content-length'));
    }

    public function testFromGlobalsWithArrayHeaders(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'HTTP_ACCEPT' => ['text/html', 'application/json'],
            'CONTENT_TYPE' => ['multipart/form-data']
        ];

        $request = ServerRequestFactory::fromGlobals();

        $headers = $request->getHeaders();
        $this->assertArrayHasKey('accept', $headers);
        $this->assertEquals(['text/html', 'application/json'], $headers['accept']);
        $this->assertArrayHasKey('content-type', $headers);
        $this->assertEquals(['multipart/form-data'], $headers['content-type']);
    }

    public function testFromGlobalsWithNonScalarHeaders(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'HTTP_X_CUSTOM' => new \stdClass(), // Non-scalar value
            'CONTENT_MD5' => null
        ];

        $request = ServerRequestFactory::fromGlobals();

        $headers = $request->getHeaders();
        $this->assertArrayHasKey('x-custom', $headers);
        $this->assertEquals([''], $headers['x-custom']);
        $this->assertArrayHasKey('content-md5', $headers);
        $this->assertEquals([''], $headers['content-md5']);
    }

    public function testFromGlobalsWithoutServerProtocol(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'example.com'
        ];

        $request = ServerRequestFactory::fromGlobals();

        $this->assertEquals('1.1', $request->getProtocolVersion());
    }

    public function testFromGlobalsWithInvalidRequestMethod(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => [], // Non-scalar
            'REQUEST_URI' => '/',
            'SERVER_PROTOCOL' => 'HTTP/1.1'
        ];

        $request = ServerRequestFactory::fromGlobals();

        $this->assertEquals('GET', $request->getMethod());
    }

    public function testFromGlobalsWithFiles(): void
    {
        $tempFile = tmpfile();
        $tempFilePath = $this->getFilePath($tempFile);
        fwrite($tempFile, 'test content');

        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/upload',
            'SERVER_PROTOCOL' => 'HTTP/1.1'
        ];

        $_FILES = [
            'file1' => [
                'tmp_name' => $tempFilePath,
                'name' => 'test.txt',
                'type' => 'text/plain',
                'size' => 12,
                'error' => UPLOAD_ERR_OK
            ],
            'files' => [
                'file2' => [
                    'tmp_name' => $tempFilePath,
                    'name' => 'test2.txt',
                    'type' => 'text/plain',
                    'size' => 12,
                    'error' => UPLOAD_ERR_OK
                ]
            ]
        ];

        $request = ServerRequestFactory::fromGlobals();
        $uploadedFiles = $request->getUploadedFiles();

        $this->assertArrayHasKey('file1', $uploadedFiles);
        $this->assertInstanceOf(UploadedFileInterface::class, $uploadedFiles['file1']);
        $this->assertEquals('test.txt', $uploadedFiles['file1']->getClientFilename());

        $this->assertArrayHasKey('files', $uploadedFiles);
        $this->assertIsArray($uploadedFiles['files']);
        $this->assertArrayHasKey('file2', $uploadedFiles['files']);
        $this->assertInstanceOf(UploadedFileInterface::class, $uploadedFiles['files']['file2']);

        fclose($tempFile);
    }

    public function testFromGlobalsWithUploadError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/upload',
            'SERVER_PROTOCOL' => 'HTTP/1.1'
        ];

        $_FILES = [
            'file' => [
                'tmp_name' => '',
                'name' => 'test.txt',
                'type' => 'text/plain',
                'size' => 0,
                'error' => UPLOAD_ERR_NO_FILE
            ]
        ];

        $request = ServerRequestFactory::fromGlobals();
        $uploadedFiles = $request->getUploadedFiles();

        $this->assertArrayHasKey('file', $uploadedFiles);
        $this->assertInstanceOf(UploadedFileInterface::class, $uploadedFiles['file']);
        $this->assertEquals(UPLOAD_ERR_NO_FILE, $uploadedFiles['file']->getError());
        $this->assertEquals(0, $uploadedFiles['file']->getSize());
    }

    public function testNormalizeFilesWithInvalidSpecification(): void
    {
        $factory = new ServerRequestFactory();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid value in files specification');

        $reflection = new \ReflectionClass($factory);
        $method = $reflection->getMethod('normalizeFiles');

        $method->invoke($factory, ['file' => 'invalid']);
    }

    public function testCreateUploadedFileFromSpecWithMissingRequiredFields(): void
    {
        $factory = new ServerRequestFactory();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('tmp_name and error are required');

        $reflection = new \ReflectionClass($factory);
        $method = $reflection->getMethod('createUploadedFileFromSpec');

        $method->invoke($factory, ['name' => 'test.txt']);
    }

    public function testCreateUploadedFileFromSpecWithInvalidTmpName(): void
    {
        $factory = new ServerRequestFactory();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid tmp_name in file specification');

        $reflection = new \ReflectionClass($factory);
        $method = $reflection->getMethod('createUploadedFileFromSpec');

        $method->invoke($factory, [
            'tmp_name' => '/invalid/path',
            'error' => UPLOAD_ERR_OK,
            'name' => 'test.txt'
        ]);
    }

    public function testCreateUploadedFileFromSpecWithNonUploadedFile(): void
    {
        $tempFile = tmpfile();
        $tempFilePath = $this->getFilePath($tempFile);

        $factory = new ServerRequestFactory();

        // Only expect exception if not in CLI mode
        if (PHP_SAPI !== 'cli') {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('File was not uploaded via HTTP POST');
        }

        $reflection = new \ReflectionClass($factory);
        $method = $reflection->getMethod('createUploadedFileFromSpec');

        try {
            $result = $method->invoke($factory, [
                'tmp_name' => $tempFilePath,
                'error' => UPLOAD_ERR_OK,
                'name' => 'test.txt'
            ]);

            if (PHP_SAPI === 'cli') {
                $this->assertInstanceOf(UploadedFileInterface::class, $result);
            }
        }
        finally {
            fclose($tempFile);
        }
    }

    public function testCreateUploadedFileFromSpecWithUnreadableFile(): void
    {
        $tempFile = tmpfile();
        $tempFilePath = $this->getFilePath($tempFile);
        fclose($tempFile);

        $factory = new ServerRequestFactory();

        $this->expectException(InvalidArgumentException::class);

        $reflection = new \ReflectionClass($factory);
        $method = $reflection->getMethod('createUploadedFileFromSpec');

        if (PHP_SAPI === 'cli') {
            $this->mockIsUploadedFile(true);
        }

        try {
            $method->invoke($factory, [
                'tmp_name' => $tempFilePath,
                'error' => UPLOAD_ERR_OK,
                'name' => 'test.txt'
            ]);
        }
        finally {
            if (PHP_SAPI === 'cli') {
                $this->restoreIsUploadedFile();
            }
        }
    }

    public function testCreateUploadedFileFromSpecWithAutoSizeDetection(): void
    {
        $tempFile = tmpfile();
        $tempFilePath = $this->getFilePath($tempFile);
        fwrite($tempFile, 'test content');

        $factory = new ServerRequestFactory();

        $reflection = new \ReflectionClass($factory);
        $method = $reflection->getMethod('createUploadedFileFromSpec');

        if (PHP_SAPI === 'cli') {
            $this->mockIsUploadedFile(true);
        }

        try {
            $file = $method->invoke($factory, [
                'tmp_name' => $tempFilePath,
                'error' => UPLOAD_ERR_OK,
                'name' => 'test.txt'
            ]);

            $this->assertInstanceOf(UploadedFileInterface::class, $file);
            $this->assertEquals(12, $file->getSize());
        }
        finally {
            fclose($tempFile);
            if (PHP_SAPI === 'cli') {
                $this->restoreIsUploadedFile();
            }
        }
    }

    private function mockIsUploadedFile(bool $returnValue): void
    {
        $GLOBALS['__is_uploaded_file_mock'] = $returnValue;

        if (!function_exists('is_uploaded_file')) {
            eval ('function is_uploaded_file($filename) { 
                return $GLOBALS["__is_uploaded_file_mock"] ?? \is_uploaded_file($filename); 
            }');
        }
    }

    private function restoreIsUploadedFile(): void
    {
        unset($GLOBALS['__is_uploaded_file_mock']);
    }

    /**
     * @param resource|false $tempFile
     * @return string
     */
    private function getFilePath($tempFile): string
    {
        if (!is_resource($tempFile)) {
            throw new InvalidArgumentException('Invalid resource provided');
        }

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
        $meta = stream_get_meta_data($tempFile);

        if (!isset($meta['uri'])) {
            $this->fail('Failed to get temporary file path for upload simulation.');
        }

        return $meta['uri'];
    }
}