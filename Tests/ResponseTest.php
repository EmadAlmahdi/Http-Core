<?php

declare(strict_types=1);

namespace Temant\HttpCore\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Temant\HttpCore\Message\Response;
use Temant\HttpCore\Message\Stream;
use Psr\Http\Message\StreamInterface;

final class ResponseTest extends TestCase
{
    public function testDefaultConstructorSetsDefaults(): void
    {
        $response = new Response();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('OK', $response->getReasonPhrase());
        $this->assertInstanceOf(StreamInterface::class, $response->getBody());
        $this->assertSame('1.1', $response->getProtocolVersion());
        $this->assertSame([], $response->getHeaders());
    }

    public function testConstructorWithCustomValues(): void
    {
        $resource = fopen('php://temp', 'r+');
        if ($resource === false) {
            $this->markTestSkipped('Unable to open temporary stream');
        }

        $body = new Stream($resource);
        $headers = ['Content-Type' => ['text/plain']];
        $response = new Response(201, $headers, $body, '2.0', 'Created!');

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('Created!', $response->getReasonPhrase());
        $this->assertSame($body, $response->getBody());
        $this->assertSame(['Content-Type' => ['text/plain']], $response->getHeaders());
        $this->assertSame('2.0', $response->getProtocolVersion());
    }

    public function testInvalidStatusCodeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Response(99);
    }

    public function testWithStatusReturnsClone(): void
    {
        $response = new Response(200);
        $new = $response->withStatus(404, 'Not here');

        $this->assertNotSame($response, $new);
        $this->assertSame(404, $new->getStatusCode());
        $this->assertSame('Not here', $new->getReasonPhrase());
        // original unchanged
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testWithStatusSameCodeAndReasonReturnsSameInstance(): void
    {
        $response = new Response(200, [], null, '1.1', 'OK!');
        $same = $response->withStatus(200, 'OK!');
        $this->assertSame($response, $same);
    }

    public function testGetReasonPhraseFallsBackToDefault(): void
    {
        $response = new Response(404);
        $this->assertSame('Not Found', $response->getReasonPhrase());
    }

    public function testGetReasonPhraseEmptyIfUnknownCode(): void
    {
        $response = new Response(299); // 299 not in map but valid
        $this->assertSame('', $response->getReasonPhrase());
    }

    public function testWithStatusWithoutReasonPhraseFallsBackToDefault(): void
    {
        $response = new Response(200, [], null, '2', 'Custom');
        $new = $response->withStatus(201); // no reasonPhrase provided

        $this->assertSame(201, $new->getStatusCode());
        $this->assertSame('Created', $new->getReasonPhrase());
    }

    public function testWithHeaderFiltersSingleString(): void
    {
        $response = new Response();
        $new = $response->withHeader('X-Test', 'value');
        $this->assertSame(['value'], $new->getHeader('X-Test'));
    }

    public function testWithHeaderFiltersArray(): void
    {
        $response = new Response();
        $new = $response->withHeader('X-Test', ['a', 'b']);
        $this->assertSame(['a', 'b'], $new->getHeader('X-Test'));
    }

    public function testWithHeaderAllowsEmptyStringValue(): void
    {
        $response = new Response();
        $new = $response->withHeader('X-Test', '');

        $this->assertSame([''], $new->getHeader('X-Test'));
    }

    public function testWithHeaderThrowsOnEmptyArrayValue(): void
    {
        $response = new Response();
        $this->expectException(InvalidArgumentException::class);
        $response->withHeader('X-Test', []);
    }

    public function testWithHeaderThrowsOnCRLF(): void
    {
        $response = new Response();
        $this->expectException(InvalidArgumentException::class);
        $response->withHeader('X-Test', "bad\r\nvalue");
    }

    /**
     * Regression test: without PCRE's `D` modifier, `$` is satisfied just
     * before a trailing `\n`, so a value ending in exactly one newline
     * used to slip past the character class entirely unmatched.
     */
    public function testWithHeaderThrowsOnValueEndingInNewline(): void
    {
        $response = new Response();
        $this->expectException(InvalidArgumentException::class);
        $response->withHeader('X-Test', "value\n");
    }

    public function testWithHeaderThrowsOnNameEndingInNewline(): void
    {
        $response = new Response();
        $this->expectException(InvalidArgumentException::class);
        $response->withHeader("X-Test\n", 'value');
    }
}