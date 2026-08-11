<?php

declare(strict_types=1);

namespace Acceptora\Verification;

use Acceptora\Verification\Contracts\VerificationClientInterface;
use Acceptora\Verification\Exception\ApiException;
use Acceptora\Verification\Exception\AuthenticationException;
use Acceptora\Verification\Exception\AuthorizationException;
use Acceptora\Verification\Exception\ConfigurationException;
use Acceptora\Verification\Exception\ContractException;
use Acceptora\Verification\Exception\InvalidInputException;
use Acceptora\Verification\Exception\InvalidResponseException;
use Acceptora\Verification\Exception\RateLimitException;
use Acceptora\Verification\Exception\TransportException;
use Acceptora\Verification\Exception\VerificationException;
use Acceptora\Verification\Support\ContractManifest;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class Client implements VerificationClientInterface
{
    private const TOKEN_PATTERN = '/^avt_[0-9A-HJKMNP-TV-Z]{26}_[A-Za-z0-9]{48}$/D';

    private const GITHUB_TOKEN_PATTERN = '/(?:gh[pousr]_[A-Za-z0-9]{36}|github_pat_[A-Za-z0-9_]{20,255})/';

    private const PRIVATE_KEY_MARKER_PATTERN = '/-----BEGIN (?:(?:RSA|EC|DSA|OPENSSH|ENCRYPTED) PRIVATE KEY|PRIVATE KEY|PGP PRIVATE KEY BLOCK)-----/';

    private const MAX_TOOL_DEFINITIONS = 256;

    private const PROJECT_METADATA_PATH = '/api/v1/integrations/project';

    public const OPENAPI_PATH = '/api/v1/integrations/openapi.json';

    /** @var array<string, string> */
    public const REST_OPERATION_PATHS = [
        'resolve_feature' => '/api/v1/integrations/features/resolve',
        'get_feature_context' => '/api/v1/integrations/features/context',
        'reconcile_checklist' => '/api/v1/integrations/checklists/reconcile',
        'get_verification_feedback' => '/api/v1/integrations/feedback/query',
        'address_feedback' => '/api/v1/integrations/feedback/address',
        'get_verification_status' => '/api/v1/integrations/status',
        'check_completion_gate' => '/api/v1/integrations/completion-gate',
        'record_verification_exception' => '/api/v1/integrations/verification-exceptions',
    ];

    private ClientInterface $http;

    private LoggerInterface $logger;

    private ContractManifest $manifest;

    private ?string $sessionId = null;

    private bool $initialized = false;

    private int $rpcId = 0;

    public function __construct(
        #[\SensitiveParameter]
        private readonly ?string $token = null,
        #[\SensitiveParameter]
        private readonly string $baseUrl = 'https://www.acceptora.com',
        private readonly float $timeout = 15.0,
        private readonly float $connectTimeout = 5.0,
        private readonly int $maxRequestBytes = 2_000_000,
        private readonly int $maxResponseBytes = 4_000_000,
        #[\SensitiveParameter]
        private readonly string $userAgent = 'acceptora-verification-php/1.0.0',
        ?ClientInterface $http = null,
        ?LoggerInterface $logger = null,
        ?ContractManifest $manifest = null,
    ) {
        $this->validateConfiguration();
        $this->http = $http ?? new GuzzleClient;
        $this->logger = $logger ?? new NullLogger;
        $this->manifest = $manifest ?? ContractManifest::bundled();
    }

    public function readiness(#[\SensitiveParameter] ?string $correlationId = null): ReadinessResult
    {
        $response = $this->request('GET', '/api/health', null, false, $correlationId, [200, 503]);
        $payload = $this->responsePayload($response);

        if ($payload === null) {
            throw $this->invalidResponse('The readiness response was empty.', $response['correlation_id']);
        }

        try {
            return ReadinessResult::fromEnvelope($payload);
        } catch (InvalidResponseException $exception) {
            throw $this->invalidResponse($exception->getMessage(), $response['correlation_id']);
        }
    }

    public function contractInfo(#[\SensitiveParameter] ?string $correlationId = null): ContractInfo
    {
        $response = $this->request('GET', '/api/contract-version', null, false, $correlationId);
        $payload = $this->responsePayload($response);

        if ($payload === null) {
            throw $this->invalidResponse('The contract_info response was empty.', $response['correlation_id']);
        }

        try {
            return ContractInfo::fromEnvelope($payload);
        } catch (InvalidResponseException $exception) {
            throw $this->invalidResponse($exception->getMessage(), $response['correlation_id']);
        }
    }

    /** @return array<string, mixed> */
    public function openApiDocument(#[\SensitiveParameter] ?string $correlationId = null): array
    {
        $response = $this->request('GET', self::OPENAPI_PATH, null, false, $correlationId);
        $document = $this->responsePayload($response);

        if ($document === null) {
            throw $this->invalidResponse('The OpenAPI document response was empty.', $response['correlation_id']);
        }
        $paths = $document['paths'] ?? null;
        $components = $document['components'] ?? null;
        $schemas = is_array($components) ? ($components['schemas'] ?? null) : null;

        if (! is_string($document['openapi'] ?? null)
            || preg_match('/^3\.1\.\d+(?:[-+][0-9A-Za-z.-]+)?$/D', $document['openapi']) !== 1
            || ! is_array($document['info'] ?? null)
            || array_is_list($document['info'])
            || ! is_array($paths)
            || $paths === []
            || array_is_list($paths)
            || ! is_array($components)
            || ($components !== [] && array_is_list($components))
            || ! is_array($schemas)
            || $schemas === []
            || array_is_list($schemas)) {
            throw $this->invalidResponse('The OpenAPI response does not contain an Acceptora OpenAPI 3.1 document with paths and component schemas.', $response['correlation_id']);
        }

        return $document;
    }

    public function projectMetadata(#[\SensitiveParameter] ?string $correlationId = null): ProjectMetadataResult
    {
        $response = $this->request('GET', self::PROJECT_METADATA_PATH, null, true, $correlationId);
        $payload = $this->responsePayload($response);

        if ($payload === null) {
            throw $this->invalidResponse('The project metadata response was empty.', $response['correlation_id']);
        }
        $payload['correlation_id'] = $this->correlationFromData($payload, $response['correlation_id']);

        try {
            return ProjectMetadataResult::fromArray($payload, $response['correlation_id']);
        } catch (InvalidResponseException $exception) {
            throw $this->invalidResponse($exception->getMessage(), $response['correlation_id']);
        }
    }

    /** @param array<array-key, mixed> $payload */
    public function completionGate(
        #[\SensitiveParameter] array $payload,
        #[\SensitiveParameter] ?string $correlationId = null,
    ): CompletionGateResult {
        if ($payload === [] || array_is_list($payload)) {
            throw new InvalidInputException('The completion-gate payload must be a non-empty JSON object.');
        }

        $response = $this->request('POST', '/api/integrations/completion-gate', $payload, true, $correlationId);
        $responsePayload = $this->responsePayload($response);

        if ($responsePayload === null) {
            throw $this->invalidResponse('The completion_gate response was empty.', $response['correlation_id']);
        }
        $responsePayload['correlation_id'] = $this->correlationFromData($responsePayload, $response['correlation_id']);

        try {
            return CompletionGateResult::fromArray($responsePayload);
        } catch (InvalidResponseException $exception) {
            throw $this->invalidResponse($exception->getMessage(), $response['correlation_id']);
        }
    }

    public function verifyCompatibility(#[\SensitiveParameter] ?string $correlationId = null): CompatibilityResult
    {
        $contract = $this->contractInfo($correlationId);

        if ($contract->contractVersion !== $this->manifest->contractVersion()
            || $contract->integrationVersion !== $this->manifest->integrationVersion()
            || $contract->skillVersion !== $this->manifest->skillVersion()) {
            throw new ContractException('The Acceptora server and package versions do not match.', 'CONTRACT_UNSUPPORTED', 422);
        }

        $this->initialize($correlationId);
        $tools = $this->listTools($correlationId);
        $actualNames = [];
        $expected = [];

        foreach ($tools as $tool) {
            if (! is_string($tool['name'] ?? null)) {
                throw new ContractException('The Acceptora MCP tool registry contains an invalid name.', 'MCP_TOOL_DRIFT');
            }

            $actualNames[] = $tool['name'];
        }

        foreach ($this->manifest->tools() as $tool) {
            $expected[$tool['name']] = $tool;
        }

        if (count($actualNames) !== count($expected) || count(array_unique($actualNames, SORT_REGULAR)) !== count($actualNames)
            || array_diff($actualNames, array_keys($expected)) !== [] || array_diff(array_keys($expected), $actualNames) !== []) {
            throw new ContractException('The Acceptora MCP tool registry does not match this package.', 'MCP_TOOL_DRIFT');
        }

        foreach ($tools as $tool) {
            $name = $tool['name'];
            $input = $tool['inputSchema'] ?? null;
            $output = $tool['outputSchema'] ?? null;
            $annotations = $tool['annotations'] ?? null;

            if (! is_array($annotations) || array_is_list($annotations)
                || ! is_bool($annotations['readOnlyHint'] ?? null)
                || $annotations['readOnlyHint'] !== $expected[$name]['read_only']) {
                throw new ContractException('An Acceptora MCP tool annotation does not match this package.', 'MCP_METADATA_DRIFT');
            }

            if (! is_array($input) || ! is_array($output)
                || $this->schemaDigest($input) !== $expected[$name]['input_schema_sha256']
                || $this->schemaDigest($output) !== $expected[$name]['output_schema_sha256']) {
                throw new ContractException('An Acceptora MCP tool schema does not match this package.', 'MCP_SCHEMA_DRIFT');
            }
        }

        return new CompatibilityResult(
            $contract->contractVersion,
            $this->manifest->protocolVersion(),
            $this->manifest->serverName(),
            $this->manifest->serverVersion(),
            $this->manifest->toolNames(),
        );
    }

    /** @param array<array-key, mixed> $arguments */
    public function callTool(
        #[\SensitiveParameter] string $name,
        #[\SensitiveParameter] array $arguments,
        #[\SensitiveParameter] ?string $correlationId = null,
    ): ToolCallResult {
        if (! in_array($name, $this->manifest->toolNames(), true)) {
            throw new InvalidInputException('Unknown Acceptora verification tool.');
        }

        if ($arguments !== [] && array_is_list($arguments)) {
            throw new InvalidInputException('MCP tool arguments must be a JSON object.');
        }

        $this->initialize($correlationId);
        $response = $this->rpc('tools/call', ['name' => $name, 'arguments' => (object) $arguments], $correlationId);
        $rpcOutcome = $this->rpcResult($response, 'tools/call');

        if ($rpcOutcome['error'] !== null) {
            $this->throwErrorSpecification($rpcOutcome['error']);
        }

        $result = $rpcOutcome['result'];
        $content = $result['content'] ?? [];

        if (! is_array($content) || ! array_is_list($content)) {
            throw $this->invalidResponse('The MCP tool response contains invalid content.', $response['correlation_id']);
        }

        if (($result['isError'] ?? false) === true) {
            $error = $this->mcpToolErrorSpecification($result, $response['correlation_id'], $name, $response['status']);
            $this->throwErrorSpecification($error);
        }

        $structured = $result['structuredContent'] ?? null;

        if (! is_array($structured) || array_is_list($structured)) {
            throw $this->invalidResponse('The MCP tool success response must contain object-valued structuredContent.', $response['correlation_id']);
        }

        $normalizedContent = [];

        foreach ($content as $item) {
            if (! is_array($item)) {
                throw $this->invalidResponse('The MCP tool response contains invalid content.', $response['correlation_id']);
            }

            $normalizedContent[] = $item;
        }

        return new ToolCallResult($name, $structured, $normalizedContent);
    }

    public function callRestOperation(
        #[\SensitiveParameter] string $operation,
        #[\SensitiveParameter] array $payload,
        #[\SensitiveParameter] ?string $correlationId = null,
    ): RestOperationResult {
        $path = self::REST_OPERATION_PATHS[$operation] ?? null;

        if ($path === null) {
            throw new InvalidInputException('Unknown Acceptora REST operation.');
        }

        if (! $this->isNonEmptyJsonObject($payload)) {
            throw new InvalidInputException('REST operation payloads must be non-empty JSON objects.');
        }

        $response = $this->request('POST', $path, $this->withVersions($payload), true, $correlationId);
        $data = $this->responsePayload($response);

        if ($data === null) {
            throw $this->invalidResponse("The {$operation} response was empty.", $response['correlation_id']);
        }

        return new RestOperationResult(
            operation: $operation,
            data: $data,
            correlationId: $this->correlationFromData($data, $response['correlation_id']),
        );
    }

    public function resolveFeatureRest(#[\SensitiveParameter] array $payload, #[\SensitiveParameter] ?string $correlationId = null): RestOperationResult
    {
        return $this->callRestOperation('resolve_feature', $payload, $correlationId);
    }

    public function getFeatureContextRest(#[\SensitiveParameter] array $payload, #[\SensitiveParameter] ?string $correlationId = null): RestOperationResult
    {
        return $this->callRestOperation('get_feature_context', $payload, $correlationId);
    }

    public function reconcileChecklistRest(#[\SensitiveParameter] array $payload, #[\SensitiveParameter] ?string $correlationId = null): RestOperationResult
    {
        return $this->callRestOperation('reconcile_checklist', $payload, $correlationId);
    }

    public function getVerificationFeedbackRest(#[\SensitiveParameter] array $payload, #[\SensitiveParameter] ?string $correlationId = null): RestOperationResult
    {
        return $this->callRestOperation('get_verification_feedback', $payload, $correlationId);
    }

    public function addressFeedbackRest(#[\SensitiveParameter] array $payload, #[\SensitiveParameter] ?string $correlationId = null): RestOperationResult
    {
        return $this->callRestOperation('address_feedback', $payload, $correlationId);
    }

    public function getVerificationStatusRest(#[\SensitiveParameter] array $payload, #[\SensitiveParameter] ?string $correlationId = null): RestOperationResult
    {
        return $this->callRestOperation('get_verification_status', $payload, $correlationId);
    }

    public function checkCompletionGateRest(#[\SensitiveParameter] array $payload, #[\SensitiveParameter] ?string $correlationId = null): RestOperationResult
    {
        return $this->callRestOperation('check_completion_gate', $payload, $correlationId);
    }

    public function recordVerificationExceptionRest(#[\SensitiveParameter] array $payload, #[\SensitiveParameter] ?string $correlationId = null): RestOperationResult
    {
        return $this->callRestOperation('record_verification_exception', $payload, $correlationId);
    }

    public function resolveFeature(#[\SensitiveParameter] array $arguments, #[\SensitiveParameter] ?string $correlationId = null): ToolCallResult
    {
        return $this->callTool('resolve_feature', $arguments, $correlationId);
    }

    public function getFeatureContext(#[\SensitiveParameter] array $arguments, #[\SensitiveParameter] ?string $correlationId = null): ToolCallResult
    {
        return $this->callTool('get_feature_context', $arguments, $correlationId);
    }

    public function reconcileChecklist(#[\SensitiveParameter] array $arguments, #[\SensitiveParameter] ?string $correlationId = null): ToolCallResult
    {
        return $this->callTool('reconcile_checklist', $arguments, $correlationId);
    }

    public function getVerificationFeedback(#[\SensitiveParameter] array $arguments, #[\SensitiveParameter] ?string $correlationId = null): ToolCallResult
    {
        return $this->callTool('get_verification_feedback', $arguments, $correlationId);
    }

    public function addressFeedback(#[\SensitiveParameter] array $arguments, #[\SensitiveParameter] ?string $correlationId = null): ToolCallResult
    {
        return $this->callTool('address_feedback', $arguments, $correlationId);
    }

    public function getVerificationStatus(#[\SensitiveParameter] array $arguments, #[\SensitiveParameter] ?string $correlationId = null): ToolCallResult
    {
        return $this->callTool('get_verification_status', $arguments, $correlationId);
    }

    public function checkCompletionGate(#[\SensitiveParameter] array $arguments, #[\SensitiveParameter] ?string $correlationId = null): ToolCallResult
    {
        return $this->callTool('check_completion_gate', $arguments, $correlationId);
    }

    public function recordVerificationException(#[\SensitiveParameter] array $arguments, #[\SensitiveParameter] ?string $correlationId = null): ToolCallResult
    {
        return $this->callTool('record_verification_exception', $arguments, $correlationId);
    }

    private function initialize(#[\SensitiveParameter] ?string $correlationId): void
    {
        if ($this->initialized) {
            return;
        }

        try {
            $response = $this->rpc('initialize', [
                'protocolVersion' => $this->manifest->protocolVersion(),
                'capabilities' => (object) [],
                'clientInfo' => ['name' => 'acceptora-verification-php', 'version' => '1.0.0'],
            ], $correlationId, false, false);
            $rpcOutcome = $this->rpcResult($response, 'initialize');

            if ($rpcOutcome['error'] !== null) {
                $this->throwErrorSpecification($rpcOutcome['error']);
            }

            $result = $rpcOutcome['result'];
            $server = $result['serverInfo'] ?? null;

            if (($result['protocolVersion'] ?? null) !== $this->manifest->protocolVersion()
                || ! is_array($server) || ($server['name'] ?? null) !== $this->manifest->serverName()
                || ($server['version'] ?? null) !== $this->manifest->serverVersion()) {
                throw new ContractException('The Acceptora MCP protocol or server version does not match this package.', 'MCP_VERSION_DRIFT');
            }

            $this->initialized = true;
            $this->rpc('notifications/initialized', [], $correlationId, true);
        } catch (\Throwable $exception) {
            $this->initialized = false;
            $this->sessionId = null;

            throw $exception;
        }
    }

    /** @return list<array<string, mixed>> */
    private function listTools(#[\SensitiveParameter] ?string $correlationId): array
    {
        $tools = [];
        $cursor = null;
        $aggregateBytes = 0;

        for ($page = 0; $page < 20; $page++) {
            $params = $cursor === null ? [] : ['cursor' => $cursor];
            $response = $this->rpc('tools/list', $params, $correlationId);
            $rpcOutcome = $this->rpcResult($response, 'tools/list');

            if ($rpcOutcome['error'] !== null) {
                $this->throwErrorSpecification($rpcOutcome['error']);
            }

            $result = $rpcOutcome['result'];
            $pageTools = $result['tools'] ?? null;

            if (! is_array($pageTools) || ! array_is_list($pageTools)) {
                throw $this->invalidResponse('The MCP tools/list response is invalid.', $response['correlation_id']);
            }

            try {
                $aggregateBytes += strlen(json_encode(
                    $pageTools,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                ));
            } catch (JsonException) {
                throw $this->invalidResponse('The MCP tools/list response is invalid.', $response['correlation_id']);
            }

            if ($aggregateBytes > $this->maxResponseBytes
                || count($tools) + count($pageTools) > self::MAX_TOOL_DEFINITIONS) {
                throw $this->invalidResponse('The MCP tools/list response exceeded the aggregate safety limit.', $response['correlation_id']);
            }

            foreach ($pageTools as $tool) {
                if (! is_array($tool)) {
                    throw $this->invalidResponse('The MCP tools/list response is invalid.', $response['correlation_id']);
                }

                $tools[] = $tool;
            }

            $next = $result['nextCursor'] ?? null;

            if ($next === null) {
                return $tools;
            }

            if (! is_string($next) || $next === '' || $next === $cursor) {
                throw $this->invalidResponse('The MCP tools/list cursor is invalid.', $response['correlation_id']);
            }

            $cursor = $next;
        }

        throw new ContractException('The MCP tools/list response exceeded the pagination limit.', 'MCP_PROTOCOL_DRIFT');
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{payload: ?array<string, mixed>, status: int, correlation_id: string}
     */
    private function rpc(
        #[\SensitiveParameter] string $method,
        #[\SensitiveParameter] array $params,
        #[\SensitiveParameter] ?string $correlationId,
        bool $notification = false,
        bool $includeProtocolHeader = true,
    ): array {
        $id = $notification ? null : ++$this->rpcId;
        $payload = ['jsonrpc' => '2.0', 'method' => $method, 'params' => (object) $params];

        if ($id !== null) {
            $payload['id'] = $id;
        }

        $headers = [];

        if ($includeProtocolHeader && $this->initialized) {
            $headers['MCP-Protocol-Version'] = $this->manifest->protocolVersion();
        }

        if ($this->sessionId !== null) {
            $headers['MCP-Session-Id'] = $this->sessionId;
        }

        $response = $this->request('POST', '/mcp', $payload, true, $correlationId, $notification ? [200, 202, 204] : [200], $headers, $notification, $id);

        if ($notification) {
            return $response;
        }

        $body = $this->responsePayload($response);

        if ($body === null) {
            throw $this->invalidResponse("The MCP {$method} response was empty.", $response['correlation_id']);
        }

        if (($body['jsonrpc'] ?? null) !== '2.0' || ($body['id'] ?? null) !== $id) {
            throw $this->invalidResponse('The MCP server returned an invalid JSON-RPC envelope.', $response['correlation_id']);
        }

        return $response;
    }

    /**
     * @param  array{payload: ?array<string, mixed>, status: int, correlation_id: string}  $response
     * @return array{
     *     result: array<string, mixed>|null,
     *     error: array{
     *         class: class-string<VerificationException>,
     *         message: string,
     *         error_code: string,
     *         status_code: int|null,
     *         retryable: bool,
     *         retry_after: int|null,
     *         correlation_id: string|null,
     *         details: array<string, mixed>,
     *         recovery_instruction: string|null
     *     }|null
     * }
     */
    private function rpcResult(#[\SensitiveParameter] array $response, #[\SensitiveParameter] string $operation): array
    {
        $payload = $this->responsePayload($response);

        if ($payload === null) {
            return [
                'result' => null,
                'error' => $this->invalidResponseSpecification(
                    "The {$operation} response was empty.",
                    $response['correlation_id'],
                ),
            ];
        }

        if (isset($payload['error'])) {
            $error = is_array($payload['error']) ? $payload['error'] : [];
            $code = is_int($error['code'] ?? null) ? (string) $error['code'] : 'MCP_RPC_ERROR';

            $this->logRequestFailure("MCP {$operation}", $response['correlation_id'], $code, $response['status'], false);

            return [
                'result' => null,
                'error' => [
                    'class' => ContractException::class,
                    'message' => "The MCP server returned a JSON-RPC error for {$operation}.",
                    'error_code' => $code,
                    'status_code' => $response['status'],
                    'retryable' => false,
                    'retry_after' => null,
                    'correlation_id' => $response['correlation_id'],
                    'details' => [],
                    'recovery_instruction' => null,
                ],
            ];
        }

        if (! is_array($payload['result'] ?? null)) {
            return [
                'result' => null,
                'error' => $this->invalidResponseSpecification(
                    "The MCP {$operation} response omitted its result object.",
                    $response['correlation_id'],
                ),
            ];
        }

        return ['result' => $payload['result'], 'error' => null];
    }

    /**
     * @param  array<string, mixed>|null  $json
     * @param  list<int>  $acceptedStatuses
     * @param  array<string, string>  $extraHeaders
     * @return array{payload: ?array<string, mixed>, status: int, correlation_id: string}
     */
    private function request(
        string $method,
        string $path,
        #[\SensitiveParameter] ?array $json,
        bool $authenticated,
        #[\SensitiveParameter] ?string $correlationId,
        array $acceptedStatuses = [200],
        #[\SensitiveParameter] array $extraHeaders = [],
        bool $allowEmpty = false,
        ?int $rpcId = null,
    ): array {
        $correlationId = $this->correlationId($correlationId);
        $headers = [
            'Accept' => $path === '/mcp' ? 'application/json, text/event-stream' : 'application/json',
            'User-Agent' => $this->userAgent,
            'X-Correlation-ID' => $correlationId,
            ...$extraHeaders,
        ];

        if ($authenticated) {
            if ($this->token === null) {
                throw new AuthenticationException(
                    'An Acceptora bearer token is required for authenticated operations.',
                    'AUTH_REQUIRED',
                    401,
                    correlationId: $correlationId,
                );
            }

            $headers['Authorization'] = 'Bearer '.$this->token;
        }

        $options = [
            'headers' => $headers,
            'http_errors' => false,
            'allow_redirects' => false,
            'timeout' => $this->timeout,
            'read_timeout' => $this->timeout,
            'connect_timeout' => min($this->connectTimeout, $this->timeout),
            'verify' => true,
            'stream' => true,
        ];

        if ($this->usesPlainHttpLoopbackOrigin()) {
            $options['proxy'] = ['no' => ['localhost', '127.0.0.1', '::1', '[::1]']];
        }

        if ($json !== null) {
            try {
                $encodedRequest = json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } catch (JsonException) {
                throw new InvalidInputException('Acceptora request payloads must contain valid JSON values.');
            }

            if (strlen($encodedRequest) > $this->maxRequestBytes) {
                throw new InvalidInputException('The Acceptora request exceeds the configured safety limit.');
            }

            $options['headers']['Content-Type'] = 'application/json';
            $options['body'] = $encodedRequest;
        }

        try {
            $response = $this->http->request($method, $this->endpoint($path), $options);
        } catch (GuzzleException) {
            throw $this->transportException($path, $correlationId);
        }

        $responseCorrelationId = $response->getHeaderLine('X-Correlation-ID');

        if (preg_match('/^[A-Za-z0-9._:-]{1,200}$/D', $responseCorrelationId) === 1
            && $this->redactString($responseCorrelationId) === $responseCorrelationId) {
            $correlationId = $responseCorrelationId;
        }

        $bodyOutcome = $this->readResponseBody($response, $correlationId, $path);

        if ($bodyOutcome['error'] !== null) {
            $this->throwErrorSpecification($bodyOutcome['error']);
        }

        $body = $bodyOutcome['body'];

        $status = $response->getStatusCode();

        if (! in_array($status, $acceptedStatuses, true)) {
            $error = $this->remoteErrorSpecification(
                $this->decodeErrorBody($body),
                $status,
                $response->getHeaderLine('Retry-After'),
                $correlationId,
                $path,
            );
            $this->throwErrorSpecification($error);
        }

        $sessionId = $path === '/mcp' ? $response->getHeaderLine('MCP-Session-Id') : '';

        if ($sessionId !== '') {
            if (preg_match('/^[A-Za-z0-9._:-]{1,256}$/D', $sessionId) !== 1
                || $this->redactString($sessionId) !== $sessionId) {
                throw $this->invalidResponse('The MCP server returned an invalid session identifier.', $correlationId);
            }

            if (($this->sessionId === null && $this->initialized)
                || ($this->sessionId !== null && $sessionId !== $this->sessionId)) {
                throw $this->invalidResponse('The MCP server changed the session identifier after initialization.', $correlationId);
            }

            $this->sessionId = $sessionId;
        }

        $decodedOutcome = $this->decode($body, $response->getHeaderLine('Content-Type'), $rpcId, $allowEmpty, $correlationId);

        if ($decodedOutcome['error'] !== null) {
            $this->throwErrorSpecification($decodedOutcome['error']);
        }

        $decoded = $decodedOutcome['payload'];

        $this->logger->info('acceptora.verification.request_completed', [
            'event' => 'acceptora.verification.request_completed',
            'operation' => $path,
            'external_service' => 'acceptora',
            'correlation_id' => $correlationId,
            'http_status' => $status,
            'attempt' => 1,
            'outcome' => 'response_received',
        ]);

        return ['payload' => $decoded, 'status' => $status, 'correlation_id' => $correlationId];
    }

    /**
     * @return array{body: string, error: null}|array{
     *     body: null,
     *     error: array{
     *         class: class-string<VerificationException>,
     *         message: string,
     *         error_code: string,
     *         status_code: int|null,
     *         retryable: bool,
     *         retry_after: int|null,
     *         correlation_id: string|null,
     *         details: array<string, mixed>,
     *         recovery_instruction: string|null
     *     }
     * }
     */
    private function readResponseBody(
        #[\SensitiveParameter] ResponseInterface $response,
        #[\SensitiveParameter] string $correlationId,
        string $operation,
    ): array {
        try {
            $contentLength = $response->getHeaderLine('Content-Length');

            if ($contentLength !== '' && ctype_digit($contentLength) && (int) $contentLength > $this->maxResponseBytes) {
                return [
                    'body' => null,
                    'error' => $this->invalidResponseSpecification(
                        'The Acceptora response exceeded the configured safety limit.',
                        $correlationId,
                    ),
                ];
            }

            $stream = $response->getBody();
            $body = '';

            while (! $stream->eof()) {
                $chunk = $stream->read(min(8192, $this->maxResponseBytes - strlen($body) + 1));

                if ($chunk === '') {
                    break;
                }

                $body .= $chunk;

                if (strlen($body) > $this->maxResponseBytes) {
                    return [
                        'body' => null,
                        'error' => $this->invalidResponseSpecification(
                            'The Acceptora response exceeded the configured safety limit.',
                            $correlationId,
                        ),
                    ];
                }
            }
        } catch (\Throwable) {
            return [
                'body' => null,
                'error' => $this->transportExceptionSpecification($operation, $correlationId),
            ];
        }

        return ['body' => $body, 'error' => null];
    }

    /** @return array<string, mixed>|null */
    private function decodeErrorBody(#[\SensitiveParameter] string $body): ?array
    {
        if (trim($body) === '') {
            return null;
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : null;
    }

    /**
     * @return array{payload: array<string, mixed>|null, error: null}|array{
     *     payload: null,
     *     error: array{
     *         class: class-string<VerificationException>,
     *         message: string,
     *         error_code: string,
     *         status_code: int|null,
     *         retryable: bool,
     *         retry_after: int|null,
     *         correlation_id: string|null,
     *         details: array<string, mixed>,
     *         recovery_instruction: string|null
     *     }
     * }
     */
    private function decode(
        #[\SensitiveParameter] string $body,
        string $contentType,
        ?int $rpcId,
        bool $allowEmpty,
        #[\SensitiveParameter] string $correlationId,
    ): array {
        if (trim($body) === '') {
            if ($allowEmpty) {
                return ['payload' => null, 'error' => null];
            }

            return [
                'payload' => null,
                'error' => $this->invalidResponseSpecification('The Acceptora response was empty.', $correlationId),
            ];
        }

        $candidates = [];

        if (str_contains(strtolower($contentType), 'text/event-stream') || str_starts_with(ltrim($body), 'event:')) {
            foreach (preg_split('/\R\R+/', trim($body)) ?: [] as $event) {
                $data = [];

                foreach (preg_split('/\R/', $event) ?: [] as $line) {
                    if (str_starts_with($line, 'data:')) {
                        $data[] = ltrim(substr($line, 5));
                    }
                }

                if ($data !== []) {
                    $candidates[] = implode("\n", $data);
                }
            }
        } else {
            $candidates[] = $body;
        }

        foreach ($candidates as $candidate) {
            try {
                $decoded = json_decode($candidate, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            if (is_array($decoded) && ! array_is_list($decoded)
                && ($rpcId === null || ($decoded['id'] ?? null) === $rpcId)) {
                return ['payload' => $decoded, 'error' => null];
            }
        }

        return [
            'payload' => null,
            'error' => $this->invalidResponseSpecification(
                'The Acceptora response was not a valid JSON object.',
                $correlationId,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array{
     *     class: class-string<VerificationException>,
     *     message: string,
     *     error_code: string,
     *     status_code: int|null,
     *     retryable: bool,
     *     retry_after: int|null,
     *     correlation_id: string|null,
     *     details: array<string, mixed>,
     *     recovery_instruction: string|null
     * }
     */
    private function remoteErrorSpecification(
        #[\SensitiveParameter] ?array $payload,
        int $status,
        string $retryAfterHeader,
        #[\SensitiveParameter] string $correlationId,
        string $operation,
    ): array {
        $error = is_array($payload['error'] ?? null) ? $payload['error'] : [];
        $code = is_string($error['code'] ?? null)
            && preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D', $error['code']) === 1
                ? $error['code']
                : 'HTTP_ERROR';
        $message = is_string($error['message'] ?? null) ? $error['message'] : 'The Acceptora verification API rejected the request.';
        $message = $this->redactString(mb_substr($message, 0, 1000));
        $retryable = is_bool($error['retryable'] ?? null) ? $error['retryable'] : in_array($status, [429, 502, 503], true);
        $details = is_array($error['details'] ?? null) ? $this->redact($error['details']) : [];
        $remoteCorrelation = is_string($error['correlation_id'] ?? null)
            && preg_match('/^[A-Za-z0-9._:-]{1,200}$/D', $error['correlation_id']) === 1
            && $this->redactString($error['correlation_id']) === $error['correlation_id']
                ? $error['correlation_id']
                : $correlationId;
        $recoveryInstruction = is_string($error['recovery_instruction'] ?? null)
            ? $this->redactString(mb_substr($error['recovery_instruction'], 0, 2000))
            : null;
        $retryAfter = $this->retryAfter($retryAfterHeader);
        $class = match (true) {
            $status === 401 || $code === 'AUTH_REQUIRED' => AuthenticationException::class,
            $status === 403 || $code === 'SCOPE_DENIED' => AuthorizationException::class,
            $status === 429 || $code === 'RATE_LIMITED' => RateLimitException::class,
            $code === 'CONTRACT_UNSUPPORTED' => ContractException::class,
            default => ApiException::class,
        };

        $this->logRequestFailure($operation, $remoteCorrelation, $code, $status, $retryable);

        return [
            'class' => $class,
            'message' => $message,
            'error_code' => $code,
            'status_code' => $status,
            'retryable' => $retryable,
            'retry_after' => $retryAfter,
            'correlation_id' => $remoteCorrelation,
            'details' => $details,
            'recovery_instruction' => $recoveryInstruction,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{
     *     class: class-string<VerificationException>,
     *     message: string,
     *     error_code: string,
     *     status_code: int|null,
     *     retryable: bool,
     *     retry_after: int|null,
     *     correlation_id: string|null,
     *     details: array<string, mixed>,
     *     recovery_instruction: string|null
     * }
     */
    private function mcpToolErrorSpecification(
        #[\SensitiveParameter] array $result,
        #[\SensitiveParameter] string $correlationId,
        #[\SensitiveParameter] string $tool,
        int $httpStatus,
    ): array {
        foreach (($result['content'] ?? []) as $content) {
            if (! is_array($content) || ($content['type'] ?? null) !== 'text' || ! is_string($content['text'] ?? null)) {
                continue;
            }

            try {
                $decoded = json_decode($content['text'], true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            if (is_array($decoded) && ! array_is_list($decoded) && is_array($decoded['error'] ?? null)) {
                $status = is_int($decoded['error']['http_status'] ?? null) ? $decoded['error']['http_status'] : 422;

                return $this->remoteErrorSpecification($decoded, $status, '', $correlationId, "MCP tools/call {$tool}");
            }
        }

        $this->logRequestFailure("MCP tools/call {$tool}", $correlationId, 'MCP_TOOL_ERROR', $httpStatus, false);

        return [
            'class' => ApiException::class,
            'message' => 'The Acceptora MCP tool reported an error.',
            'error_code' => 'MCP_TOOL_ERROR',
            'status_code' => null,
            'retryable' => false,
            'retry_after' => null,
            'correlation_id' => $correlationId,
            'details' => [],
            'recovery_instruction' => null,
        ];
    }

    /**
     * @param  array{
     *     class: class-string<VerificationException>,
     *     message: string,
     *     error_code: string,
     *     status_code: int|null,
     *     retryable: bool,
     *     retry_after: int|null,
     *     correlation_id: string|null,
     *     details: array<string, mixed>,
     *     recovery_instruction: string|null
     * }  $error
     */
    private function throwErrorSpecification(#[\SensitiveParameter] array $error): never
    {
        $class = $error['class'];

        throw new $class(
            message: $error['message'],
            errorCode: $error['error_code'],
            statusCode: $error['status_code'],
            retryable: $error['retryable'],
            retryAfter: $error['retry_after'],
            correlationId: $error['correlation_id'],
            details: $error['details'],
            recoveryInstruction: $error['recovery_instruction'],
        );
    }

    private function logRequestFailure(
        #[\SensitiveParameter] string $operation,
        #[\SensitiveParameter] string $correlationId,
        #[\SensitiveParameter] string $errorCode,
        ?int $status,
        bool $retryable,
    ): void {
        $context = [
            'event' => 'acceptora.verification.request_failed',
            'operation' => $operation,
            'external_service' => 'acceptora',
            'correlation_id' => $correlationId,
            'error_code' => $errorCode,
            'retryable' => $retryable,
            'attempt' => 1,
            'outcome' => 'rejected',
        ];

        if ($status !== null) {
            $context['http_status'] = $status;
        }

        $this->logger->warning('acceptora.verification.request_failed', $context);
    }

    private function transportException(
        #[\SensitiveParameter] string $operation,
        #[\SensitiveParameter] string $correlationId,
    ): TransportException {
        $error = $this->transportExceptionSpecification($operation, $correlationId);

        return new TransportException(
            message: $error['message'],
            errorCode: $error['error_code'],
            statusCode: $error['status_code'],
            retryable: $error['retryable'],
            retryAfter: $error['retry_after'],
            correlationId: $error['correlation_id'],
            details: $error['details'],
            recoveryInstruction: $error['recovery_instruction'],
        );
    }

    /**
     * @return array{
     *     class: class-string<VerificationException>,
     *     message: string,
     *     error_code: string,
     *     status_code: int|null,
     *     retryable: bool,
     *     retry_after: int|null,
     *     correlation_id: string|null,
     *     details: array<string, mixed>,
     *     recovery_instruction: string|null
     * }
     */
    private function transportExceptionSpecification(
        #[\SensitiveParameter] string $operation,
        #[\SensitiveParameter] string $correlationId,
    ): array {
        $this->logger->error('acceptora.verification.transport_failed', [
            'event' => 'acceptora.verification.transport_failed',
            'operation' => $operation,
            'external_service' => 'acceptora',
            'correlation_id' => $correlationId,
            'attempt' => 1,
            'outcome' => 'transport_error',
        ]);

        return [
            'class' => TransportException::class,
            'message' => 'The Acceptora verification request could not be completed.',
            'error_code' => 'TRANSPORT_ERROR',
            'status_code' => null,
            'retryable' => true,
            'retry_after' => null,
            'correlation_id' => $correlationId,
            'details' => [],
            'recovery_instruction' => null,
        ];
    }

    /**
     * @param  array{payload: ?array<string, mixed>, status: int, correlation_id: string}  $response
     * @return array<string, mixed>|null
     */
    private function responsePayload(#[\SensitiveParameter] array $response): ?array
    {
        return is_array($response['payload']) ? $response['payload'] : null;
    }

    /** @param array<string, mixed> $data */
    private function correlationFromData(#[\SensitiveParameter] array $data, #[\SensitiveParameter] string $fallback): string
    {
        $correlationId = $data['correlation_id'] ?? null;

        return is_string($correlationId)
            && preg_match('/^[A-Za-z0-9._:-]{1,200}$/D', $correlationId) === 1
            && $this->redactString($correlationId) === $correlationId
                ? $correlationId
                : $fallback;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withVersions(#[\SensitiveParameter] array $payload): array
    {
        $payload['versions'] ??= [
            'integration_name' => 'acceptora-verification-php',
            'integration_version' => $this->manifest->integrationVersion(),
            'skill_version' => $this->manifest->skillVersion(),
            'contract_version' => $this->manifest->contractVersion(),
        ];

        return $payload;
    }

    /** @param array<array-key, mixed> $payload */
    private function isNonEmptyJsonObject(#[\SensitiveParameter] array $payload): bool
    {
        return $payload !== [] && ! array_is_list($payload);
    }

    private function invalidResponse(
        #[\SensitiveParameter] string $message,
        #[\SensitiveParameter] ?string $correlationId = null,
    ): InvalidResponseException {
        $error = $this->invalidResponseSpecification($message, $correlationId);

        return new InvalidResponseException(
            message: $error['message'],
            errorCode: $error['error_code'],
            statusCode: $error['status_code'],
            retryable: $error['retryable'],
            retryAfter: $error['retry_after'],
            correlationId: $error['correlation_id'],
            details: $error['details'],
            recoveryInstruction: $error['recovery_instruction'],
        );
    }

    /**
     * @return array{
     *     class: class-string<VerificationException>,
     *     message: string,
     *     error_code: string,
     *     status_code: int|null,
     *     retryable: bool,
     *     retry_after: int|null,
     *     correlation_id: string|null,
     *     details: array<string, mixed>,
     *     recovery_instruction: string|null
     * }
     */
    private function invalidResponseSpecification(
        #[\SensitiveParameter] string $message,
        #[\SensitiveParameter] ?string $correlationId = null,
    ): array {
        $this->logger->error('acceptora.verification.invalid_response', [
            'event' => 'acceptora.verification.invalid_response', 'operation' => 'response_decode',
            'external_service' => 'acceptora',
            'correlation_id' => $correlationId, 'attempt' => 1, 'outcome' => 'invalid_response',
        ]);

        return [
            'class' => InvalidResponseException::class,
            'message' => $this->redactString(mb_substr($message, 0, 1_000)),
            'error_code' => 'INVALID_RESPONSE',
            'status_code' => null,
            'retryable' => false,
            'retry_after' => null,
            'correlation_id' => $correlationId,
            'details' => [],
            'recovery_instruction' => null,
        ];
    }

    private function endpoint(string $path): string
    {
        return rtrim($this->baseUrl, '/').$path;
    }

    private function correlationId(#[\SensitiveParameter] ?string $correlationId): string
    {
        $correlationId ??= 'sdk_'.bin2hex(random_bytes(16));

        if (preg_match('/^[A-Za-z0-9._:-]{1,120}$/D', $correlationId) !== 1
            || $this->redactString($correlationId) !== $correlationId) {
            throw new InvalidInputException('Correlation IDs must be 1 to 120 URL-safe characters.');
        }

        return $correlationId;
    }

    /** @param array<string, mixed> $schema */
    private function schemaDigest(#[\SensitiveParameter] array $schema): string
    {
        return 'sha256:'.hash('sha256', json_encode($this->canonicalize($schema), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function canonicalize(#[\SensitiveParameter] mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $child) {
            $value[$key] = $this->canonicalize($child);
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    private function redact(#[\SensitiveParameter] mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->redactString($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        $redacted = [];

        foreach ($value as $key => $child) {
            $redactedKey = is_string($key) ? $this->redactString($key) : $key;

            if (is_string($key) && preg_match('/(?:authorization|cookie|credential|password|secret|token)/i', $key) === 1) {
                $redacted[$redactedKey] = '[redacted]';

                continue;
            }

            $redacted[$redactedKey] = $this->redact($child);
        }

        return $redacted;
    }

    private function redactString(#[\SensitiveParameter] string $value): string
    {
        if ($this->token !== null) {
            $value = str_replace($this->token, '[redacted]', $value);
        }

        if (preg_match(self::PRIVATE_KEY_MARKER_PATTERN, $value) === 1) {
            return '[redacted]';
        }

        return preg_replace(
            [
                '/\bavt_[0-9A-HJKMNP-TV-Z]{26}_[A-Za-z0-9]{48}\b/',
                self::GITHUB_TOKEN_PATTERN,
            ],
            '[redacted]',
            $value,
        ) ?? '[redacted]';
    }

    private function retryAfter(#[\SensitiveParameter] string $value): ?int
    {
        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : max(0, $timestamp - time());
    }

    private function validateConfiguration(): void
    {
        if ($this->token !== null && preg_match(self::TOKEN_PATTERN, $this->token) !== 1) {
            throw new ConfigurationException('The Acceptora bearer token has an invalid format.');
        }

        $parts = parse_url($this->baseUrl);
        $authority = [];
        $authorityMatched = preg_match(
            '/\A[a-z][a-z0-9+.-]*:\/\/([^\/?#]*)/i',
            $this->baseUrl,
            $authority,
        ) === 1;

        if ($this->baseUrl !== trim($this->baseUrl)
            || preg_match('/[\x00-\x20\x7F"\\\\]/', $this->baseUrl) === 1
            || $this->redactString($this->baseUrl) !== $this->baseUrl
            || ! is_array($parts) || ! is_string($parts['scheme'] ?? null)
            || ! is_string($parts['host'] ?? null) || $parts['host'] === ''
            || array_key_exists('user', $parts) || array_key_exists('pass', $parts)
            || array_key_exists('query', $parts) || array_key_exists('fragment', $parts)
            || (isset($parts['path']) && ! in_array($parts['path'], ['', '/'], true))
            || ! $authorityMatched
            || ! $this->urlAuthorityMatchesParsedComponents($authority[1], $parts)) {
            throw new ConfigurationException('The Acceptora base URL must be an absolute HTTP or HTTPS origin without credentials, path, query, or fragment.');
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new ConfigurationException('The Acceptora base URL must use HTTP or HTTPS.');
        }

        if ($scheme === 'http' && ! in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)) {
            throw new ConfigurationException('Plain HTTP is allowed only for loopback development endpoints.');
        }

        if (! is_finite($this->timeout) || ! is_finite($this->connectTimeout)
            || $this->timeout <= 0 || $this->connectTimeout <= 0 || $this->maxRequestBytes < 1024
            || $this->maxResponseBytes < 1024 || trim($this->userAgent) === ''
            || $this->userAgent !== trim($this->userAgent)
            || $this->redactString($this->userAgent) !== $this->userAgent
            || preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $this->userAgent) === 1
            || strlen($this->userAgent) > 255) {
            throw new ConfigurationException('Timeouts, request and response limits, and the user agent must contain valid values.');
        }
    }

    private function usesPlainHttpLoopbackOrigin(): bool
    {
        $parts = parse_url($this->baseUrl);

        if (! is_array($parts) || ! is_string($parts['scheme'] ?? null) || ! is_string($parts['host'] ?? null)) {
            return false;
        }

        return strtolower($parts['scheme']) === 'http'
            && in_array(strtolower($parts['host']), ['localhost', '127.0.0.1', '::1', '[::1]'], true);
    }

    /** @param array<string, mixed> $parts */
    private function urlAuthorityMatchesParsedComponents(
        #[\SensitiveParameter] string $authority,
        #[\SensitiveParameter] array $parts,
    ): bool {
        $host = $parts['host'] ?? null;

        if (! is_string($host)) {
            return false;
        }

        $expected = $host;

        if (array_key_exists('port', $parts)) {
            if (! is_int($parts['port']) || $parts['port'] < 1 || $parts['port'] > 65_535) {
                return false;
            }

            $expected .= ':'.$parts['port'];
        }

        return strcasecmp($authority, $expected) === 0;
    }
}
