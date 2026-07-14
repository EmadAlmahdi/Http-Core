<?php

declare(strict_types=1);

namespace Temant\HttpCore\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Temant\HttpCore\Uri;

final class UriTest extends TestCase
{
    public function testConstructFromString(): void
    {
        $uri = new Uri('https://user:pass@Example.COM:8080/path/to/resource?query=1#frag');
        $this->assertSame('https', $uri->getScheme());
        // userinfo är nu url-enkodat i konstruktorn
        $this->assertSame(rawurlencode('user') . ':' . rawurlencode('pass'), $uri->getUserInfo());
        $this->assertSame('example.com', $uri->getHost()); // normaliserat till lowercase
        $this->assertSame(8080, $uri->getPort());
        $this->assertSame('/path/to/resource', $uri->getPath()); // path är url-enkodat men inga mellanslag här
        $this->assertSame('query=1', $uri->getQuery());
        $this->assertSame('frag', $uri->getFragment());
        $this->assertSame(rawurlencode('user') . ':' . rawurlencode('pass') . '@example.com:8080', $uri->getAuthority());
    }
    public function testConstructWithWrongPortNumber(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $uri = new Uri('https://user:pass@Example.COM:0/path/to/resource?query=1#frag');
    }

    public function testWithMethodsAreImmutableAndTyped(): void
    {
        $uri = new Uri();
        $u2 = $uri->withScheme('HTTP');
        $this->assertNotSame($uri, $u2);
        $this->assertSame('http', $u2->getScheme());

        $u3 = $u2->withHost('ExAmPlE.Com');
        $this->assertSame('example.com', $u3->getHost());

        $u4 = $u3->withUserInfo('john', 'doe');
        $this->assertSame('john:doe', rawurldecode($u4->getUserInfo()));
    }

    public function testToStringProducesCompleteUri(): void
    {
        $uri = new Uri()
            ->withScheme('https')
            ->withUserInfo('john', 'doe')
            ->withHost('example.com')
            ->withPort(443)
            ->withPath('/home')
            ->withQuery('?a=1')
            ->withFragment('#section');

        $expected = 'https://john:doe@example.com/home?a=1#section';

        $this->assertSame($expected, (string) $uri);
    }

    public function testInvalidPortThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Uri()->withPort(70000);
    }

    public function testInvalidPortInConstructor(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Uri('https://user:pass@Example.COM:99999');
    }

    public function testInvalidSchemeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Uri()->withScheme('1nvalid'); // måste börja med bokstav enligt regex
    }

    public function testQueryAndFragmentTrimLeadingChars(): void
    {
        $uri = new Uri()->withQuery('?a=b')->withFragment('#frag');
        $this->assertSame('a=b', $uri->getQuery());
        $this->assertSame('frag', $uri->getFragment());
    }

    public function testPathIsEncodedButSlashesPreserved(): void
    {
        $uri = new Uri()->withPath('/a path/with spaces');
        $this->assertSame('/a%20path/with%20spaces', $uri->getPath());
    }

    public function testFilterPathEncodesSpecialCharacters(): void
    {
        $uri = new Uri();
        $reflection = new \ReflectionClass($uri);
        $method = $reflection->getMethod('filterPath');

        $result = $method->invoke($uri, '/path with space');
        $this->assertSame('/path%20with%20space', $result);
    }

    public function testConstructorThrowsOnInvalidUri(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Uri('http://');
    }

    public function testUserInfoWithPasswordParsedFromUri(): void
    {
        $uri = new Uri('https://user:pass@example.com');
        $this->assertSame(rawurlencode('user') . ':' . rawurlencode('pass'), $uri->getUserInfo());
        $this->assertSame(rawurlencode('user') . ':' . rawurlencode('pass') . '@example.com', $uri->getAuthority());
    }

    public function testUserInfoWithUserOnlyParsedFromUri(): void
    {
        $uri = new Uri('https://user@example.com');
        $this->assertSame(rawurlencode('user'), $uri->getUserInfo());
        $this->assertSame(rawurlencode('user') . '@example.com', $uri->getAuthority());
    }

    public function testFilterPath(): void
    {
        $uri = new Uri();
        $method = ReflectionMethod::createFromMethodName($uri::class . "::filterPath");

        $exec = $method->invoke($uri, '');

        $this->assertSame("", $exec);
    }

    public function testConstructorThrowsOnInvalidPort(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Uri('http://example.com:70000');
    }
    public function testUserInfoEncoding(): void
    {
        $user = 'user@name';
        $pass = 'p@ss:word';

        $uri = new Uri()
            ->withUserInfo($user, $pass);

        $this->assertSame(rawurlencode($user) . ':' . rawurlencode($pass), $uri->getUserInfo());
    }
    public function testMailtoUri(): void
    {
        $uri = new Uri('mailto:user@example.com');
        $this->assertSame('mailto', $uri->getScheme());
        $this->assertSame('', $uri->getAuthority());
        // "@" is a valid pchar per RFC 3986 (unlike in userinfo, where it's
        // the delimiter) - a path may contain it unescaped.
        $this->assertSame('user@example.com', $uri->getPath());
    }

    public function testFileUri(): void
    {
        $uri = new Uri('file:///tmp/file.txt');
        $this->assertSame('file', $uri->getScheme());
        $this->assertSame('', $uri->getAuthority());
        $this->assertSame('/tmp/file.txt', $uri->getPath());
    }
    public function testWithSchemeReturnsSameInstanceIfUnchanged(): void
    {
        $uri = new Uri('https://example.com');
        $this->assertSame($uri, $uri->withScheme('https'));
    }

    public function testWithHostReturnsSameInstanceIfUnchanged(): void
    {
        $uri = new Uri('https://example.com');
        $this->assertSame($uri, $uri->withHost('example.com'));
    }

    public function testWithPortReturnsSameInstanceIfUnchanged(): void
    {
        $uri = new Uri('https://example.com:443');
        $this->assertSame($uri, $uri->withPort(443));
    }

    public function testWithUserInfoReturnsSameInstanceIfUnchanged(): void
    {
        $uri = new Uri()->withUserInfo('user', 'pass');
        $this->assertSame($uri, $uri->withUserInfo('user', 'pass'));
    }
    public function testPathWithDotSegments(): void
    {
        $uri = new Uri()->withPath('/a/./b/../c');
        // path segments ska url-enkodas, men dots sparas oförändrade
        $this->assertSame('/a/./b/../c', rawurldecode($uri->getPath()));
    }
    public function testEmptyUserInfo(): void
    {
        $uri = new Uri('http://example.com');
        $this->assertSame('', $uri->getUserInfo());
    }

    public function testUriWithoutHost(): void
    {
        $uri = new Uri('/relative/path');
        $this->assertSame('', $uri->getHost());
        $this->assertSame('/relative/path', $uri->getPath());
    }
    public function testConstructorTrimsQueryAndFragment(): void
    {
        $uri = new Uri('http://example.com/path?query=123#frag');
        $this->assertSame('query=123', $uri->getQuery());
        $this->assertSame('frag', $uri->getFragment());
    }

    public function testWithPathReturnsSameInstanceIfUnchanged(): void
    {
        $uri = new Uri()->withPath('/same/path');
        $this->assertSame($uri, $uri->withPath('/same/path'));
    }

    public function testWithQueryReturnsSameInstanceIfUnchanged(): void
    {
        $uri = new Uri()->withQuery('a=1');
        $this->assertSame($uri, $uri->withQuery('a=1'));
    }

    public function testWithFragmentReturnsSameInstanceIfUnchanged(): void
    {
        $uri = new Uri()->withFragment('frag');
        $this->assertSame($uri, $uri->withFragment('frag'));
    }

    public function testFilterPathReturnsEmptyForEmptyString(): void
    {
        $uri = new Uri();
        $reflection = new \ReflectionClass($uri);
        $method = $reflection->getMethod('filterPath');

        $result = $method->invoke($uri, '');
        $this->assertSame('', $result);
    }
}