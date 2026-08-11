<?php

declare(strict_types=1);

namespace Acceptora\Verification;

use JsonSerializable;

final readonly class RestOperationResult implements JsonSerializable
{
    /** @param array<string, mixed> $data */
    public function __construct(
        #[\SensitiveParameter]
        public string $operation,
        #[\SensitiveParameter]
        public array $data,
        #[\SensitiveParameter]
        public string $correlationId,
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'operation' => $this->operation,
            'data' => $this->data,
            'correlation_id' => $this->correlationId,
        ];
    }
}
