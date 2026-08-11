<?php

declare(strict_types=1);

namespace Acceptora\Verification;

use Acceptora\Verification\Exception\InvalidResponseException;
use JsonSerializable;

final readonly class ContractInfo implements JsonSerializable
{
    public function __construct(
        #[\SensitiveParameter] public string $contractVersion,
        #[\SensitiveParameter] public string $integrationVersion,
        #[\SensitiveParameter] public string $skillVersion,
        #[\SensitiveParameter] public string $schemaRegistry,
    ) {}

    /** @param array<string, mixed> $envelope */
    public static function fromEnvelope(#[\SensitiveParameter] array $envelope): self
    {
        $data = $envelope['data'] ?? null;

        if (! is_array($data) || ! is_string($data['contract_version'] ?? null)
            || ! is_string($data['integration_version'] ?? null) || ! is_string($data['skill_version'] ?? null)
            || ! is_string($data['schema_registry'] ?? null)) {
            throw new InvalidResponseException('The contract information response does not match the public contract.', 'INVALID_RESPONSE');
        }

        return new self($data['contract_version'], $data['integration_version'], $data['skill_version'], $data['schema_registry']);
    }

    /** @return array<string, string> */
    public function jsonSerialize(): array
    {
        return ['contract_version' => $this->contractVersion, 'integration_version' => $this->integrationVersion, 'skill_version' => $this->skillVersion, 'schema_registry' => $this->schemaRegistry];
    }
}
