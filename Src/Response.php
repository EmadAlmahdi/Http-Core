<?php

declare(strict_types=1);

namespace Temant\HttpCore;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use InvalidArgumentException;

/**
 * PSR-7 HTTP Response implementation.
 *
 * Represents an outgoing HTTP response: status code, headers, body, and
 * protocol version. A reason phrase is only stored when you give one
 * explicitly; otherwise {@see getReasonPhrase()} falls back to the
 * standard phrase for the status code via {@see HttpStatus}, and returns
 * an empty string for a non-standard code with no phrase of its own -
 * exactly as PSR-7 specifies.
 *
 * @link https://www.php-fig.org/psr/psr-7/ PSR-7 Specification
 */
final class Response extends Message implements ResponseInterface
{
    private readonly int $statusCode;
    private readonly string $reasonPhrase;

    /**
     * Create a new HTTP response.
     *
     * @param int|HttpStatus $statusCode HTTP status code (default: 200)
     * @param array<string, string[]> $headers Response headers
     * @param StreamInterface|null $body Response body
     * @param string $protocolVersion HTTP protocol version (default: '1.1')
     * @param string $reasonPhrase Reason phrase (if empty, will use standard phrase)
     *
     * @throws InvalidArgumentException For invalid status code or protocol version
     */
    public function __construct(
        int|HttpStatus $statusCode = 200,
        array $headers = [],
        ?StreamInterface $body = null,
        string $protocolVersion = '1.1',
        string $reasonPhrase = ''
    ) {
        $statusCode = $statusCode instanceof HttpStatus ? $statusCode->value : $statusCode;
        $this->validateStatusCode($statusCode);

        parent::__construct($headers, $body, $protocolVersion);

        $this->statusCode = $statusCode;
        $this->reasonPhrase = $reasonPhrase !== ''
            ? $this->filterHeaderValue($reasonPhrase)[0]
            : '';
    }

    #[\Override]
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    #[\Override]
    public function withStatus(int $code, string $reasonPhrase = ''): static
    {
        $this->validateStatusCode($code);

        if ($code === $this->statusCode && $reasonPhrase === $this->reasonPhrase) {
            return $this;
        }

        return clone($this, [
            'statusCode' => $code,
            'reasonPhrase' => $reasonPhrase !== '' ? $this->filterHeaderValue($reasonPhrase)[0] : '',
        ]);
    }

    #[\Override]
    public function getReasonPhrase(): string
    {
        if ($this->reasonPhrase !== '') {
            return $this->reasonPhrase;
        }

        return HttpStatus::tryFrom($this->statusCode)?->reasonPhrase() ?? '';
    }

    /**
     * Validate that the status code is within the valid range (100-599)
     *
     * @throws InvalidArgumentException For invalid status codes
     */
    private function validateStatusCode(int $statusCode): void
    {
        if ($statusCode < 100 || $statusCode > 599) {
            throw new InvalidArgumentException(
                "Invalid HTTP status code: {$statusCode}. Must be between 100 and 599."
            );
        }
    }
}