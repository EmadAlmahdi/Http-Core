<?php declare(strict_types=1);

namespace Temant\HttpCore\Tests;

use PHPUnit\Framework\TestCase;
use Temant\HttpCore\ServerRequest;
use Temant\HttpCore\Stream;
use Psr\Http\Message\UriInterface;
use InvalidArgumentException;

final class ServerRequestTest extends TestCase
{
    private function createUriMock(
        string $path = '/test',
        string $host = 'example.com',
        ?int $port = null
    ): UriInterface {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn($path);
        $uri->method('getHost')->willReturn($host);
        $uri->method('getPort')->willReturn($port);
        return $uri;
    }

    public function testConstructorAndDefaults(): void
    {
        $uri = $this->createUriMock();
        $request = new ServerRequest('GET', $uri);

        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/test', $request->getRequestTarget());
        $this->assertSame($uri, $request->getUri());
        $this->assertSame(['Host' => ['example.com']], $request->getHeaders());
        $this->assertInstanceOf(Stream::class, $request->getBody());
        $this->assertSame([], $request->getServerParams());
        $this->assertSame([], $request->getCookieParams());
        $this->assertSame([], $request->getQueryParams());
        $this->assertSame([], $request->getUploadedFiles());
        $this->assertNull($request->getParsedBody());
        $this->assertSame([], $request->getAttributes());
    }

    public function testWithMethod(): void
    {
        $uri = $this->createUriMock();
        $request = new ServerRequest('GET', $uri);
        $newRequest = $request->withMethod('post');

        $this->assertNotSame($request, $newRequest);
        $this->assertSame('POST', $newRequest->getMethod());
    }

    public function testWithRequestTargetAndInvalid(): void
    {
        $uri = $this->createUriMock();
        $request = new ServerRequest('GET', $uri);
        $newRequest = $request->withRequestTarget('/custom');

        $this->assertSame('/custom', $newRequest->getRequestTarget());

        $this->expectException(InvalidArgumentException::class);
        $request->withRequestTarget('bad target'); // whitespace invalid
    }

    public function testWithUriPreserveHost(): void
    {
        $uri1 = $this->createUriMock('/one', 'host1.com');
        $uri2 = $this->createUriMock('/two', 'host2.com');

        $request = new ServerRequest('GET', $uri1, [], ['host' => ['host1.com']]);
        $newRequestPreserve = $request->withUri($uri2, true);
        $newRequestNoPreserve = $request->withUri($uri2, false);

        $this->assertSame($uri2, $newRequestPreserve->getUri());
        $this->assertSame(['host1.com'], $newRequestPreserve->getHeader('host'));

        $this->assertSame($uri2, $newRequestNoPreserve->getUri());
        $this->assertSame([$uri2->getHost()], $newRequestNoPreserve->getHeader('host'));
    }

    public function testWithAddedHeaderAppendsToExisting(): void
    {
        $req = new ServerRequest('GET', $this->createUriMock())
            ->withHeader('X-Test', 'foo')
            ->withAddedHeader('X-Test', 'bar');

        $this->assertSame(['foo', 'bar'], $req->getHeader('x-test'));
    }

    public function testWithAddedHeaderCreatesNew(): void
    {
        $req = new ServerRequest('GET', $this->createUriMock())
            ->withAddedHeader('X-New', 'val');

        $this->assertSame(['val'], $req->getHeader('x-new'));
    }

    public function testWithAddedHeaderAllowsDuplicates(): void
    {
        $req = new ServerRequest('GET', $this->createUriMock())
            ->withHeader('X-Test', 'dup')
            ->withAddedHeader('X-Test', 'dup');

        $this->assertSame(['dup', 'dup'], $req->getHeader('x-test'));
    }

    public function testWithAddedHeaderRejectsEmptyValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ServerRequest('GET', $this->createUriMock())
            ->withAddedHeader('X-Fail', '');
    }

    public function testWithAddedHeaderRejectsInvalidChars(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ServerRequest('GET', $this->createUriMock()))
            ->withAddedHeader('X-Bad', "abc\r\n");
    }

    public function testBodyManipulation(): void
    {
        $uri = $this->createUriMock();
        $request = new ServerRequest('GET', $uri);

        $resource = fopen('php://temp', 'r+');
        if ($resource === false) {
            $this->markTestSkipped('Unable to open temporary stream');
        }

        $body = new Stream($resource);
        $newRequest = $request->withBody($body);

        $this->assertNotSame($request, $newRequest);
        $this->assertSame($body, $newRequest->getBody());
    }

    public function testCookieParams(): void
    {
        $uri = $this->createUriMock();
        $request = new ServerRequest('GET', $uri);

        $cookies = ['foo' => 'bar'];
        $newRequest = $request->withCookieParams($cookies);

        $this->assertNotSame($request, $newRequest);
        $this->assertSame($cookies, $newRequest->getCookieParams());
    }

    public function testQueryParams(): void
    {
        $uri = $this->createUriMock();
        $request = new ServerRequest('GET', $uri);

        $query = ['q' => 'search'];
        $newRequest = $request->withQueryParams($query);

        $this->assertNotSame($request, $newRequest);
        $this->assertSame($query, $newRequest->getQueryParams());
    }

    public function testUploadedFiles(): void
    {
        $uri = $this->createUriMock();
        $request = new ServerRequest('GET', $uri);

        $files = ['file1' => ['name' => 'test.txt']];
        $newRequest = $request->withUploadedFiles($files); // @phpstan-ignore argument.type

        $this->assertNotSame($request, $newRequest);
        $this->assertSame($files, $newRequest->getUploadedFiles());
    }

    public function testParsedBody(): void
    {
        $uri = $this->createUriMock();
        $request = new ServerRequest('GET', $uri);

        $parsed = ['key' => 'value'];
        $newRequest = $request->withParsedBody($parsed);
        $this->assertSame($parsed, $newRequest->getParsedBody());

        $obj = (object) ['foo' => 'bar'];
        $newRequestObj = $request->withParsedBody($obj);
        $this->assertSame($obj, $newRequestObj->getParsedBody());

        $newRequestNull = $request->withParsedBody(null);
        $this->assertNull($newRequestNull->getParsedBody());

        $this->expectException(InvalidArgumentException::class);
        $request->withParsedBody('invalid'); // @phpstan-ignore argument.type
    }

    public function testAttributes(): void
    {
        $uri = $this->createUriMock();
        $request = new ServerRequest('GET', $uri);

        $newRequest = $request->withAttribute('foo', 'bar');
        $this->assertSame('bar', $newRequest->getAttribute('foo'));
        $this->assertNull($newRequest->getAttribute('nonexistent'));
        $this->assertSame('default', $newRequest->getAttribute('nonexistent', 'default'));

        $removed = $newRequest->withoutAttribute('foo');
        $this->assertNull($removed->getAttribute('foo'));
    }
}