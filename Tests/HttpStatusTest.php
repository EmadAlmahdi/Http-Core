<?php

declare(strict_types=1);

namespace Temant\HttpCore\Tests;

use PHPUnit\Framework\TestCase;
use Temant\HttpCore\Enum\HttpStatus;
use Temant\HttpCore\Message\Response;

final class HttpStatusTest extends TestCase
{
    public function testReasonPhrases(): void
    {
        $this->assertSame('OK', HttpStatus::OK->reasonPhrase());
        $this->assertSame('Not Found', HttpStatus::NotFound->reasonPhrase());
        $this->assertSame('I\'m a teapot', HttpStatus::ImATeapot->reasonPhrase());
        $this->assertSame('Internal Server Error', HttpStatus::InternalServerError->reasonPhrase());
    }

    public function testClassification(): void
    {
        $this->assertTrue(HttpStatus::Continue->isInformational());
        $this->assertTrue(HttpStatus::OK->isSuccessful());
        $this->assertTrue(HttpStatus::Found->isRedirection());
        $this->assertTrue(HttpStatus::NotFound->isClientError());
        $this->assertTrue(HttpStatus::InternalServerError->isServerError());

        $this->assertFalse(HttpStatus::OK->isClientError());
        $this->assertFalse(HttpStatus::NotFound->isSuccessful());
    }

    public function testTryFromUnknownCodeReturnsNull(): void
    {
        /** @phpstan-ignore method.alreadyNarrowedType (phpstan over-narrows tryFrom() with a literal argument not in the enum) */
        $this->assertNull(HttpStatus::tryFrom(499));
    }

    public function testResponseAcceptsEnumStatus(): void
    {
        $response = new Response(HttpStatus::NotFound);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Not Found', $response->getReasonPhrase());
    }

    public function testResponseWithNonStandardCodeHasEmptyReasonPhrase(): void
    {
        $response = new Response(499);

        $this->assertSame(499, $response->getStatusCode());
        $this->assertSame('', $response->getReasonPhrase());
    }
}
