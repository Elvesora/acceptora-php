<?php

declare(strict_types=1);

namespace Acceptora\Verification;

use JsonSerializable;

final readonly class ToolCallResult implements JsonSerializable
{
    /**
     * @param  array<string, mixed>  $structuredContent
     * @param  list<array<string, mixed>>  $content
     */
    public function __construct(
        #[\SensitiveParameter]
        public string $tool,
        #[\SensitiveParameter]
        public array $structuredContent,
        #[\SensitiveParameter]
        public array $content = [],
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['tool' => $this->tool, 'structured_content' => $this->structuredContent, 'content' => $this->content];
    }
}
