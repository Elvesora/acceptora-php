<?php

declare(strict_types=1);

namespace Acceptora\Verification\Tests;

use Countable;
use LogicException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class RequestHistory implements Countable
{
    /** @var array<int, array{request: RequestInterface, response: ResponseInterface|null, error: mixed, options: array<mixed>}> */
    private array $transactions = [];

    /** @return array<int, array{request: RequestInterface, response: ResponseInterface|null, error: mixed, options: array<mixed>}> */
    public function &container(): array
    {
        $this->transactions = [];

        return $this->transactions;
    }

    public function request(int $index): RequestInterface
    {
        $transaction = $this->transactions[$index] ?? null;
        $request = is_array($transaction) ? $transaction['request'] : null;

        if (! $request instanceof RequestInterface) {
            throw new LogicException("No recorded request exists at index {$index}.");
        }

        return $request;
    }

    /** @return array<mixed> */
    public function options(int $index): array
    {
        $transaction = $this->transactions[$index] ?? null;
        $options = is_array($transaction) ? $transaction['options'] : null;

        if (! is_array($options)) {
            throw new LogicException("No recorded request options exist at index {$index}.");
        }

        return $options;
    }

    /** @return list<RequestInterface> */
    public function requests(): array
    {
        $requests = [];

        foreach (array_keys($this->transactions) as $index) {
            $requests[] = $this->request($index);
        }

        return $requests;
    }

    public function count(): int
    {
        return count($this->transactions);
    }
}
