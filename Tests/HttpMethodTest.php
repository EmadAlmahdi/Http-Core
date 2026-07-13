<?php declare(strict_types=1);

namespace Temant\HttpCore\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UriInterface;
use Temant\HttpCore\HttpMethod;
use Temant\HttpCore\Request;

final class HttpMethodTest extends TestCase
{
    public function testSafeMethods(): void
    {
        $this->assertTrue(HttpMethod::Get->isSafe());
        $this->assertTrue(HttpMethod::Head->isSafe());
        $this->assertTrue(HttpMethod::Options->isSafe());
        $this->assertTrue(HttpMethod::Trace->isSafe());

        $this->assertFalse(HttpMethod::Post->isSafe());
        $this->assertFalse(HttpMethod::Put->isSafe());
        $this->assertFalse(HttpMethod::Patch->isSafe());
        $this->assertFalse(HttpMethod::Delete->isSafe());
        $this->assertFalse(HttpMethod::Connect->isSafe());
    }

    public function testIdempotentMethods(): void
    {
        $this->assertTrue(HttpMethod::Get->isIdempotent());
        $this->assertTrue(HttpMethod::Put->isIdempotent());
        $this->assertTrue(HttpMethod::Delete->isIdempotent());

        $this->assertFalse(HttpMethod::Post->isIdempotent());
        $this->assertFalse(HttpMethod::Patch->isIdempotent());
        $this->assertFalse(HttpMethod::Connect->isIdempotent());
    }

    public function testValueMatchesString(): void
    {
        $this->assertSame('GET', HttpMethod::Get->value);
        $this->assertSame('POST', HttpMethod::Post->value);
    }

    public function testRequestAcceptsEnumMethod(): void
    {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getHost')->willReturn('');

        $request = new Request(HttpMethod::Post, $uri);

        $this->assertSame('POST', $request->getMethod());
    }
}
