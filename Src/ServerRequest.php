<?php

declare(strict_types=1);

namespace Temant\HttpCore;

use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;

use function array_key_exists;
use function is_array;
use function is_object;

/**
 * PSR-7 HTTP Server Request implementation.
 *
 * Everything {@see Request} offers, plus the extra state that only exists
 * once a request has actually arrived at a server: the raw `$_SERVER`-style
 * parameters, cookies, query parameters, uploaded files, a parsed body, and
 * an arbitrary attribute bag middleware can use to pass data down the
 * pipeline (routing results, an authenticated user, and so on). Build one
 * from PHP's superglobals with {@see ServerRequestFactory::fromGlobals()}.
 *
 * @see ServerRequestInterface The PSR-7 contract this class implements.
 * @phpstan-type UploadedFilesTree array<string|int, UploadedFileInterface|array<string|int, UploadedFileInterface|mixed>>
 */
final readonly class ServerRequest extends Request implements ServerRequestInterface
{
    /** @var array<string, mixed> */
    private array $attributes;

    /**
     * @param string|HttpMethod $method
     * @param array<mixed> $serverParams
     * @param array<string, string|string[]> $headers
     * @param array<mixed> $cookieParams
     * @param array<mixed> $queryParams
     * @param UploadedFilesTree $uploadedFiles
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
        self::assertValidUploadedFilesTree($uploadedFiles);

        parent::__construct($method, $uri, $headers, $body, $protocolVersion);
        $this->attributes = [];
    }

    /**
     * @inheritDoc
     * @see ServerRequestInterface::getServerParams()
     * @return array<mixed>
     */
    public function getServerParams(): array
    {
        return $this->serverParams;
    }

    /**
     * @inheritDoc
     * @see ServerRequestInterface::getCookieParams()
     * @return array<mixed>
     */
    public function getCookieParams(): array
    {
        return $this->cookieParams;
    }

    /**
     * @inheritDoc
     * @see ServerRequestInterface::withCookieParams()
     * @param array<string, string> $cookies
     */
    public function withCookieParams(array $cookies): static
    {
        return clone($this, ['cookieParams' => $cookies]);
    }

    /**
     * @inheritDoc
     * @see ServerRequestInterface::getQueryParams()
     * @return array<mixed>
     */
    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    /**
     * @inheritDoc
     * @see ServerRequestInterface::withQueryParams()
     * @param array<string, mixed> $query
     */
    public function withQueryParams(array $query): static
    {
        return clone($this, ['queryParams' => $query]);
    }

    /**
     * @inheritDoc
     * @see ServerRequestInterface::getUploadedFiles()
     * @return UploadedFilesTree
     */
    public function getUploadedFiles(): array
    {
        return $this->uploadedFiles;
    }

    /**
     * @inheritDoc
     * @see ServerRequestInterface::withUploadedFiles()
     * @param UploadedFilesTree $uploadedFiles
     * @throws InvalidArgumentException if any leaf of `$uploadedFiles` isn't an {@see UploadedFileInterface}.
     */
    public function withUploadedFiles(array $uploadedFiles): static
    {
        self::assertValidUploadedFilesTree($uploadedFiles);

        return clone($this, ['uploadedFiles' => $uploadedFiles]);
    }

    /**
     * @inheritDoc
     * @see ServerRequestInterface::getParsedBody()
     * @return array<mixed>|object|null
     */
    public function getParsedBody(): array|object|null
    {
        return $this->parsedBody;
    }

    /**
     * @inheritDoc
     * @see ServerRequestInterface::withParsedBody()
     * @param array<string, mixed>|object|null $data
     * @throws InvalidArgumentException if `$data` isn't an array, object, or null.
     */
    public function withParsedBody($data): static
    {
        if ($data === null) {
            return $this;
        }

        /** @phpstan-ignore function.alreadyNarrowedType, booleanAnd.alwaysFalse */
        if (!is_array($data) && !is_object($data)) {
            throw new InvalidArgumentException('Parsed body must be an array, object, or null.');
        }

        return clone($this, ['parsedBody' => $data]);
    }

    /**
     * @inheritDoc
     * @see ServerRequestInterface::getAttributes()
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * @inheritDoc
     * @see ServerRequestInterface::getAttribute()
     */
    public function getAttribute(string $name, mixed $default = null): mixed
    {
        /** @phpstan-ignore nullCoalesce.offset (phpstan doesn't yet track PHP 8.5 clone-with reinitialization of readonly properties) */
        return $this->attributes[$name] ?? $default;
    }

    /**
     * @inheritDoc
     * @see ServerRequestInterface::withAttribute()
     */
    public function withAttribute(string $name, mixed $value): static
    {
        return clone($this, ['attributes' => [...$this->attributes, $name => $value]]);
    }

    /**
     * @inheritDoc
     * @see ServerRequestInterface::withoutAttribute()
     */
    public function withoutAttribute(string $name): static
    {
        if (!array_key_exists($name, $this->attributes)) {
            return $this;
        }

        $attributes = $this->attributes;
        unset($attributes[$name]);

        return clone($this, ['attributes' => $attributes]);
    }

    /**
     * PSR-7 describes {@see getUploadedFiles()} as returning "an array
     * tree" whose leaves are {@see UploadedFileInterface} instances -
     * nested arrays are allowed (mirroring how a multi-file `<input
     * name="photos[]">` shows up in `$_FILES`), so this walks the whole
     * tree rather than just checking the top level.
     *
     * @param array<mixed> $tree
     * @throws InvalidArgumentException if any leaf isn't an {@see UploadedFileInterface}.
     */
    private static function assertValidUploadedFilesTree(array $tree): void
    {
        foreach ($tree as $leaf) {
            if ($leaf instanceof UploadedFileInterface) {
                continue;
            }

            if (is_array($leaf)) {
                self::assertValidUploadedFilesTree($leaf);
                continue;
            }

            throw new InvalidArgumentException(
                'Invalid uploaded files structure: every leaf must be an UploadedFileInterface instance.'
            );
        }
    }
}
