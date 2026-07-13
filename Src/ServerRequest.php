<?php

declare(strict_types=1);

namespace Temant\HttpCore;

use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;

/**
 * PSR-7 HTTP Server Request implementation.
 *
 * Everything {@see Request} offers, plus the extra state that only exists
 * once a request has actually arrived at a server: the raw `$_SERVER`-style
 * parameters, cookies, query parameters, uploaded files, a parsed body, and
 * an arbitrary attribute bag middleware can use to pass data down the
 * pipeline (routing results, an authenticated user, and so on). Build one
 * from PHP's superglobals with {@see \Temant\HttpCore\Factory\ServerRequestFactory::fromGlobals()}.
 */
final class ServerRequest extends Request implements ServerRequestInterface
{
    /** @var array<string, mixed> */
    private readonly array $attributes;

    /**
     * @param string|HttpMethod $method
     * @param array<mixed> $serverParams
     * @param array<mixed> $cookieParams
     * @param array<mixed> $queryParams
     * @param array<UploadedFileInterface> $uploadedFiles
     * @param array<mixed>|object|null $parsedBody
     */
    public function __construct(
        string|HttpMethod $method,
        UriInterface $uri,
        private readonly array $serverParams = [],
        array $headers = [],
        ?StreamInterface $body = null,
        string $protocolVersion = '1.1',
        private readonly array $cookieParams = [],
        private readonly array $queryParams = [],
        private readonly array $uploadedFiles = [],
        private readonly array|object|null $parsedBody = null
    ) {
        parent::__construct($method, $uri, $headers, $body, $protocolVersion);
        $this->attributes = [];
    }

    /**
     * @inheritDoc
     *
     * @return array<mixed>
     */
    #[\Override]
    public function getServerParams(): array
    {
        return $this->serverParams;
    }

    /**
     * @inheritDoc
     *
     * @return array<mixed>
     */
    #[\Override]
    public function getCookieParams(): array
    {
        return $this->cookieParams;
    }

    /**
     * @inheritDoc
     *
     * @param array<string, string> $cookies
     */
    #[\Override]
    public function withCookieParams(array $cookies): static
    {
        return clone($this, ['cookieParams' => $cookies]);
    }

    /**
     * @inheritDoc
     *
     * @return array<mixed>
     */
    #[\Override]
    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    /**
     * @inheritDoc
     *
     * @param array<string, mixed> $query
     */
    #[\Override]
    public function withQueryParams(array $query): static
    {
        return clone($this, ['queryParams' => $query]);
    }

    /**
     * @inheritDoc
     *
     * @return array<UploadedFileInterface>
     */
    #[\Override]
    public function getUploadedFiles(): array
    {
        return $this->uploadedFiles;
    }

    /**
     * @inheritDoc
     *
     * @param array<UploadedFileInterface> $uploadedFiles
     */
    #[\Override]
    public function withUploadedFiles(array $uploadedFiles): static
    {
        return clone($this, ['uploadedFiles' => $uploadedFiles]);
    }

    /**
     * @inheritDoc
     *
     * @return array<mixed>|object|null
     */
    #[\Override]
    public function getParsedBody(): array|object|null
    {
        return $this->parsedBody;
    }

    /**
     * @inheritDoc
     *
     * @param array<string, mixed>|object|null $data
     *
     * @throws InvalidArgumentException if $data is not an array, object
     */
    #[\Override]
    public function withParsedBody($data): static
    {
        if ($data === null) {
            return $this;
        }

        /** @phpstan-ignore function.alreadyNarrowedType, booleanAnd.alwaysFalse */
        if (!is_array($data) && !is_object($data)) {
            throw new InvalidArgumentException('Parsed body must be array, object, or null');
        }

        return clone($this, ['parsedBody' => $data]);
    }

    /**
     * @inheritDoc
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    #[\Override]
    public function getAttribute(string $name, mixed $default = null): mixed
    {
        /** @phpstan-ignore nullCoalesce.offset (phpstan doesn't yet track PHP 8.5 clone-with reinitialization of readonly properties) */
        return $this->attributes[$name] ?? $default;
    }

    #[\Override]
    public function withAttribute(string $name, mixed $value): static
    {
        return clone($this, ['attributes' => [...$this->attributes, $name => $value]]);
    }

    #[\Override]
    public function withoutAttribute(string $name): static
    {
        if (!array_key_exists($name, $this->attributes)) {
            return $this;
        }

        $attributes = $this->attributes;
        unset($attributes[$name]);
        return clone($this, ['attributes' => $attributes]);
    }
}