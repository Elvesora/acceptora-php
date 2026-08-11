<?php

declare(strict_types=1);

namespace Acceptora\Verification;

use Acceptora\Verification\Exception\InvalidResponseException;
use JsonSerializable;

final readonly class ReadinessResult implements JsonSerializable
{
    /** @param array<string, mixed> $components */
    public function __construct(
        #[\SensitiveParameter] public string $status,
        public bool $ready,
        #[\SensitiveParameter] public string $service,
        #[\SensitiveParameter] public string $checkedAt,
        #[\SensitiveParameter] public array $components,
        #[\SensitiveParameter] public string $contractVersion,
    ) {}

    /** @param array<string, mixed> $envelope */
    public static function fromEnvelope(#[\SensitiveParameter] array $envelope): self
    {
        $data = $envelope['data'] ?? null;
        $meta = $envelope['meta'] ?? null;

        if (! is_array($data) || ! is_array($meta) || ! is_string($data['status'] ?? null)
            || ! is_bool($data['ready'] ?? null) || ! is_string($data['service'] ?? null)
            || ! is_string($data['checked_at'] ?? null) || ! is_array($data['components'] ?? null)
            || ! is_string($meta['contract_version'] ?? null)) {
            throw new InvalidResponseException('The readiness response does not match the public contract.', 'INVALID_RESPONSE');
        }

        return new self($data['status'], $data['ready'], $data['service'], $data['checked_at'], $data['components'], $meta['contract_version']);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['status' => $this->status, 'ready' => $this->ready, 'service' => $this->service, 'checked_at' => $this->checkedAt, 'components' => $this->components, 'contract_version' => $this->contractVersion];
    }
}
