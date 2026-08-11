<?php

declare(strict_types=1);

namespace Acceptora\Verification\Exception;

use RuntimeException;
use Throwable;

class VerificationException extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly ?int $statusCode = null,
        public readonly bool $retryable = false,
        public readonly ?int $retryAfter = null,
        public readonly ?string $correlationId = null,
        public readonly array $details = [],
        public readonly ?string $recoveryInstruction = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        return [
            'message' => $this->getMessage(),
            'errorCode' => $this->errorCode,
            'statusCode' => $this->statusCode,
            'retryable' => $this->retryable,
            'retryAfter' => $this->retryAfter,
            'correlationId' => $this->correlationId,
            'details' => $this->details,
            'recoveryInstruction' => $this->recoveryInstruction,
        ];
    }
}
