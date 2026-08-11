<?php

declare(strict_types=1);

namespace Acceptora\Verification;

use Acceptora\Verification\Exception\InvalidResponseException;
use JsonSerializable;

final readonly class ProjectMetadataResult implements JsonSerializable
{
    /**
     * @param  array<string, mixed>  $verificationPolicy
     * @param  list<string>  $grantedScopes
     * @param  array<string, mixed>  $versions
     * @param  array<string, mixed>  $endpoints
     * @param  list<string>  $supportedClients
     */
    public function __construct(
        #[\SensitiveParameter] public string $projectId,
        #[\SensitiveParameter] public string $name,
        #[\SensitiveParameter] public ?string $sourceKind,
        #[\SensitiveParameter] public ?string $platform,
        #[\SensitiveParameter] public ?string $defaultEnvironment,
        #[\SensitiveParameter] public array $verificationPolicy,
        #[\SensitiveParameter] public ?string $connectionStatus,
        #[\SensitiveParameter] public array $grantedScopes,
        #[\SensitiveParameter] public ?string $credentialExpiresAt,
        #[\SensitiveParameter] public array $versions,
        #[\SensitiveParameter] public array $endpoints,
        #[\SensitiveParameter] public array $supportedClients,
        #[\SensitiveParameter] public string $correlationId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(
        #[\SensitiveParameter] array $data,
        #[\SensitiveParameter] string $fallbackCorrelationId,
    ): self {
        foreach (['project_id', 'name'] as $field) {
            if (! is_string($data[$field] ?? null) || trim($data[$field]) === '') {
                throw new InvalidResponseException("The project metadata response field [{$field}] must be a non-empty string.", 'INVALID_RESPONSE', correlationId: $fallbackCorrelationId);
            }
        }

        foreach (['source_kind', 'platform', 'default_environment', 'connection_status'] as $field) {
            if (! array_key_exists($field, $data)
                || ($data[$field] !== null && (! is_string($data[$field]) || trim($data[$field]) === ''))) {
                throw new InvalidResponseException("The project metadata response field [{$field}] must be a non-empty string or null.", 'INVALID_RESPONSE', correlationId: $fallbackCorrelationId);
            }
        }

        if (preg_match('/^proj_[0-9A-HJKMNP-TV-Z]{26}$/D', $data['project_id']) !== 1) {
            throw new InvalidResponseException('The project metadata response contains an invalid project ID.', 'INVALID_RESPONSE', correlationId: $fallbackCorrelationId);
        }

        foreach (['verification_policy', 'versions', 'endpoints'] as $field) {
            if (! is_array($data[$field] ?? null) || ($data[$field] !== [] && array_is_list($data[$field]))) {
                throw new InvalidResponseException("The project metadata response field [{$field}] must be an object.", 'INVALID_RESPONSE', correlationId: $fallbackCorrelationId);
            }
        }

        foreach (['granted_scopes', 'supported_clients'] as $field) {
            if (! is_array($data[$field] ?? null) || ! array_is_list($data[$field])) {
                throw new InvalidResponseException("The project metadata response field [{$field}] must be a list of strings.", 'INVALID_RESPONSE', correlationId: $fallbackCorrelationId);
            }

            foreach ($data[$field] as $value) {
                if (! is_string($value) || trim($value) === '') {
                    throw new InvalidResponseException("The project metadata response field [{$field}] must be a list of strings.", 'INVALID_RESPONSE', correlationId: $fallbackCorrelationId);
                }
            }
        }

        if (! array_key_exists('credential_expires_at', $data)
            || ($data['credential_expires_at'] !== null && ! is_string($data['credential_expires_at']))) {
            throw new InvalidResponseException('The project metadata credential expiry must be a string or null.', 'INVALID_RESPONSE', correlationId: $fallbackCorrelationId);
        }

        $correlationId = is_string($data['correlation_id'] ?? null) && preg_match('/^[A-Za-z0-9._:-]{1,200}$/D', $data['correlation_id']) === 1
            ? $data['correlation_id']
            : $fallbackCorrelationId;

        return new self(
            projectId: $data['project_id'],
            name: $data['name'],
            sourceKind: $data['source_kind'],
            platform: $data['platform'],
            defaultEnvironment: $data['default_environment'],
            verificationPolicy: $data['verification_policy'],
            connectionStatus: $data['connection_status'],
            grantedScopes: $data['granted_scopes'],
            credentialExpiresAt: $data['credential_expires_at'] ?? null,
            versions: $data['versions'],
            endpoints: $data['endpoints'],
            supportedClients: $data['supported_clients'],
            correlationId: $correlationId,
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'project_id' => $this->projectId,
            'name' => $this->name,
            'source_kind' => $this->sourceKind,
            'platform' => $this->platform,
            'default_environment' => $this->defaultEnvironment,
            'verification_policy' => $this->verificationPolicy,
            'connection_status' => $this->connectionStatus,
            'granted_scopes' => $this->grantedScopes,
            'credential_expires_at' => $this->credentialExpiresAt,
            'versions' => $this->versions,
            'endpoints' => $this->endpoints,
            'supported_clients' => $this->supportedClients,
            'correlation_id' => $this->correlationId,
        ];
    }
}
