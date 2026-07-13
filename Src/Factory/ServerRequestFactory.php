<?php

declare(strict_types=1);

namespace Temant\HttpCore\Factory;

use InvalidArgumentException;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;
use Temant\HttpCore\ServerRequest;

/**
 * PSR-17 factory for {@see ServerRequest} instances.
 *
 * Use {@see createServerRequest()} when you already have the pieces
 * (method, URI, server params) in hand, or the static
 * {@see fromGlobals()} to build one straight from PHP's superglobals -
 * that's the one you want at the front controller of a real application.
 */
class ServerRequestFactory implements ServerRequestFactoryInterface
{
    public function __construct(
        private UriFactoryInterface $uriFactory = new UriFactory(),
        private UploadedFileFactoryInterface $uploadedFileFactory = new UploadedFileFactory(),
        private StreamFactoryInterface $streamFactory = new StreamFactory()
    ) {
    }

    /**
     * {@inheritdoc}
     * @param array<string, mixed> $serverParams
     */
    #[\Override]
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

        return new ServerRequest(
            $method,
            $uri,
            $serverParams
        );
    }

    /**
     * Create a new server request from the global superglobals.
     *
     * @return ServerRequestInterface
     */
    public static function fromGlobals(): ServerRequestInterface
    {
        $self = new self();

        /** @var UriFactory $uriFactory */
        $uriFactory = $self->uriFactory;
        $uri = $uriFactory->createUriFromGlobals($_SERVER);

        $method = isset($_SERVER['REQUEST_METHOD']) && is_scalar($_SERVER['REQUEST_METHOD'])
            ? strval($_SERVER['REQUEST_METHOD'])
            : 'GET';

        $headers = [];
        $special = ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'];

        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_') || in_array($key, $special, true)) {
                $name = str_replace('_', '-', strtolower(str_starts_with($key, 'HTTP_') ? substr($key, 5) : $key));

                $headers[$name] = is_array($value)
                    ? array_map(fn($v) => is_scalar($v) ? (string) $v : '', $value)
                    : [is_scalar($value) ? (string) $value : ''];
            }
        }

        $protocol = isset($_SERVER['SERVER_PROTOCOL']) && is_scalar($_SERVER['SERVER_PROTOCOL'])
            ? str_replace('HTTP/', '', strval($_SERVER['SERVER_PROTOCOL']))
            : '1.1';

        $serverRequest = new ServerRequest(
            $method,
            $uri,
            $_SERVER,
            $headers,
            $self->streamFactory->createStream(),
            $protocol,
            $_COOKIE,
            $_GET,
            $self->normalizeFiles($_FILES),
            $_POST,
        );

        return $serverRequest;
    }

    /**
     * Normalize uploaded files array to PSR-7 format.
     *
     * @param array<mixed, mixed> $files
     * @return array<UploadedFileInterface>
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
                throw new InvalidArgumentException('Invalid value in files specification');
            }
        }

        return $normalized; /** @phpstan-ignore return.type */
    }

    /**
     * Create an UploadedFile instance from a $_FILES specification.
     *
     * @param array{
     *     tmp_name: string|null,
     *     name?: string|null,
     *     type?: string|null,
     *     size?: int|null,
     *     error: int|null
     * } $file The $_FILES array entry
     * @return UploadedFileInterface
     * @throws InvalidArgumentException if file specification is invalid
     */
    private function createUploadedFileFromSpec(array $file): UploadedFileInterface
    {
        if (!isset($file['tmp_name'], $file['error'])) {
            throw new InvalidArgumentException('Invalid file specification: tmp_name and error are required');
        }

        $tmpName = $file['tmp_name'];
        $error = $file['error'];
        $size = $file['size'] ?? null;
        $clientFilename = $file['name'] ?? null;
        $clientMediaType = $file['type'] ?? null;

        // Validate the uploaded file
        if ($error === UPLOAD_ERR_OK) {
            if (!is_file($tmpName)) {
                throw new InvalidArgumentException('Invalid tmp_name in file specification');
            }

            if (PHP_SAPI !== 'cli' && !is_uploaded_file($tmpName)) {
                throw new InvalidArgumentException('File was not uploaded via HTTP POST');
            }

            if ($size === null) {
                if (filesize($tmpName) !== false) {
                    $size = filesize($tmpName);
                }
            }
        }

        // Create a stream from the temporary file
        try {
            $stream = $this->streamFactory->createStreamFromFile($tmpName, 'r');
        } catch (\RuntimeException $e) {
            throw new InvalidArgumentException('Cannot create stream from uploaded file', 0, $e);
        }

        // Use the UploadedFileFactory to create the UploadedFile instance
        return $this->uploadedFileFactory->createUploadedFile(
            $stream,
            $size,
            $error,
            $clientFilename,
            $clientMediaType
        );
    }
}