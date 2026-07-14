<?php

declare(strict_types=1);

namespace Temant\HttpCore\Tests;

use PHPUnit\Framework\TestCase;
use Temant\HttpCore\Request;
use Temant\HttpCore\Stream;
use Psr\Http\Message\UriInterface;
use InvalidArgumentException;

final class RequestTest extends TestCase
{
    private function createUriMock(
        string $path = '/test',
        string $query = '',
        string $host = 'example.com',
        ?int $port = null
    ): UriInterface {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn($path);
        $uri->method('getQuery')->willReturn($query);
        $uri->method('getHost')->willReturn($host);
        $uri->method('getPort')->willReturn($port);
        return $uri;
    }

    public function testConstructorSetsDefaultsAndHostHeader(): void
    {
        $uri = $this->createUriMock();
        $request = new Request('GET', $uri);

        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/test', $request->getRequestTarget());
        $this->assertSame('example.com', $request->getHeaderLine('host'));
        $this->assertInstanceOf(Stream::class, $request->getBody());
    }

    public function testConstructorWithInvalidProtocolVersion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $uri = $this->createUriMock();
        new Request('GET', $uri, [], null, '3.0');
    }

    public function testConstructorThrowsOnEmptyMethod(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('HTTP method cannot be empty');

        $uri = $this->createUriMock();
        new Request('', $uri);
    }

    public function testConstructorThrowsOnInvalidMethod(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid HTTP method: BAD METHOD');

        $uri = $this->createUriMock();
        new Request('BAD METHOD', $uri);
    }

    public function testGetRequestTargetWithQuery(): void
    {
        $uri = $this->createUriMock('/abc', 'x=1&y=2');
        $request = new Request('GET', $uri);

        $this->assertSame('/abc?x=1&y=2', $request->getRequestTarget());
    }

    public function testGetRequestTargetEmptyPathReturnsSlash(): void
    {
        $uri = $this->createUriMock('', '');
        $request = new Request('GET', $uri);

        $this->assertSame('/', $request->getRequestTarget());
    }

    public function testWithRequestTarget(): void
    {
        $uri = $this->createUriMock();
        $request = new Request('GET', $uri);
        $newRequest = $request->withRequestTarget('/custom');

        $this->assertNotSame($request, $newRequest);
        $this->assertSame('/custom', $newRequest->getRequestTarget());
    }

    public function testWithRequestTargetThrowsOnWhitespace(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Request target cannot contain whitespace');

        $uri = $this->createUriMock();
        $request = new Request('GET', $uri);
        $request->withRequestTarget('/bad target');
    }

    public function testWithMethod(): void
    {
        $uri = $this->createUriMock();
        $request = new Request('GET', $uri);
        $newRequest = $request->withMethod('post');

        $this->assertNotSame($request, $newRequest);
        // PSR-7's RequestInterface::withMethod() docblock: "HTTP method
        // names are case-sensitive and thus implementations SHOULD NOT
        // modify the given string" - case is preserved exactly as given.
        $this->assertSame('post', $newRequest->getMethod());
    }

    public function testWithMethodReturnsSameForSameMethod(): void
    {
        $uri = $this->createUriMock();
        $request = new Request('GET', $uri);

        $this->assertSame($request, $request->withMethod('GET'));
    }

    public function testWithUri(): void
    {
        $uri1 = $this->createUriMock('/one', '', 'host1.com');
        $uri2 = $this->createUriMock('/two', '', 'host2.com');

        $request = new Request('GET', $uri1);
        $new = $request->withUri($uri2, false);

        $this->assertNotSame($request, $new);
        $this->assertSame('host2.com', $new->getHeaderLine('host'));
    }

    public function testWithUriPreserveHostKeepsOriginal(): void
    {
        $uri1 = $this->createUriMock('/one', '', 'host1.com');
        $uri2 = $this->createUriMock('/two', '', 'host2.com');

        $request = new Request('GET', $uri1);
        $new = $request->withUri($uri2, true);

        $this->assertSame('host1.com', $new->getHeaderLine('host'));
    }

    public function testWithUriReturnsSameInstanceForSameUri(): void
    {
        $uri = $this->createUriMock('/same', '');
        $request = new Request('GET', $uri);

        $newRequest = $request->withUri($uri);
        $this->assertSame($request, $newRequest);
    }

    public function testWithUriPreserveHostSetsHostIfOriginalMissing(): void
    {
        $uri1 = $this->createUriMock('/one', '', '');
        $request = new Request('GET', $uri1);

        $uri2 = $this->createUriMock('/two', '', 'host2.com');
        $new = $request->withUri($uri2, true);

        $this->assertSame('host2.com', $new->getHeaderLine('host'));
    }

    public function testGetUriReturnsSameInstance(): void
    {
        $uri = $this->createUriMock('/x', '');
        $request = new Request('GET', $uri);

        $this->assertSame($uri, $request->getUri());
    }

    public function testConstructorPreservesGivenHostHeader(): void
    {
        $uri = $this->createUriMock('/path', '', 'ignored.com');
        $request = new Request('GET', $uri, ['Host' => ['custom.com']]);

        $this->assertSame('custom.com', $request->getHeaderLine('host'));
    }

    public function testConstructorWithPort(): void
    {
        $uri = $this->createUriMock('/test', '', 'example.com', 8080);
        $request = new Request('GET', $uri);

        $this->assertSame('example.com:8080', $request->getHeaderLine('host'));
    }

    public function testConstructorWithoutHostDoesNotSetHostHeader(): void
    {
        $uri = $this->createUriMock('/nohost', '', '');
        $request = new Request('GET', $uri);

        $this->assertFalse($request->hasHeader('host'));
        $this->assertSame([], $request->getHeader('host'));
    }

    public function testWithBody(): void
    {
        $uri = $this->createUriMock();
        $request = new Request('GET', $uri);

        $resource = fopen('php://temp', 'r+');
        if ($resource === false) {
            $this->markTestSkipped('Unable to open temporary stream');
        }

        $body = new Stream($resource);
        $new = $request->withBody($body);
        $this->assertSame($body, $new->getBody());
    }

    public function testWithProtocolVersionReturnsSameInstanceWhenUnchanged(): void
    {
        $uri = $this->createUriMock();
        $request = new Request('GET', $uri);

        // Default protocol version is '1.1'
        $same = $request->withProtocolVersion('1.1');
        $this->assertSame($request, $same);
    }

    public function testWithProtocolVersionClonesAndSetsNewVersion(): void
    {
        $uri = $this->createUriMock();
        $request = new Request('GET', $uri);

        $new = $request->withProtocolVersion('1.0');
        $this->assertNotSame($request, $new);
        $this->assertSame('1.0', $new->getProtocolVersion());
        $this->assertSame('1.1', $request->getProtocolVersion());
    }

    public function testWithHeaderClonesWhenValueChanges(): void
    {
        $uri = $this->createUriMock();
        $request = new Request('GET', $uri);

        $new = $request->withHeader('X-Test', 'value');
        $this->assertNotSame($request, $new);
        $this->assertSame(['value'], $new->getHeader('x-test'));
        $this->assertSame([], $request->getHeader('x-test'));
    }

    public function testWithHeaderReturnsSameInstanceWhenValueUnchanged(): void
    {
        $uri = $this->createUriMock();
        $request = new Request('GET', $uri, ['X-Test' => ['value']]);

        $same = $request->withHeader('X-Test', 'value');
        $this->assertSame($request, $same);
    }

    public function testWithHeaderPreservesExactCaseInGetHeaders(): void
    {
        $uri = $this->createUriMock('/test', '', '');
        $request = new Request('GET', $uri);

        $new = $request->withHeader('X-Request-Id', 'abc');

        $this->assertSame(['X-Request-Id' => ['abc']], $new->getHeaders());
        $this->assertSame(['abc'], $new->getHeader('x-request-id'));
        $this->assertTrue($new->hasHeader('X-REQUEST-ID'));
    }

    public function testWithHeaderReplacesExistingHeaderRegardlessOfCase(): void
    {
        $uri = $this->createUriMock('/test', '', '');
        $request = new Request('GET', $uri, ['x-test' => ['old']]);

        $new = $request->withHeader('X-Test', 'new');

        $this->assertSame(['X-Test' => ['new']], $new->getHeaders());
    }

    public function testWithAddedHeaderKeepsOriginalCaseOfExistingHeader(): void
    {
        $uri = $this->createUriMock('/test', '', '');
        $request = new Request('GET', $uri, ['X-Test' => ['foo']]);

        $new = $request->withAddedHeader('x-test', 'bar');

        $this->assertSame(['X-Test' => ['foo', 'bar']], $new->getHeaders());
    }

    public function testWithoutHeaderRemovesHeader(): void
    {
        $uri = $this->createUriMock();
        $request = new Request('GET', $uri, ['X-Test' => ['value']]);

        $new = $request->withoutHeader('x-test');
        $this->assertNotSame($request, $new);
        $this->assertFalse($new->hasHeader('x-test'));
        $this->assertSame(['value'], $request->getHeader('x-test')); // original unchanged
    }

    public function testWithoutHeaderReturnsSameInstanceWhenHeaderMissing(): void
    {
        $uri = $this->createUriMock();
        $request = new Request('GET', $uri);

        $same = $request->withoutHeader('x-test');
        $this->assertSame($request, $same);
    }

    public function testGetBodyReturnsStream(): void
    {
        $uri = $this->createUriMock();

        $resource = fopen('php://temp', 'r+');
        if ($resource === false) {
            $this->markTestSkipped('Unable to open temporary stream');
        }

        $stream = new Stream($resource);
        $request = new Request('GET', $uri, [], $stream);

        $this->assertSame($stream, $request->getBody());
    }

    public function testWithBodyClonesAndSetsNewStream(): void
    {
        $uri = $this->createUriMock();

        $resource1 = fopen('php://temp', 'r+');
        $resource2 = fopen('php://temp', 'r+');

        if ($resource1 === false || $resource2 === false) {
            $this->markTestSkipped('Unable to open temporary streams');
        }

        $stream1 = new Stream($resource1);
        $stream2 = new Stream($resource2);
        $request = new Request('GET', $uri, [], $stream1);

        $new = $request->withBody($stream2);
        $this->assertNotSame($request, $new);
        $this->assertSame($stream2, $new->getBody());
        $this->assertSame($stream1, $request->getBody()); // original unchanged
    }

    public function testWithBodyReturnsSameInstanceIfSameStream(): void
    {
        $uri = $this->createUriMock();
        $resource = fopen('php://temp', 'r+');
        if ($resource === false) {
            $this->markTestSkipped('Unable to open temporary stream');
        }

        $stream = new Stream($resource);
        $request = new Request('GET', $uri, [], $stream);
        $same = $request->withBody($stream);
        $this->assertSame($request, $same);
    }
}