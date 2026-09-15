<?php

declare(strict_types=1);

namespace Temant\HttpCore\Factory;

use InvalidArgumentException;
use Psr\Http\Message\ServerRequestFactoryInterface;
use RuntimeException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;
use Temant\HttpCore\ServerRequest;
use Temant\HttpCore\UploadedFile;

use function array_map;
use function filesize;
use function in_array;
use function is_array;
use function is_file;
use function is_scalar;
use function is_string;
use function is_uploaded_file;
use function str_replace;
use function str_starts_with;
use function strtolower;
use function substr;

use const PHP_SAPI;
use const UPLOAD_ERR_OK;

/**
 * PSR-17 factory for {@see ServerRequest} instances.
 *
 * Use {@see createServerRequest()} when the pieces (method, URI, server
 * params) are already in hand, or the static {@see fromGlobals()} to build
 * one straight from PHP's superglobals - that's the one to reach for at
 * the front controller of a real application.
 *
 * @see ServerRequestFactoryInterface The PSR-17 contract this class implements.
 */
class ServerRequestFactory implements ServerRequestFactoryInterface
{
    /** `$_SERVER` keys that carry a header value without an `HTTP_` prefix. */
    private const array UNPREFIXED_HEADER_KEYS = ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'];

    public function __construct(
        private readonly UriFactoryInterface $uriFactory = new UriFactory(),
        private readonly UploadedFileFactoryInterface $uploadedFileFactory = new UploadedFileFactory(),
        private readonly StreamFactoryInterface $streamFactory = new StreamFactory()
    ) {
    }

    /**
     * @inheritDoc
     * @see ServerRequestFactoryInterface::createServerRequest()
     * @param UriInterface|string $uri
     * @param array<string, mixed> $serverParams
     * @throws InvalidArgumentException if `$uri` is neither a string nor a `UriInterface`.
     */
    public function createServerRequest(string $method, $uri, array $serverParams = []): ServerRequestInterface
    {
        if (is_string($uri)) {
            $uri = $this->uriFactory->createUri($uri);
        }

        /** @phpstan-ignore instanceof.alwaysTrue */
        if (!$uri instanceof UriInterface) {
            throw new InvalidArgumentException(
                'Parameter 2 of ServerRequestFactory::createServerRequest() must be a string or a compatible UriInterface.'
            );
        }

        return new ServerRequest($method, $uri, $serverParams);
    }

    /**
     * Builds a {@see ServerRequest} from PHP's superglobals: method,
     * headers, and protocol version from `$_SERVER`; the URI via {@see
     * UriFactory::createUriFromGlobals()}; and `$_COOKIE`, `$_GET`,
     * `$_FILES`, and `$_POST` for the corresponding PSR-7 accessors.
     */
    public static function fromGlobals(): ServerRequestInterface
    {
        $self = new self();

        /** @var UriFactory $uriFactory */
        $uriFactory = $self->uriFactory;

        return new ServerRequest(
            self::resolveMethod($_SERVER),
            $uriFactory->createUriFromGlobals($_SERVER),
            $_SERVER,
            self::resolveHeaders($_SERVER),
            $self->streamFactory->createStream(),
            self::resolveProtocolVersion($_SERVER),
            $_COOKIE,
            $_GET,
            $self->normalizeFiles($_FILES),
            $_POST,
        );
    }

    /**
     * @param array<mixed, mixed> $server
     */
    private static function resolveMethod(array $server): string
    {
        return isset($server['REQUEST_METHOD']) && is_scalar($server['REQUEST_METHOD'])
            ? (string) $server['REQUEST_METHOD']
            : 'GET';
    }

    /**
     * @param array<mixed, mixed> $server
     */
    private static function resolveProtocolVersion(array $server): string
    {
        return isset($server['SERVER_PROTOCOL']) && is_scalar($server['SERVER_PROTOCOL'])
            ? str_replace('HTTP/', '', (string) $server['SERVER_PROTOCOL'])
            : '1.1';
    }

    /**
     * Reconstructs request headers from `$_SERVER`'s `HTTP_*` entries (plus
     * the handful of headers PHP exposes without the prefix, like
     * `CONTENT_TYPE`), translating `HTTP_X_CUSTOM` to `x-custom`.
     *
     * @param array<mixed, mixed> $server
     * @return array<string, string[]>
     */
    private static function resolveHeaders(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $isPrefixed = str_starts_with($key, 'HTTP_');
            if (!$isPrefixed && !in_array($key, self::UNPREFIXED_HEADER_KEYS, true)) {
                continue;
            }

            $name = str_replace('_', '-', strtolower($isPrefixed ? substr($key, 5) : $key));

            $headers[$name] = is_array($value)
                ? array_map(static fn(mixed $v): string => is_scalar($v) ? (string) $v : '', $value)
                : [is_scalar($value) ? (string) $value : ''];
        }

        return $headers;
    }

    /**
     * Normalizes a `$_FILES`-shaped array tree to PSR-7's `UploadedFileInterface` tree.
     *
     * @param array<mixed, mixed> $files
     * @return array<mixed, UploadedFileInterface|mixed[]>
     * @throws InvalidArgumentException if a leaf is neither an `UploadedFileInterface` nor a valid `$_FILES` entry.
     */
    private function normalizeFiles(array $files): array
    {
        $normalized = [];

        foreach ($files as $key => $value) {
            if ($value instanceof UploadedFileInterface) {
                $normalized[$key] = $value;
            } elseif (is_array($value) && isset($value['tmp_name'])) {
                $normalized[$key] = $this->createUploadedFileFromSpec($value); /** @phpstan-ignore argument.type */
            } elseif (is_array($value)) {
                $normalized[$key] = $this->normalizeFiles($value);
            } else {
                throw new InvalidArgumentException('Invalid value in files specification.');
            }
        }

        return $normalized;
    }

    /**
     * Builds one {@see UploadedFile} from a single `$_FILES` entry,
     * verifying (outside the CLI SAPI) that `tmp_name` genuinely arrived
     * via an HTTP upload before trusting it.
     *
     * @param array{
     *     tmp_name: string|null,
     *     name?: string|null,
     *     type?: string|null,
     *     size?: int|null,
     *     error: int|null
     * } $file One `$_FILES` entry.
     * @throws InvalidArgumentException if the specification is invalid or the file can't be trusted.
     */
    private function createUploadedFileFromSpec(array $file): UploadedFileInterface
    {
        if (!isset($file['tmp_name'], $file['error'])) {
            throw new InvalidArgumentException('Invalid file specification: tmp_name and error are required.');
        }

        $tmpName = $file['tmp_name'];
        $error = $file['error'];
        $size = $file['size'] ?? null;

        if ($error === UPLOAD_ERR_OK) {
            if (!is_file($tmpName)) {
                throw new InvalidArgumentException('Invalid tmp_name in file specification.');
            }

            if (PHP_SAPI !== 'cli' && !is_uploaded_file($tmpName)) {
                throw new InvalidArgumentException('File was not uploaded via HTTP POST.');
            }

            if ($size === null) {
                $fileSize = filesize($tmpName);
                $size = $fileSize !== false ? $fileSize : null;
            }
        }

        try {
            $stream = $this->streamFactory->createStreamFromFile($tmpName, 'r');
        } catch (RuntimeException $e) {
            throw new InvalidArgumentException('Cannot create stream from uploaded file.', 0, $e);
        }

        return $this->uploadedFileFactory->createUploadedFile(
            $stream,
            $size,
            $error,
            $file['name'] ?? null,
            $file['type'] ?? null,
        );
    }
}
