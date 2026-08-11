<?php

declare(strict_types=1);

namespace Acceptora\Verification\Contracts;

use Acceptora\Verification\CompatibilityResult;
use Acceptora\Verification\CompletionGateResult;
use Acceptora\Verification\ContractInfo;
use Acceptora\Verification\ProjectMetadataResult;
use Acceptora\Verification\ReadinessResult;
use Acceptora\Verification\RestOperationResult;
use Acceptora\Verification\ToolCallResult;

interface VerificationClientInterface
{
    public function readiness(#[\SensitiveParameter] ?string $correlationId = null): ReadinessResult;

    public function contractInfo(#[\SensitiveParameter] ?string $correlationId = null): ContractInfo;

    /** @return array<string, mixed> */
    public function openApiDocument(#[\SensitiveParameter] ?string $correlationId = null): array;

    public function projectMetadata(#[\SensitiveParameter] ?string $correlationId = null): ProjectMetadataResult;

    /** @param array<array-key, mixed> $payload */
    public function completionGate(#[\SensitiveParameter] array $payload, #[\SensitiveParameter] ?string $correlationId = null): CompletionGateResult;

    public function verifyCompatibility(#[\SensitiveParameter] ?string $correlationId = null): CompatibilityResult;

    /** @param array<array-key, mixed> $arguments */
    public function callTool(#[\SensitiveParameter] string $name, #[\SensitiveParameter] array $arguments, #[\SensitiveParameter] ?string $correlationId = null): ToolCallResult;

    /** @param array<string, mixed> $payload */
    public function callRestOperation(#[\SensitiveParameter] string $operation, #[\SensitiveParameter] array $payload, #[\SensitiveParameter] ?string $correlationId = null): RestOperationResult;

    /** @param array<string, mixed> $payload */
    public function resolveFeatureRest(#[\SensitiveParameter] array $payload, #[\SensitiveParameter] ?string $correlationId = null): RestOperationResult;

    /** @param array<string, mixed> $payload */
    public function getFeatureContextRest(#[\SensitiveParameter] array $payload, #[\SensitiveParameter] ?string $correlationId = null): RestOperationResult;

    /** @param array<string, mixed> $payload */
    public function reconcileChecklistRest(#[\SensitiveParameter] array $payload, #[\SensitiveParameter] ?string $correlationId = null): RestOperationResult;

    /** @param array<string, mixed> $payload */
    public function getVerificationFeedbackRest(#[\SensitiveParameter] array $payload, #[\SensitiveParameter] ?string $correlationId = null): RestOperationResult;

    /** @param array<string, mixed> $payload */
    public function addressFeedbackRest(#[\SensitiveParameter] array $payload, #[\SensitiveParameter] ?string $correlationId = null): RestOperationResult;

    /** @param array<string, mixed> $payload */
    public function getVerificationStatusRest(#[\SensitiveParameter] array $payload, #[\SensitiveParameter] ?string $correlationId = null): RestOperationResult;

    /** @param array<string, mixed> $payload */
    public function checkCompletionGateRest(#[\SensitiveParameter] array $payload, #[\SensitiveParameter] ?string $correlationId = null): RestOperationResult;

    /** @param array<string, mixed> $payload */
    public function recordVerificationExceptionRest(#[\SensitiveParameter] array $payload, #[\SensitiveParameter] ?string $correlationId = null): RestOperationResult;

    /** @param array<string, mixed> $arguments */
    public function resolveFeature(#[\SensitiveParameter] array $arguments, #[\SensitiveParameter] ?string $correlationId = null): ToolCallResult;

    /** @param array<string, mixed> $arguments */
    public function getFeatureContext(#[\SensitiveParameter] array $arguments, #[\SensitiveParameter] ?string $correlationId = null): ToolCallResult;

    /** @param array<string, mixed> $arguments */
    public function reconcileChecklist(#[\SensitiveParameter] array $arguments, #[\SensitiveParameter] ?string $correlationId = null): ToolCallResult;

    /** @param array<string, mixed> $arguments */
    public function getVerificationFeedback(#[\SensitiveParameter] array $arguments, #[\SensitiveParameter] ?string $correlationId = null): ToolCallResult;

    /** @param array<string, mixed> $arguments */
    public function addressFeedback(#[\SensitiveParameter] array $arguments, #[\SensitiveParameter] ?string $correlationId = null): ToolCallResult;

    /** @param array<string, mixed> $arguments */
    public function getVerificationStatus(#[\SensitiveParameter] array $arguments, #[\SensitiveParameter] ?string $correlationId = null): ToolCallResult;

    /** @param array<string, mixed> $arguments */
    public function checkCompletionGate(#[\SensitiveParameter] array $arguments, #[\SensitiveParameter] ?string $correlationId = null): ToolCallResult;

    /** @param array<string, mixed> $arguments */
    public function recordVerificationException(#[\SensitiveParameter] array $arguments, #[\SensitiveParameter] ?string $correlationId = null): ToolCallResult;
}
