<?php

declare(strict_types=1);

namespace Acceptora\Verification;

use JsonSerializable;

final readonly class CompatibilityResult implements JsonSerializable
{
    /** @param list<string> $tools */
    public function __construct(
        #[\SensitiveParameter] public string $contractVersion,
        #[\SensitiveParameter] public string $protocolVersion,
        #[\SensitiveParameter] public string $serverName,
        #[\SensitiveParameter] public string $serverVersion,
        #[\SensitiveParameter] public array $tools,
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['contract_version' => $this->contractVersion, 'protocol_version' => $this->protocolVersion, 'server_name' => $this->serverName, 'server_version' => $this->serverVersion, 'tools' => $this->tools];
    }
}
