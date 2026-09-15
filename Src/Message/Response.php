<?php

declare(strict_types=1);

namespace Temant\HttpCore\Message;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Temant\HttpCore\Enum\HttpStatus;

/**
 * PSR-7 HTTP Response implementation.
 *
 * Represents an outgoing HTTP response: status code, headers, body, and
 * protocol version. A reason phrase is only stored when one is given
 * explicitly; otherwise {@see getReasonPhrase()} falls back to the
 * standard phrase for the status code via {@see HttpStatus}, and returns
 * an empty string for a non-standard code with no phrase of its own -
 * exactly as PSR-7 specifies.
 *
 * @see ResponseInterface The PSR-7 contract this class implements.
 * @link https://www.php-fig.org/psr/psr-7/ PSR-7 Specification
 */
final readonly class Response extends Message implements ResponseInterface
{
    private readonly int $statusCode;
    private readonly string $reasonPhrase;

    /**
     * @param int|HttpStatus $statusCode HTTP status code.
     * @param array<string, string|string[]> $headers Response headers.
     * @param StreamInterface|null $body Response body; created lazily if omitted.
     * @param string $protocolVersion HTTP protocol version.
     * @param string $reasonPhrase Reason phrase; the standard one for `$statusCode` is used if left empty.
     * @throws InvalidArgumentException for an invalid status code or protocol version.
     */
    public function __construct(
        int|HttpStatus $statusCode = 200,
        array $headers = [],
        ?StreamInterface $body = null,
        string $protocolVersion = '1.1',
        string $reasonPhrase = ''
    ) {
        $statusCode = $statusCode instanceof HttpStatus ? $statusCode->value : $statusCode;
        self::assertValidStatusCode($statusCode);

        parent::__construct($headers, $body, $protocolVersion);

        $this->statusCode = $statusCode;
        $this->reasonPhrase = $reasonPhrase !== '' ? $this->filterHeaderValue($reasonPhrase)[0] : '';
    }

    /**
     * @inheritDoc
     * @see ResponseInterface::getStatusCode()
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @inheritDoc
     * @see ResponseInterface::withStatus()
     * @throws InvalidArgumentException for an invalid status code.
     */
    public function withStatus(int $code, string $reasonPhrase = ''): static
    {
        self::assertValidStatusCode($code);

        if ($code === $this->statusCode && $reasonPhrase === $this->reasonPhrase) {
            return $this;
        }

        return clone($this, [
            'statusCode' => $code,
            'reasonPhrase' => $reasonPhrase !== '' ? $this->filterHeaderValue($reasonPhrase)[0] : '',
        ]);
    }

    /**
     * @inheritDoc
     * @see ResponseInterface::getReasonPhrase()
     */
    public function getReasonPhrase(): string
    {
        if ($this->reasonPhrase !== '') {
            return $this->reasonPhrase;
        }

        return HttpStatus::tryFrom($this->statusCode)?->reasonPhrase() ?? '';
    }

    /**
     * @throws InvalidArgumentException if `$statusCode` is outside the 100-599 range.
     */
    private static function assertValidStatusCode(int $statusCode): void
    {
        if ($statusCode < 100 || $statusCode > 599) {
            throw new InvalidArgumentException(
                "Invalid HTTP status code: {$statusCode}. Must be between 100 and 599."
            );
        }
    }
}
