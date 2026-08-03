<?php

declare(strict_types=1);

namespace ModusPromethean\FireSdk\Exceptions;

use Exception;

/**
 * Base class for every error this SDK raises. Carries the parsed response
 * body ($response) alongside the HTTP status and Fire's own error_code/
 * description when present, so a caller can branch on structured data
 * instead of parsing a message string.
 */
class FireError extends Exception
{
    public readonly ?int $statusCode;
    public readonly ?string $errorCode;
    /** @var array<string, mixed> */
    public readonly array $response;

    /**
     * @param array<string, mixed> $response
     */
    public function __construct(
        string $message,
        ?int $statusCode = null,
        ?string $errorCode = null,
        array $response = [],
    ) {
        parent::__construct($message);
        $this->statusCode = $statusCode;
        $this->errorCode = $errorCode;
        $this->response = $response;
    }
}
