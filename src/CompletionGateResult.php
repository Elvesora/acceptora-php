<?php

declare(strict_types=1);

namespace Acceptora\Verification;

use Acceptora\Verification\Exception\InvalidResponseException;
use JsonSerializable;

final readonly class CompletionGateResult implements JsonSerializable
{
    public function __construct(
        #[\SensitiveParameter] public string $outcome,
        #[\SensitiveParameter] public ?string $featureId,
        #[\SensitiveParameter] public string $reasonCode,
        #[\SensitiveParameter] public string $reason,
        #[\SensitiveParameter] public ?string $lastSynchronizedDigest,
        public ?int $lastSynchronizedChecklistRevision,
        #[\SensitiveParameter] public ?string $recoveryInstruction,
        #[\SensitiveParameter] public string $correlationId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(#[\SensitiveParameter] array $data): self
    {
        if (! is_string($data['outcome'] ?? null) || ! is_string($data['reason_code'] ?? null)
            || ! is_string($data['reason'] ?? null) || ! is_string($data['correlation_id'] ?? null)
            || (isset($data['feature_id']) && ! is_string($data['feature_id']))
            || (isset($data['last_synchronized_digest']) && ! is_string($data['last_synchronized_digest']))
            || (isset($data['last_synchronized_checklist_revision']) && ! is_int($data['last_synchronized_checklist_revision']))
            || (isset($data['recovery_instruction']) && ! is_string($data['recovery_instruction']))) {
            throw new InvalidResponseException('The completion-gate response does not match the public contract.', 'INVALID_RESPONSE');
        }

        return new self(
            $data['outcome'], $data['feature_id'] ?? null, $data['reason_code'], $data['reason'],
            $data['last_synchronized_digest'] ?? null, $data['last_synchronized_checklist_revision'] ?? null,
            $data['recovery_instruction'] ?? null, $data['correlation_id'],
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'outcome' => $this->outcome, 'feature_id' => $this->featureId, 'reason_code' => $this->reasonCode,
            'reason' => $this->reason, 'last_synchronized_digest' => $this->lastSynchronizedDigest,
            'last_synchronized_checklist_revision' => $this->lastSynchronizedChecklistRevision,
            'recovery_instruction' => $this->recoveryInstruction, 'correlation_id' => $this->correlationId,
        ];
    }
}
