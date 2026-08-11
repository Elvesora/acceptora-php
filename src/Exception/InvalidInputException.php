<?php

declare(strict_types=1);

namespace Acceptora\Verification\Exception;

use InvalidArgumentException;

final class InvalidInputException extends InvalidArgumentException
{
    /** @return array<string, int|string> */
    public function __serialize(): array
    {
        return [
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
        ];
    }
}
