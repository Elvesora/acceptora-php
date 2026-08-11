<?php

declare(strict_types=1);

namespace Acceptora\Verification\Support;

use Acceptora\Verification\Exception\InvalidResponseException;

final readonly class ContractManifest
{
    /** @param array<string, mixed> $data */
    public function __construct(#[\SensitiveParameter] private array $data)
    {
        if (($data['schema_version'] ?? null) !== 1
            || ($data['package']['name'] ?? null) !== 'acceptora/verification-php'
            || ! is_string($data['package']['version'] ?? null)
            || ! is_string($data['integration']['version'] ?? null)
            || ! is_string($data['skill']['version'] ?? null)
            || ! is_string($data['contract']['version'] ?? null)
            || ! is_string($data['contract']['mcp_protocol_version'] ?? null)
            || ! is_string($data['server']['name'] ?? null)
            || ! is_string($data['server']['version'] ?? null)
            || ! is_array($data['http_endpoints'] ?? null)
            || ! array_is_list($data['http_endpoints'])
            || ! is_array($data['error_codes'] ?? null)
            || ! array_is_list($data['error_codes'])
            || ! is_array($data['tools'] ?? null)
            || ! array_is_list($data['tools'])) {
            throw new InvalidResponseException('The bundled contract manifest is invalid.', 'PACKAGE_INVALID');
        }

        foreach ($data['tools'] as $tool) {
            if (! is_array($tool)
                || ! is_string($tool['name'] ?? null)
                || ! is_string($tool['required_scope'] ?? null)
                || ! is_bool($tool['read_only'] ?? null)
                || ! is_string($tool['input_schema_sha256'] ?? null)
                || ! is_string($tool['output_schema_sha256'] ?? null)) {
                throw new InvalidResponseException('The bundled contract manifest is invalid.', 'PACKAGE_INVALID');
            }
        }
    }

    public static function bundled(): self
    {
        $path = __DIR__.'/../../resources/contract-manifest.json';

        if (! is_file($path)) {
            throw new InvalidResponseException('The bundled contract manifest is invalid.', 'PACKAGE_INVALID');
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new InvalidResponseException('The bundled contract manifest is invalid.', 'PACKAGE_INVALID');
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidResponseException('The bundled contract manifest is invalid.', 'PACKAGE_INVALID');
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidResponseException('The bundled contract manifest is invalid.', 'PACKAGE_INVALID');
        }

        return new self($decoded);
    }

    public function contractVersion(): string
    {
        return $this->data['contract']['version'];
    }

    public function integrationVersion(): string
    {
        return $this->data['integration']['version'];
    }

    public function skillVersion(): string
    {
        return $this->data['skill']['version'];
    }

    public function protocolVersion(): string
    {
        return $this->data['contract']['mcp_protocol_version'];
    }

    public function serverName(): string
    {
        return $this->data['server']['name'];
    }

    public function serverVersion(): string
    {
        return $this->data['server']['version'];
    }

    /** @return list<array{name: string, required_scope: string, read_only: bool, input_schema_sha256: string, output_schema_sha256: string}> */
    public function tools(): array
    {
        /** @var list<array{name: string, required_scope: string, read_only: bool, input_schema_sha256: string, output_schema_sha256: string}> $tools */
        $tools = $this->data['tools'];

        return $tools;
    }

    /** @return list<string> */
    public function toolNames(): array
    {
        return array_column($this->tools(), 'name');
    }
}
