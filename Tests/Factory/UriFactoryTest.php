<?php

declare(strict_types=1);

namespace Temant\HttpCore\Tests\Factory;

use Interop\Http\Factory\UriFactoryTestCase;
use Temant\HttpCore\Factory\UriFactory;
use Temant\HttpCore\Uri;

class UriFactoryTest extends UriFactoryTestCase
{
    protected function createUriFactory(): UriFactory
    {
        return new UriFactory();
    }

    /**
     * Test creating URI from globals with basic HTTP request.
     *
     * @return void
     */
    public function testCreateUriFromGlobalsBasicHttp(): void
    {
        $server = [
            'HTTPS' => 'off',
            'SERVER_NAME' => 'example.com',
            'SERVER_PORT' => '80',
            'REQUEST_URI' => '/path',
            'QUERY_STRING' => 'foo=bar'
        ];

        $uri = UriFactory::createUriFromGlobals($server);

        $this->assertInstanceOf(Uri::class, $uri);
        $this->assertEquals('http', $uri->getScheme());
        $this->assertEquals('example.com', $uri->getHost());
        $this->assertEquals('/path', $uri->getPath());
        $this->assertEquals('foo=bar', $uri->getQuery());
        $this->assertNull($uri->getPort());
        $this->assertEquals('http://example.com/path?foo=bar', (string) $uri);
    }

    /**
     * Test creating URI from globals with HTTPS request.
     *
     * @return void
     */
    public function testCreateUriFromGlobalsHttps(): void
    {
        $server = [
            'HTTPS' => 'on',
            'HTTP_HOST' => 'example.com',
            'REQUEST_URI' => '/secure',
            'QUERY_STRING' => ''
        ];

        $uri = UriFactory::createUriFromGlobals($server);

        $this->assertEquals('https', $uri->getScheme());
        $this->assertEquals('example.com', $uri->getHost());
        $this->assertEquals('/secure', $uri->getPath());
        $this->assertNull($uri->getPort());
        $this->assertEquals('https://example.com/secure', (string) $uri);
    }

    /**
     * Test creating URI from globals with non-standard port.
     *
     * @return void
     */
    public function testCreateUriFromGlobalsWithCustomPort(): void
    {
        $server = [
            'HTTPS' => 'off',
            'HTTP_HOST' => 'example.com',
            'SERVER_PORT' => '8080',
            'REQUEST_URI' => '/app',
            'QUERY_STRING' => 'test=1'
        ];

        $uri = UriFactory::createUriFromGlobals($server);

        $this->assertEquals('http', $uri->getScheme());
        $this->assertEquals('example.com', $uri->getHost());
        $this->assertEquals(8080, $uri->getPort());
        $this->assertEquals('/app', $uri->getPath());
        $this->assertEquals('test=1', $uri->getQuery());
        $this->assertEquals('http://example.com:8080/app?test=1', (string) $uri);
    }

    /**
     * Test creating URI from globals with authentication.
     *
     * @return void
     */
    public function testCreateUriFromGlobalsWithAuthentication(): void
    {
        $server = [
            'HTTPS' => 'off',
            'HTTP_HOST' => 'example.com',
            'SERVER_PORT' => '80',
            'REQUEST_URI' => '/private',
            'PHP_AUTH_USER' => 'user',
            'PHP_AUTH_PW' => 'pass123'
        ];

        $uri = UriFactory::createUriFromGlobals($server);

        $this->assertEquals('user:pass123', $uri->getUserInfo());
        $this->assertEquals('http://user:pass123@example.com/private', (string) $uri);
    }

    /**
     * Test creating URI from globals with minimal server array.
     *
     * @return void
     */
    public function testCreateUriFromGlobalsWithMinimalServer(): void
    {
        $server = [
            'REQUEST_URI' => '/'
        ];

        $uri = UriFactory::createUriFromGlobals($server);

        $this->assertEquals('http', $uri->getScheme());
        $this->assertEquals('localhost', $uri->getHost());
        $this->assertEquals('/', $uri->getPath());
        $this->assertNull($uri->getPort());
        $this->assertEquals('http://localhost/', (string) $uri);
    }

    /**
     * Test creating URI from globals with query string in REQUEST_URI.
     *
     * @return void
     */
    public function testCreateUriFromGlobalsWithQueryInRequestUri(): void
    {
        $server = [
            'HTTPS' => 'off',
            'HTTP_HOST' => 'example.com',
            'REQUEST_URI' => '/path?existing=query',
            'QUERY_STRING' => 'additional=param'
        ];

        $uri = UriFactory::createUriFromGlobals($server);

        $this->assertEquals('/path', $uri->getPath());
        $this->assertEquals('additional=param', $uri->getQuery());
        $this->assertEquals('http://example.com/path?additional=param', (string) $uri);
    }

    /**
     * Test creating URI from globals with IPv6 host and port in HTTP_HOST.
     *
     * @return void
     */
    public function testCreateUriFromGlobalsWithIPv6AndPortInHost(): void
    {
        $server = [
            'HTTPS' => 'off',
            'HTTP_HOST' => '[::1]:8080',
            'REQUEST_URI' => '/api',
            'QUERY_STRING' => 'param=value'
        ];

        $uri = UriFactory::createUriFromGlobals($server);

        $this->assertEquals('[::1]', $uri->getHost()); // getHost() returns with brackets
        $this->assertEquals(8080, $uri->getPort());
        $this->assertEquals('/api', $uri->getPath());
        $this->assertEquals('param=value', $uri->getQuery());
        $this->assertEquals('http://[::1]:8080/api?param=value', (string) $uri);
    }

    /**
     * Test creating URI from globals with IPv6 host without port in HTTP_HOST.
     *
     * @return void
     */
    public function testCreateUriFromGlobalsWithIPv6WithoutPort(): void
    {
        $server = [
            'HTTPS' => 'off',
            'HTTP_HOST' => '[2001:db8::1]',
            'SERVER_PORT' => '8080',
            'REQUEST_URI' => '/api'
        ];

        $uri = UriFactory::createUriFromGlobals($server);

        $this->assertEquals('[2001:db8::1]', $uri->getHost()); // getHost() returns with brackets
        $this->assertEquals(8080, $uri->getPort());
        $this->assertEquals('/api', $uri->getPath());
        $this->assertEquals('http://[2001:db8::1]:8080/api', (string) $uri);
    }

    /**
     * Test creating URI from globals with port in HTTP_HOST but also SERVER_PORT (HTTP_HOST port should win).
     *
     * @return void
     */
    public function testCreateUriFromGlobalsWithPortInHostAndServerPort(): void
    {
        $server = [
            'HTTPS' => 'off',
            'HTTP_HOST' => 'example.com:8080',
            'SERVER_PORT' => '9999', // This should be ignored since port is in HTTP_HOST
            'REQUEST_URI' => '/test'
        ];

        $uri = UriFactory::createUriFromGlobals($server);

        $this->assertEquals('example.com', $uri->getHost());
        $this->assertEquals(8080, $uri->getPort()); // Should use port from HTTP_HOST, not SERVER_PORT
        $this->assertEquals('/test', $uri->getPath());
        $this->assertEquals('http://example.com:8080/test', (string) $uri);
    }
}