<?php namespace StarlineApi\Exceptions;

/**
 * Thrown when the StarLine API returns an error envelope or HTTP >= 400.
 *
 * @author Alexander Tischenko <http://alex-tisch.ru>
 */
class StarlineApiException extends StarlineException
{
    /**
     * @param array<string, mixed> $raw Decoded response body.
     */
    public function __construct(
        string $message,
        private readonly int $apiCode = 0,
        private readonly array $raw = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $apiCode, $previous);
    }

    public function getApiCode(): int
    {
        return $this->apiCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function getRaw(): array
    {
        return $this->raw;
    }
}