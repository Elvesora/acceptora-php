<?php

declare(strict_types=1);

namespace Acceptora\Verification\Tests;

use Acceptora\Verification\Client;
use Acceptora\Verification\Exception\ApiException;
use Acceptora\Verification\Exception\AuthenticationException;
use Acceptora\Verification\Exception\AuthorizationException;
use Acceptora\Verification\Exception\ContractException;
use Acceptora\Verification\Exception\InvalidResponseException;
use Acceptora\Verification\Exception\RateLimitException;
use Acceptora\Verification\Exception\TransportException;
use Acceptora\Verification\Exception\VerificationException;
use Acceptora\Verification\Support\ContractManifest;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class ClientTest extends TestCase
{
    private const TOKEN = 'avt_01ARZ3NDEKTSV4RRFFQ69G5FAV_'
        .'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

    public function test_public_discovery_endpoints_are_available_without_a_token(): void
    {
        $history = new RequestHistory;
        $client = $this->client([
            $this->jsonResponse(200, [
                'data' => [
                    'status' => 'ok', 'ready' => true, 'service' => 'acceptora',
                    'checked_at' => '2026-08-03T00:00:00+00:00',
                    'components' => ['database' => ['status' => 'healthy', 'required' => true]],
                ],
                'meta' => ['contract_version' => '1.0.0'],
            ]),
            $this->contractResponse(),
            $this->jsonResponse(200, [
                'openapi' => '3.1.0',
                'info' => ['title' => 'Acceptora Integration API', 'version' => '1.0.0'],
                'paths' => ['/api/v1/integrations/project' => ['get' => []]],
                'components' => ['schemas' => ['Project' => ['type' => 'object']]],
            ]),
        ], $history, token: null);

        $readiness = $client->readiness('request-readiness');
        $contract = $client->contractInfo('request-contract');
        $openApi = $client->openApiDocument('request-openapi');

        $this->assertTrue($readiness->ready);
        $this->assertSame('healthy', $readiness->components['database']['status']);
        $this->assertSame('1.0.0', $contract->contractVersion);
        $this->assertSame('3.1.0', $openApi['openapi']);
        $this->assertSame('/api/health', $history->request(0)->getUri()->getPath());
        $this->assertSame('/api/contract-version', $history->request(1)->getUri()->getPath());
        $this->assertSame(Client::OPENAPI_PATH, $history->request(2)->getUri()->getPath());
        $this->assertFalse($history->request(0)->hasHeader('Authorization'));
        $this->assertFalse($history->request(1)->hasHeader('Authorization'));
        $this->assertFalse($history->request(2)->hasHeader('Authorization'));
        $this->assertSame('request-openapi', $history->request(2)->getHeaderLine('X-Correlation-ID'));
        $this->assertSame(15.0, $history->options(0)['timeout']);
        $this->assertSame(15.0, $history->options(0)['read_timeout']);
    }

    public function test_completion_gate_sends_bearer_auth_and_returns_a_typed_result(): void
    {
        $history = new RequestHistory;
        $client = $this->client([
            $this->jsonResponse(200, [
                'outcome' => 'pass', 'feature_id' => 'feat_01J00000000000000000000001',
                'reason_code' => 'SYNCHRONIZED', 'reason' => 'The final source is synchronized.',
                'last_synchronized_digest' => 'sha256:'.str_repeat('a', 64),
                'last_synchronized_checklist_revision' => 3, 'recovery_instruction' => null,
                'correlation_id' => self::TOKEN,
            ], ['X-Correlation-ID' => 'corr-server']),
        ], $history);
        $payload = [
            'project_id' => 'proj_01J00000000000000000000001',
            'source_url' => 'https://example.test/café',
            'versions' => ['contract_version' => '1.0.0'],
        ];

        $result = $client->completionGate($payload, 'corr-client');

        $this->assertSame('pass', $result->outcome);
        $this->assertSame('corr-server', $result->correlationId);
        $request = $history->request(0);
        $this->assertSame('Bearer '.self::TOKEN, $request->getHeaderLine('Authorization'));
        $this->assertSame('corr-client', $request->getHeaderLine('X-Correlation-ID'));
        $this->assertSame('/api/integrations/completion-gate', $request->getUri()->getPath());
        $this->assertSame($payload, json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame(
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            (string) $request->getBody(),
        );
    }

    public function test_remote_errors_are_typed_preserve_unknown_codes_and_never_expose_the_token(): void
    {
        $cases = [
            [401, 'AUTH_REQUIRED', AuthenticationException::class, ''],
            [429, 'RATE_LIMITED', RateLimitException::class, '17'],
            [409, 'FUTURE_CONFLICT', ApiException::class, ''],
        ];

        foreach ($cases as [$status, $code, $class, $retryAfter]) {
            $logger = new ArrayLogger;
            $client = $this->client([
                $this->jsonResponse($status, [
                    'error' => [
                        'code' => $code, 'message' => 'Rejected '.self::TOKEN, 'http_status' => $status,
                        'retryable' => $status === 429, 'correlation_id' => 'corr-error',
                        'details' => [self::TOKEN => ['echo' => self::TOKEN]],
                        'recovery_instruction' => 'Reconnect with '.self::TOKEN,
                    ],
                ], $retryAfter === '' ? [] : ['Retry-After' => $retryAfter]),
            ], logger: $logger);

            try {
                $client->completionGate(['project_id' => 'proj_test']);
                $this->fail('Expected a typed API exception.');
            } catch (ApiException $exception) {
                $this->assertInstanceOf($class, $exception);
                $this->assertSame($code, $exception->errorCode);
                $this->assertStringNotContainsString(self::TOKEN, $exception->getMessage());
                $this->assertStringNotContainsString(self::TOKEN, json_encode($exception->details, JSON_THROW_ON_ERROR));
                $this->assertSame($retryAfter === '' ? null : (int) $retryAfter, $exception->retryAfter);
                $this->assertSame('Reconnect with [redacted]', $exception->recoveryInstruction);
                $this->assertStringNotContainsString(self::TOKEN, json_encode($logger->records, JSON_THROW_ON_ERROR));
            }
        }
    }

    public function test_non_json_rate_limit_responses_keep_status_and_retry_metadata(): void
    {
        $client = $this->client([
            new Response(429, ['Content-Type' => 'text/html', 'Retry-After' => '11'], '<h1>Slow down</h1>'),
        ]);

        try {
            $client->completionGate(['project_id' => 'proj_test']);
            $this->fail('Expected a rate-limit exception.');
        } catch (RateLimitException $exception) {
            $this->assertSame('HTTP_ERROR', $exception->errorCode);
            $this->assertSame(429, $exception->statusCode);
            $this->assertTrue($exception->retryable);
            $this->assertSame(11, $exception->retryAfter);
        }
    }

    public function test_named_mcp_tool_performs_initialize_notification_and_call_with_session_headers(): void
    {
        $history = new RequestHistory;
        $client = $this->client([
            $this->rpcResponse(1, [
                'protocolVersion' => '2025-11-25', 'capabilities' => ['tools' => (object) []],
                'serverInfo' => ['name' => 'Acceptora Verification', 'version' => '1.0.0'],
            ], ['MCP-Session-Id' => 'session-1']),
            new Response(202),
            $this->rpcResponse(2, [
                'content' => [['type' => 'text', 'text' => 'ok']], 'isError' => false,
                'structuredContent' => ['feature_id' => 'feat_01J00000000000000000000001', 'status' => 'ready'],
            ]),
        ], $history);

        $result = $client->getVerificationStatus(['feature_id' => 'feat_01J00000000000000000000001'], 'corr-mcp');

        $this->assertSame('ready', $result->structuredContent['status']);
        $this->assertSame(['initialize', 'notifications/initialized', 'tools/call'], array_map(
            static fn (RequestInterface $request): string => json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR)['method'],
            $history->requests(),
        ));
        $this->assertSame('session-1', $history->request(1)->getHeaderLine('MCP-Session-Id'));
        $this->assertSame('2025-11-25', $history->request(1)->getHeaderLine('MCP-Protocol-Version'));
        $this->assertSame('session-1', $history->request(2)->getHeaderLine('MCP-Session-Id'));
        $this->assertSame('Bearer '.self::TOKEN, $history->request(2)->getHeaderLine('Authorization'));
    }

    public function test_mcp_json_rpc_responses_may_arrive_as_sse_events(): void
    {
        $initialize = ['jsonrpc' => '2.0', 'id' => 1, 'result' => [
            'protocolVersion' => '2025-11-25', 'capabilities' => (object) [],
            'serverInfo' => ['name' => 'Acceptora Verification', 'version' => '1.0.0'],
        ]];
        $tool = ['jsonrpc' => '2.0', 'id' => 2, 'result' => [
            'content' => [], 'isError' => false, 'structuredContent' => ['feature_id' => null],
        ]];
        $client = $this->client([
            new Response(200, ['Content-Type' => 'text/event-stream'], "event: message\ndata: ".json_encode($initialize, JSON_THROW_ON_ERROR)."\n\n"),
            new Response(202),
            new Response(200, ['Content-Type' => 'text/event-stream'], 'data: '.json_encode($tool, JSON_THROW_ON_ERROR)."\n\n"),
        ]);

        $result = $client->checkCompletionGate(['project_id' => 'proj_test']);

        $this->assertArrayHasKey('feature_id', $result->structuredContent);
    }

    public function test_failed_initialized_notification_resets_the_session_before_retry(): void
    {
        $history = new RequestHistory;
        $client = $this->client([
            $this->rpcResponse(1, [
                'protocolVersion' => '2025-11-25', 'capabilities' => (object) [],
                'serverInfo' => ['name' => 'Acceptora Verification', 'version' => '1.0.0'],
            ], ['MCP-Session-Id' => 'failed-session']),
            new ConnectException('notification failed', new Request('POST', 'https://www.acceptora.com/mcp')),
            $this->rpcResponse(2, [
                'protocolVersion' => '2025-11-25', 'capabilities' => (object) [],
                'serverInfo' => ['name' => 'Acceptora Verification', 'version' => '1.0.0'],
            ], ['MCP-Session-Id' => 'replacement-session']),
            new Response(202),
            $this->rpcResponse(3, [
                'content' => [], 'isError' => false, 'structuredContent' => ['status' => 'ready'],
            ]),
        ], $history);

        try {
            $client->getVerificationStatus([]);
            $this->fail('Expected the initialized notification to fail.');
        } catch (TransportException) {
            $this->addToAssertionCount(1);
        }

        $result = $client->getVerificationStatus([]);

        $this->assertSame('ready', $result->structuredContent['status']);
        $this->assertSame('initialize', json_decode((string) $history->request(2)->getBody(), true, 512, JSON_THROW_ON_ERROR)['method']);
        $this->assertFalse($history->request(2)->hasHeader('MCP-Session-Id'));
        $this->assertSame('replacement-session', $history->request(3)->getHeaderLine('MCP-Session-Id'));
    }

    public function test_public_endpoint_session_headers_are_never_reused_for_mcp(): void
    {
        $history = new RequestHistory;
        $client = $this->client([
            $this->jsonResponse(200, [
                'data' => [
                    'status' => 'ok', 'ready' => true, 'service' => 'acceptora',
                    'checked_at' => '2026-08-03T00:00:00+00:00', 'components' => [],
                ],
                'meta' => ['contract_version' => '1.0.0'],
            ], ['MCP-Session-Id' => 'untrusted-public-session']),
            $this->rpcResponse(1, [
                'protocolVersion' => '2025-11-25', 'capabilities' => (object) [],
                'serverInfo' => ['name' => 'Acceptora Verification', 'version' => '1.0.0'],
            ]),
            new Response(202),
            $this->rpcResponse(2, [
                'content' => [], 'isError' => false, 'structuredContent' => ['status' => 'ready'],
            ]),
        ], $history);

        $client->readiness();
        $client->getVerificationStatus([]);

        $this->assertFalse($history->request(1)->hasHeader('MCP-Session-Id'));
    }

    public function test_mcp_rejects_token_bearing_session_identifiers(): void
    {
        $history = new RequestHistory;
        $logger = new ArrayLogger;
        $client = $this->client([
            $this->rpcResponse(1, [
                'protocolVersion' => '2025-11-25', 'capabilities' => (object) [],
                'serverInfo' => ['name' => 'Acceptora Verification', 'version' => '1.0.0'],
            ], ['MCP-Session-Id' => self::TOKEN]),
        ], $history, $logger);

        try {
            $client->getVerificationStatus([], 'corr-session');
            $this->fail('Expected the token-bearing session identifier to be rejected.');
        } catch (InvalidResponseException $exception) {
            $this->assertSame('corr-session', $exception->correlationId);
        }

        $this->assertCount(1, $history);
        $this->assertSame('acceptora.verification.invalid_response', $logger->records[0]['context']['event']);
        $this->assertStringNotContainsString(self::TOKEN, json_encode($logger->records, JSON_THROW_ON_ERROR));
    }

    public function test_mcp_rejects_session_identifier_changes_after_initialization(): void
    {
        $history = new RequestHistory;
        $client = $this->client([
            $this->rpcResponse(1, [
                'protocolVersion' => '2025-11-25', 'capabilities' => (object) [],
                'serverInfo' => ['name' => 'Acceptora Verification', 'version' => '1.0.0'],
            ], ['MCP-Session-Id' => 'session-original']),
            new Response(202, ['MCP-Session-Id' => 'session-replaced']),
        ], $history);

        try {
            $client->getVerificationStatus([], 'corr-session-change');
            $this->fail('Expected the changed MCP session identifier to be rejected.');
        } catch (InvalidResponseException $exception) {
            $this->assertSame('corr-session-change', $exception->correlationId);
        }

        $this->assertCount(2, $history);
        $this->assertSame('session-original', $history->request(1)->getHeaderLine('MCP-Session-Id'));
    }

    public function test_mcp_rejects_session_identifier_introduction_after_initialization(): void
    {
        $history = new RequestHistory;
        $client = $this->client([
            $this->rpcResponse(1, [
                'protocolVersion' => '2025-11-25', 'capabilities' => (object) [],
                'serverInfo' => ['name' => 'Acceptora Verification', 'version' => '1.0.0'],
            ]),
            new Response(202, ['MCP-Session-Id' => 'session-introduced-late']),
        ], $history);

        try {
            $client->getVerificationStatus([], 'corr-session-late');
            $this->fail('Expected the late MCP session identifier to be rejected.');
        } catch (InvalidResponseException $exception) {
            $this->assertSame('corr-session-late', $exception->correlationId);
        }

        $this->assertCount(2, $history);
        $this->assertFalse($history->request(1)->hasHeader('MCP-Session-Id'));
    }

    public function test_mcp_tool_errors_use_the_stable_typed_exception(): void
    {
        $logger = new ArrayLogger;
        $client = $this->client([
            $this->rpcResponse(1, [
                'protocolVersion' => '2025-11-25', 'capabilities' => (object) [],
                'serverInfo' => ['name' => 'Acceptora Verification', 'version' => '1.0.0'],
            ]),
            new Response(202),
            $this->rpcResponse(2, [
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode(['error' => [
                        'code' => 'FEATURE_ARCHIVED', 'message' => 'Restore the feature.', 'http_status' => 409,
                        'retryable' => false, 'correlation_id' => 'corr-tool', 'details' => [],
                    ]], JSON_THROW_ON_ERROR),
                ]],
                'isError' => true,
            ]),
        ], logger: $logger);

        try {
            $client->getFeatureContext(['feature_id' => 'feat_test']);
            $this->fail('Expected an API exception.');
        } catch (ApiException $exception) {
            $this->assertSame('FEATURE_ARCHIVED', $exception->errorCode);
            $this->assertSame(409, $exception->statusCode);
            $this->assertSame('corr-tool', $exception->correlationId);
        }

        $failures = array_values(array_filter(
            $logger->records,
            static fn (array $record): bool => ($record['context']['event'] ?? null) === 'acceptora.verification.request_failed',
        ));

        $this->assertCount(1, $failures);
        $this->assertSame('MCP tools/call get_feature_context', $failures[0]['context']['operation']);
        $this->assertSame('FEATURE_ARCHIVED', $failures[0]['context']['error_code']);
        $this->assertSame('rejected', $failures[0]['context']['outcome']);
    }

    public function test_json_rpc_errors_emit_safe_failure_context(): void
    {
        $logger = new ArrayLogger;
        $client = $this->client([
            $this->rpcResponse(1, [
                'protocolVersion' => '2025-11-25', 'capabilities' => (object) [],
                'serverInfo' => ['name' => 'Acceptora Verification', 'version' => '1.0.0'],
            ]),
            new Response(202),
            $this->jsonResponse(200, [
                'jsonrpc' => '2.0',
                'id' => 2,
                'error' => ['code' => -32603, 'message' => 'Failed '.self::TOKEN, 'data' => ['token' => self::TOKEN]],
            ]),
        ], logger: $logger);

        try {
            $client->getFeatureContext(['feature_id' => 'feat_test'], 'corr-rpc-error');
            $this->fail('Expected a JSON-RPC error.');
        } catch (ContractException $exception) {
            $this->assertSame('-32603', $exception->errorCode);
            $this->assertStringNotContainsString(self::TOKEN, $exception->getMessage());
        }

        $failures = array_values(array_filter(
            $logger->records,
            static fn (array $record): bool => ($record['context']['event'] ?? null) === 'acceptora.verification.request_failed',
        ));

        $this->assertCount(1, $failures);
        $this->assertSame('MCP tools/call', $failures[0]['context']['operation']);
        $this->assertSame('-32603', $failures[0]['context']['error_code']);
        $this->assertSame(200, $failures[0]['context']['http_status']);
        $this->assertStringNotContainsString(self::TOKEN, json_encode($logger->records, JSON_THROW_ON_ERROR));
    }

    public function test_decoded_error_exceptions_do_not_retain_remote_tokens_in_native_traces(): void
    {
        $previousIgnoreArguments = ini_set('zend.exception_ignore_args', '0');

        $this->assertNotFalse($previousIgnoreArguments);
        $this->assertNotSame('1', ini_get('zend.exception_ignore_args'));

        try {
            $httpClient = $this->client([
                $this->jsonResponse(429, ['error' => [
                    'code' => 'RATE_LIMITED',
                    'message' => 'Rejected '.self::TOKEN,
                    'http_status' => 429,
                    'details' => [self::TOKEN => self::TOKEN],
                ]]),
            ]);
            $toolClient = $this->client([
                $this->rpcResponse(1, [
                    'protocolVersion' => '2025-11-25', 'capabilities' => (object) [],
                    'serverInfo' => ['name' => 'Acceptora Verification', 'version' => '1.0.0'],
                ]),
                new Response(202),
                $this->rpcResponse(2, [
                    'content' => [[
                        'type' => 'text',
                        'text' => json_encode(['error' => [
                            'code' => 'FEATURE_ARCHIVED',
                            'message' => 'Rejected '.self::TOKEN,
                            'http_status' => 409,
                            'details' => [self::TOKEN => self::TOKEN],
                        ]], JSON_THROW_ON_ERROR),
                    ]],
                    'isError' => true,
                ]),
            ]);
            $rpcClient = $this->client([
                $this->rpcResponse(1, [
                    'protocolVersion' => '2025-11-25', 'capabilities' => (object) [],
                    'serverInfo' => ['name' => 'Acceptora Verification', 'version' => '1.0.0'],
                ]),
                new Response(202),
                $this->jsonResponse(200, [
                    'jsonrpc' => '2.0',
                    'id' => 2,
                    'error' => [
                        'code' => -32603,
                        'message' => 'Rejected '.self::TOKEN,
                        'data' => [self::TOKEN => self::TOKEN],
                    ],
                ]),
            ]);

            foreach ([
                static fn () => $httpClient->completionGate(['project_id' => 'proj_test']),
                static fn () => $toolClient->getFeatureContext(['feature_id' => 'feat_test']),
                static fn () => $rpcClient->getFeatureContext(['feature_id' => 'feat_test']),
            ] as $operation) {
                try {
                    $operation();
                    $this->fail('Expected a decoded remote error.');
                } catch (VerificationException $exception) {
                    $this->assertStringNotContainsString(self::TOKEN, serialize($exception));
                    $this->assertStringNotContainsString(self::TOKEN, var_export($exception->getTrace(), true));
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previousIgnoreArguments);
        }
    }

    public function test_configuration_rejections_are_natively_serializable_without_secret_arguments(): void
    {
        $previousIgnoreArguments = ini_set('zend.exception_ignore_args', '0');

        $this->assertNotFalse($previousIgnoreArguments);
        $this->assertNotSame('1', ini_get('zend.exception_ignore_args'));

        try {
            try {
                new Client(
                    token: self::TOKEN,
                    baseUrl: 'https://'.self::TOKEN.'.example.com',
                    userAgent: 'acceptora '.self::TOKEN,
                );
                $this->fail('Expected invalid client configuration.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringNotContainsString(self::TOKEN, serialize($exception));
                $this->assertStringNotContainsString(self::TOKEN, var_export($exception->getTrace(), true));
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previousIgnoreArguments);
        }
    }

    public function test_response_validation_failures_do_not_retain_remote_tokens_in_native_traces(): void
    {
        $previousIgnoreArguments = ini_set('zend.exception_ignore_args', '0');
        $stream = FnStream::decorate(Utils::streamFor(''), [
            'eof' => static fn (): bool => false,
            'read' => static function (int $length): string {
                throw new \RuntimeException('Stream failed with '.self::TOKEN.' at '.$length);
            },
        ]);
        $semanticPayload = [
            'data' => [
                'contract_version' => null,
                'integration_version' => '1.0.0',
                'skill_version' => '1.0.0',
                'schema_registry' => 'contracts/v1/mcp-tools.json',
                self::TOKEN => self::TOKEN,
            ],
            'meta' => ['contract_version' => '1.0.0'],
        ];
        $cases = [
            [$this->client([new Response(200, ['Content-Type' => 'application/json'], '{"echo":"'.self::TOKEN.'"')]), InvalidResponseException::class],
            [$this->client([new Response(200, ['Content-Type' => 'text/event-stream'], 'data: {"echo":"'.self::TOKEN.'"')]), InvalidResponseException::class],
            [$this->client([new Response(200, ['Content-Length' => '4096'], self::TOKEN)], maxResponseBytes: 1_024), InvalidResponseException::class],
            [$this->client([new Response(200, [], self::TOKEN.str_repeat('x', 1_024))], maxResponseBytes: 1_024), InvalidResponseException::class],
            [$this->client([new Response(200, [], $stream)]), TransportException::class],
            [$this->client([$this->jsonResponse(200, $semanticPayload)]), InvalidResponseException::class],
        ];

        $this->assertNotFalse($previousIgnoreArguments);
        $this->assertNotSame('1', ini_get('zend.exception_ignore_args'));

        try {
            foreach ($cases as [$client, $expectedException]) {
                try {
                    $client->contractInfo();
                    $this->fail('Expected response validation to fail.');
                } catch (VerificationException $exception) {
                    $this->assertInstanceOf($expectedException, $exception);
                    $this->assertStringNotContainsString(self::TOKEN, serialize($exception));
                    $this->assertStringNotContainsString(self::TOKEN, var_export($exception->getTrace(), true));
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previousIgnoreArguments);
        }
    }

    public function test_external_inputs_do_not_retain_tokens_in_native_traces_or_serialization(): void
    {
        $previousIgnoreArguments = ini_set('zend.exception_ignore_args', '0');
        $localLimitClient = $this->client([], maxRequestBytes: 1_024);
        $remoteFailureClient = $this->client([
            $this->jsonResponse(400, ['error' => [
                'code' => 'INVALID_REQUEST',
                'message' => 'Invalid request.',
            ]]),
        ]);
        $identifierClient = $this->client([]);

        $this->assertNotFalse($previousIgnoreArguments);
        $this->assertNotSame('1', ini_get('zend.exception_ignore_args'));

        try {
            foreach ([
                static fn () => $localLimitClient->completionGate([
                    'secret' => self::TOKEN,
                    'payload' => str_repeat('x', 1_024),
                ]),
                static fn () => $remoteFailureClient->completionGate(['secret' => self::TOKEN]),
                static fn () => $identifierClient->readiness(self::TOKEN),
                static fn () => $identifierClient->callTool(self::TOKEN, []),
            ] as $operation) {
                try {
                    $operation();
                    $this->fail('Expected external input rejection.');
                } catch (\Throwable $exception) {
                    $this->assertStringNotContainsString(self::TOKEN, serialize($exception));
                    $this->assertStringNotContainsString(self::TOKEN, var_export($exception->getTrace(), true));
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previousIgnoreArguments);
        }
    }

    public function test_plain_http_loopback_requests_ignore_ambient_proxies(): void
    {
        [$process, $pipes, $capturePath, $proxyUrl] = $this->startProxyCapture();
        $targetOrigin = $this->unusedLoopbackOrigin();
        $environment = ['HTTP_PROXY', 'http_proxy', 'HTTPS_PROXY', 'https_proxy', 'NO_PROXY', 'no_proxy'];
        $previousEnvironment = [];
        $caught = null;

        foreach ($environment as $name) {
            $previousEnvironment[$name] = getenv($name);
            putenv($name.(str_contains($name, 'NO_PROXY') || $name === 'no_proxy' ? '=' : '='.$proxyUrl));
        }

        try {
            $client = new Client(
                token: self::TOKEN,
                baseUrl: $targetOrigin,
                timeout: 0.5,
                connectTimeout: 0.25,
            );
            $client->completionGate(['project_id' => 'proj_proxy_test']);
        } catch (\Throwable $exception) {
            $caught = $exception;
        } finally {
            foreach ($previousEnvironment as $name => $value) {
                $value === false ? putenv($name) : putenv($name.'='.$value);
            }

            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }

            proc_close($process);
            $capture = is_file($capturePath) ? (string) file_get_contents($capturePath) : '';

            if (is_file($capturePath)) {
                unlink($capturePath);
            }
        }

        $this->assertInstanceOf(TransportException::class, $caught);
        $this->assertStringContainsString('READY', $capture);
        $this->assertStringNotContainsString('REQUEST', $capture);
        $this->assertStringNotContainsString('Authorization', $capture);
        $this->assertStringNotContainsString(self::TOKEN, $capture);
    }

    public function test_remote_errors_redact_high_confidence_github_tokens_and_private_keys(): void
    {
        $githubToken = 'ghp_'.str_repeat('B', 36);
        $privateKeyMarker = '-----'.'BEGIN PRIVATE KEY-----';
        $privateKey = $privateKeyMarker."\nQUJDREVGRw==\n-----END PRIVATE KEY-----";
        $client = $this->client([
            $this->jsonResponse(400, ['error' => [
                'code' => 'INVALID_REQUEST',
                'message' => 'Rejected '.$githubToken,
                'details' => [
                    $githubToken => $githubToken,
                    'github_echo' => $githubToken,
                    'embedded' => 'prefix'.$githubToken.'suffix',
                    'material' => $privateKey,
                ],
            ]]),
        ]);

        try {
            $client->completionGate(['project_id' => 'proj_redaction_test']);
            $this->fail('Expected a remote error.');
        } catch (ApiException $exception) {
            $serialized = serialize($exception);

            $this->assertSame('Rejected [redacted]', $exception->getMessage());
            $this->assertSame('[redacted]', $exception->details['github_echo']);
            $this->assertSame('prefix[redacted]suffix', $exception->details['embedded']);
            $this->assertSame('[redacted]', $exception->details['material']);
            $this->assertSame('[redacted]', $exception->details['[redacted]']);
            $this->assertStringNotContainsString($githubToken, $serialized);
            $this->assertStringNotContainsString($privateKeyMarker, $serialized);
        }
    }

    public function test_mcp_tool_success_requires_object_valued_structured_content(): void
    {
        $client = $this->client([
            $this->rpcResponse(1, [
                'protocolVersion' => '2025-11-25', 'capabilities' => (object) [],
                'serverInfo' => ['name' => 'Acceptora Verification', 'version' => '1.0.0'],
            ]),
            new Response(202),
            $this->rpcResponse(2, [
                'content' => [], 'isError' => false, 'structuredContent' => ['invalid-list'],
            ]),
        ]);

        $this->expectException(InvalidResponseException::class);

        $client->getVerificationStatus([]);
    }

    public function test_compatibility_preflight_checks_versions_tool_names_and_schema_digests(): void
    {
        $inputSchema = ['type' => 'object', 'properties' => ['feature_id' => ['type' => 'string']]];
        $outputSchema = ['type' => 'object', 'properties' => ['status' => ['type' => 'string']]];
        $manifest = $this->singleToolManifest($inputSchema, $outputSchema);
        $client = $this->client([
            $this->contractResponse(),
            $this->rpcResponse(1, [
                'protocolVersion' => '2025-11-25', 'capabilities' => (object) [],
                'serverInfo' => ['name' => 'Acceptora Verification', 'version' => '1.0.0'],
            ]),
            new Response(202),
            $this->rpcResponse(2, ['tools' => [[
                'name' => 'get_verification_status', 'inputSchema' => $inputSchema, 'outputSchema' => $outputSchema,
                'annotations' => ['readOnlyHint' => true],
            ]]]),
        ], manifest: $manifest);

        $result = $client->verifyCompatibility('corr-compatible');

        $this->assertSame(['get_verification_status'], $result->tools);
        $this->assertSame('2025-11-25', $result->protocolVersion);
    }

    public function test_compatibility_preflight_rejects_read_only_annotation_drift(): void
    {
        $inputSchema = ['type' => 'object'];
        $outputSchema = ['type' => 'object'];
        $client = $this->client([
            $this->contractResponse(),
            $this->rpcResponse(1, [
                'protocolVersion' => '2025-11-25', 'capabilities' => (object) [],
                'serverInfo' => ['name' => 'Acceptora Verification', 'version' => '1.0.0'],
            ]),
            new Response(202),
            $this->rpcResponse(2, ['tools' => [[
                'name' => 'get_verification_status', 'inputSchema' => $inputSchema, 'outputSchema' => $outputSchema,
                'annotations' => ['readOnlyHint' => false],
            ]]]),
        ], manifest: $this->singleToolManifest($inputSchema, $outputSchema));

        try {
            $client->verifyCompatibility();
            $this->fail('Expected tool metadata drift to fail compatibility.');
        } catch (ContractException $exception) {
            $this->assertSame('MCP_METADATA_DRIFT', $exception->errorCode);
        }
    }

    public function test_invalid_oversized_redirected_and_transport_responses_fail_closed(): void
    {
        $cases = [
            [$this->client([new Response(200, ['Content-Type' => 'application/json'], '{')]), InvalidResponseException::class],
            [$this->client([new Response(200, ['Content-Type' => 'application/json'], str_repeat('x', 1025))], maxResponseBytes: 1024), InvalidResponseException::class],
            [$this->client([$this->jsonResponse(302, ['error' => ['code' => 'REDIRECT_REJECTED']], ['Location' => 'https://example.test'])]), ApiException::class],
            [$this->client([new ConnectException('failed '.self::TOKEN, new Request('GET', 'https://www.acceptora.com'))]), TransportException::class],
        ];

        foreach ($cases as [$client, $exception]) {
            try {
                $client->contractInfo();
                $this->fail('Expected the response to fail closed.');
            } catch (\Throwable $throwable) {
                $this->assertInstanceOf($exception, $throwable);
                $this->assertStringNotContainsString(self::TOKEN, $throwable->getMessage());
            }
        }
    }

    public function test_response_stream_failures_are_retryable_transport_errors_with_safe_telemetry(): void
    {
        $logger = new ArrayLogger;
        $stream = FnStream::decorate(Utils::streamFor('response'), [
            'read' => static function (int $length): string {
                throw new \RuntimeException("stream failed {$length}");
            },
        ]);
        $client = $this->client([
            new Response(200, ['Content-Type' => 'application/json'], $stream),
        ], logger: $logger);

        try {
            $client->contractInfo('corr-stream');
            $this->fail('Expected the response stream to fail.');
        } catch (TransportException $exception) {
            $this->assertTrue($exception->retryable);
            $this->assertSame('TRANSPORT_ERROR', $exception->errorCode);
            $this->assertSame('corr-stream', $exception->correlationId);
        }

        $this->assertSame('acceptora.verification.transport_failed', $logger->records[0]['context']['event']);
        $this->assertSame('/api/contract-version', $logger->records[0]['context']['operation']);
        $this->assertSame('transport_error', $logger->records[0]['context']['outcome']);
    }

    public function test_invalid_responses_emit_failure_context_without_a_success_event(): void
    {
        $logger = new ArrayLogger;
        $client = $this->client([
            new Response(200, [
                'Content-Type' => 'application/json',
                'X-Correlation-ID' => 'corr-invalid-response',
            ], '{'),
        ], logger: $logger);

        try {
            $client->contractInfo();
            $this->fail('Expected invalid JSON to fail.');
        } catch (InvalidResponseException) {
            $this->addToAssertionCount(1);
        }

        $events = array_column(array_column($logger->records, 'context'), 'event');

        $this->assertContains('acceptora.verification.invalid_response', $events);
        $this->assertNotContains('acceptora.verification.request_completed', $events);

        foreach ($logger->records as $record) {
            $this->assertSame('acceptora', $record['context']['external_service']);
            $this->assertSame(1, $record['context']['attempt']);
            $this->assertArrayHasKey('outcome', $record['context']);
            $this->assertSame('corr-invalid-response', $record['context']['correlation_id']);
        }
    }

    public function test_semantically_invalid_responses_are_logged_as_invalid_after_transport_receipt(): void
    {
        $logger = new ArrayLogger;
        $client = $this->client([
            $this->jsonResponse(200, ['data' => ['contract_version' => '1.0.0']], [
                'X-Correlation-ID' => 'corr-invalid-contract',
            ]),
        ], logger: $logger);

        try {
            $client->contractInfo();
            $this->fail('Expected an incomplete contract response to fail.');
        } catch (InvalidResponseException $exception) {
            $this->assertSame('corr-invalid-contract', $exception->correlationId);
        }

        $outcomes = array_column(array_column($logger->records, 'context'), 'outcome');

        $this->assertContains('response_received', $outcomes);
        $this->assertContains('invalid_response', $outcomes);
        $this->assertNotContains('success', $outcomes);
    }

    public function test_configuration_rejects_unsafe_values_before_network_access(): void
    {
        foreach ([
            fn (): Client => new Client(''),
            fn (): Client => new Client('avt_invalid'),
            fn (): Client => new Client(self::TOKEN, 'http://example.com'),
            fn (): Client => new Client(self::TOKEN, 'https://user@example.com'),
            fn (): Client => new Client(self::TOKEN, 'https://example.com?token=value'),
            fn (): Client => new Client(self::TOKEN, 'https://example.com/api'),
            fn (): Client => new Client(self::TOKEN, "https://example.com\n"),
            fn (): Client => new Client(self::TOKEN, "https://example.com/\x07"),
            fn (): Client => new Client(self::TOKEN, 'https://example.com:'),
            fn (): Client => new Client(self::TOKEN, 'https://example.com:bad'),
            fn (): Client => new Client(self::TOKEN, 'https://example.com/unsafe\\path'),
            fn (): Client => new Client(self::TOKEN, 'https://example.com/'.self::TOKEN),
            fn (): Client => new Client(self::TOKEN, userAgent: "acceptora\r\ninjected"),
            fn (): Client => new Client(self::TOKEN, userAgent: "acceptora\x07invalid"),
            fn (): Client => new Client(self::TOKEN, userAgent: "acceptora\x7Finvalid"),
            fn (): Client => new Client(self::TOKEN, userAgent: str_repeat('a', 256)),
            fn (): Client => new Client(self::TOKEN, userAgent: ' acceptora'),
            fn (): Client => new Client(self::TOKEN, userAgent: 'acceptora '.self::TOKEN),
            fn (): Client => new Client(self::TOKEN, timeout: 0),
            fn (): Client => new Client(self::TOKEN, timeout: NAN),
            fn (): Client => new Client(self::TOKEN, timeout: INF),
            fn (): Client => new Client(self::TOKEN, connectTimeout: INF),
        ] as $factory) {
            try {
                $factory();
                $this->fail('Expected invalid package configuration.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertInstanceOf(Client::class, new Client(null, 'http://[::1]'));
        $this->assertInstanceOf(Client::class, new Client(null, 'https://www.acceptora.com/'));
        $this->assertInstanceOf(Client::class, new Client(null, 'HTTPS://www.acceptora.com'));
        $this->assertInstanceOf(Client::class, new Client(null, userAgent: "acceptora\tclient"));

        try {
            $this->client([])->contractInfo('request-'.self::TOKEN);
            $this->fail('Expected a token-bearing correlation ID to be rejected.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_authenticated_operations_require_a_token_before_network_access(): void
    {
        $history = new RequestHistory;
        $client = $this->client([], $history, token: null);

        try {
            $client->completionGate(['project_id' => 'proj_test']);
            $this->fail('Expected authenticated operations to require a token.');
        } catch (AuthenticationException $exception) {
            $this->assertSame('AUTH_REQUIRED', $exception->errorCode);
            $this->assertSame(401, $exception->statusCode);
        }

        $this->assertCount(0, $history);
    }

    public function test_project_metadata_uses_the_scoped_rest_endpoint_and_returns_a_typed_result(): void
    {
        $history = new RequestHistory;
        $client = $this->client([
            $this->jsonResponse(200, [
                'project_id' => 'proj_01ARZ3NDEKTSV4RRFFQ69G5FAV',
                'name' => 'Example project',
                'source_kind' => null,
                'platform' => null,
                'default_environment' => null,
                'verification_policy' => ['verification_enabled' => true],
                'connection_status' => null,
                'granted_scopes' => ['projects:read', 'features:read'],
                'credential_expires_at' => null,
                'versions' => ['contract_version' => '1.0.0'],
                'endpoints' => ['mcp' => 'https://www.acceptora.com/mcp'],
                'supported_clients' => ['codex', 'claude', 'gemini'],
                'correlation_id' => self::TOKEN,
            ], ['X-Correlation-ID' => 'corr-header']),
        ], $history);

        $result = $client->projectMetadata('request-project');

        $this->assertSame('proj_01ARZ3NDEKTSV4RRFFQ69G5FAV', $result->projectId);
        $this->assertNull($result->sourceKind);
        $this->assertNull($result->platform);
        $this->assertNull($result->defaultEnvironment);
        $this->assertNull($result->connectionStatus);
        $this->assertSame('corr-header', $result->correlationId);
        $this->assertSame('/api/v1/integrations/project', $history->request(0)->getUri()->getPath());
        $this->assertSame('GET', $history->request(0)->getMethod());
        $this->assertSame('Bearer '.self::TOKEN, $history->request(0)->getHeaderLine('Authorization'));
    }

    public function test_rest_operations_use_the_canonical_routes_and_add_versions_without_mcp_initialization(): void
    {
        $history = new RequestHistory;
        $responses = [];

        foreach (array_keys(Client::REST_OPERATION_PATHS) as $operation) {
            $responses[] = $this->jsonResponse(200, [
                'operation' => $operation,
                'correlation_id' => 'corr-'.$operation,
            ]);
        }

        $client = $this->client($responses, $history);
        $index = 0;

        foreach (Client::REST_OPERATION_PATHS as $operation => $path) {
            $result = $client->callRestOperation($operation, ['feature_id' => 'feat_01ARZ3NDEKTSV4RRFFQ69G5FAV']);
            $request = $history->request($index++);
            $payload = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame($operation, $result->operation);
            $this->assertSame($path, $request->getUri()->getPath());
            $this->assertSame('POST', $request->getMethod());
            $this->assertSame('1.0.0', $payload['versions']['contract_version']);
            $this->assertSame('acceptora-verification-php', $payload['versions']['integration_name']);
        }

        $this->assertCount(8, $history);
    }

    public function test_named_rest_method_and_rest_errors_preserve_the_http_contract(): void
    {
        $history = new RequestHistory;
        $client = $this->client([
            $this->jsonResponse(200, ['feature_id' => null, 'correlation_id' => 'corr-status']),
            $this->jsonResponse(403, [
                'error' => [
                    'code' => 'SCOPE_DENIED',
                    'message' => 'The credential lacks the required scope.',
                    'http_status' => 403,
                    'retryable' => false,
                    'correlation_id' => 'corr-denied',
                    'details' => ['required_scope' => 'feedback:address'],
                ],
            ]),
        ], $history);

        $result = $client->getVerificationStatusRest(['feature_id' => 'feat_01ARZ3NDEKTSV4RRFFQ69G5FAV']);

        $this->assertSame('get_verification_status', $result->operation);
        $this->assertSame('/api/v1/integrations/status', $history->request(0)->getUri()->getPath());

        try {
            $client->addressFeedbackRest(['feature_id' => 'feat_01ARZ3NDEKTSV4RRFFQ69G5FAV']);
            $this->fail('Expected the REST authorization error.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('SCOPE_DENIED', $exception->errorCode);
            $this->assertSame('corr-denied', $exception->correlationId);
            $this->assertSame('feedback:address', $exception->details['required_scope']);
        }
    }

    public function test_unknown_rest_operation_fails_before_network_access(): void
    {
        $history = new RequestHistory;
        $client = $this->client([], $history);

        $this->expectException(InvalidArgumentException::class);

        try {
            $client->callRestOperation('accept_human_decision', ['feature_id' => 'feat_01ARZ3NDEKTSV4RRFFQ69G5FAV']);
        } finally {
            $this->assertCount(0, $history);
        }
    }

    public function test_oversized_request_bodies_are_rejected_before_network_access(): void
    {
        $client = $this->client([], maxRequestBytes: 1024);

        $this->expectException(InvalidArgumentException::class);

        $client->completionGate(['payload' => str_repeat('x', 1024)]);
    }

    public function test_non_json_request_values_are_rejected_before_network_access(): void
    {
        $history = new RequestHistory;
        $logger = new ArrayLogger;
        $client = $this->client([], $history, $logger);

        try {
            $client->completionGate(['payload' => NAN]);
            $this->fail('Expected the request payload to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Acceptora request payloads must contain valid JSON values.', $exception->getMessage());
        }

        $this->assertCount(0, $history);
        $this->assertSame([], $logger->records);
    }

    public function test_openapi_discovery_requires_an_openapi_31_document(): void
    {
        foreach ([
            [
                'openapi' => '3.0.4',
                'info' => ['title' => 'Wrong version'],
                'paths' => ['/api/v1/integrations/project' => ['get' => []]],
                'components' => ['schemas' => ['Project' => ['type' => 'object']]],
            ],
            [
                'openapi' => '3.1.0',
                'info' => ['title' => 'Empty contract'],
                'paths' => [],
                'components' => ['schemas' => []],
            ],
        ] as $document) {
            $logger = new ArrayLogger;
            $client = $this->client([
                $this->jsonResponse(200, $document, ['X-Correlation-ID' => 'corr-openapi-response']),
            ], logger: $logger, token: null);

            try {
                $client->openApiDocument('corr-openapi-invalid');
                $this->fail('Expected the OpenAPI document to be rejected.');
            } catch (InvalidResponseException $exception) {
                $this->assertSame('corr-openapi-response', $exception->correlationId);
            }

            $this->assertSame('acceptora.verification.invalid_response', $logger->records[1]['context']['event']);
            $this->assertSame('corr-openapi-response', $logger->records[1]['context']['correlation_id']);
            $this->assertSame('invalid_response', $logger->records[1]['context']['outcome']);
        }
    }

    public function test_mcp_tool_pagination_rejects_aggregate_payloads_above_the_response_limit(): void
    {
        $history = new RequestHistory;
        $client = $this->client([
            $this->contractResponse(),
            $this->rpcResponse(1, [
                'protocolVersion' => '2025-11-25', 'capabilities' => (object) [],
                'serverInfo' => ['name' => 'Acceptora Verification', 'version' => '1.0.0'],
            ]),
            new Response(202),
            $this->rpcResponse(2, [
                'tools' => [['name' => 'first', 'description' => str_repeat('a', 560)]],
                'nextCursor' => 'page-two',
            ]),
            $this->rpcResponse(3, [
                'tools' => [['name' => 'second', 'description' => str_repeat('b', 560)]],
            ]),
        ], $history, manifest: $this->singleToolManifest(['type' => 'object'], ['type' => 'object']), maxResponseBytes: 1024);

        try {
            $client->verifyCompatibility('corr-tool-aggregate');
            $this->fail('Expected the aggregate tools/list payload to be rejected.');
        } catch (InvalidResponseException $exception) {
            $this->assertSame('corr-tool-aggregate', $exception->correlationId);
        }

        $this->assertCount(5, $history);
    }

    public function test_mcp_tool_pagination_rejects_too_many_tool_definitions(): void
    {
        $history = new RequestHistory;
        $client = $this->client([
            $this->contractResponse(),
            $this->rpcResponse(1, [
                'protocolVersion' => '2025-11-25', 'capabilities' => (object) [],
                'serverInfo' => ['name' => 'Acceptora Verification', 'version' => '1.0.0'],
            ]),
            new Response(202),
            $this->rpcResponse(2, [
                'tools' => array_map(
                    static fn (int $index): array => ['name' => 'tool-'.$index],
                    range(1, 257),
                ),
            ]),
        ], $history, manifest: $this->singleToolManifest(['type' => 'object'], ['type' => 'object']));

        try {
            $client->verifyCompatibility('corr-tool-count');
            $this->fail('Expected the aggregate tools/list count to be rejected.');
        } catch (InvalidResponseException $exception) {
            $this->assertSame('corr-tool-count', $exception->correlationId);
        }

        $this->assertCount(4, $history);
    }

    /** @return array{resource, array<int, resource>, string, string} */
    private function startProxyCapture(): array
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        $this->assertIsResource($listener, $errorMessage ?? 'Unable to open the proxy-capture listener.');
        $address = stream_socket_get_name($listener, false);
        $this->assertIsString($address);
        fclose($listener);
        $port = (int) substr($address, (int) strrpos($address, ':') + 1);
        $capturePath = tempnam(sys_get_temp_dir(), 'acceptora-proxy-');
        $this->assertIsString($capturePath);
        $server = <<<'PHP'
$capturePath = $argv[1];
$port = (int) $argv[2];
$listener = @stream_socket_server('tcp://127.0.0.1:'.$port, $errorCode, $errorMessage);

if (! is_resource($listener)) {
    file_put_contents($capturePath, 'BIND_FAILED '.$errorCode.' '.$errorMessage);
    exit(2);
}

file_put_contents($capturePath, "READY\n");
$connection = @stream_socket_accept($listener, 1);

if (is_resource($connection)) {
    stream_set_timeout($connection, 1);
    $request = '';

    while (! feof($connection) && ! str_contains($request, "\r\n\r\n")) {
        $chunk = fread($connection, 8_192);

        if (! is_string($chunk) || $chunk === '') {
            break;
        }

        $request .= $chunk;
    }

    file_put_contents($capturePath, "REQUEST\n".$request, FILE_APPEND);
    fwrite($connection, "HTTP/1.1 502 Bad Gateway\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
    fclose($connection);
}

fclose($listener);
PHP;
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, '-r', $server, '--', $capturePath, (string) $port],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);

        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
            unset($pipes[0]);
        }

        $ready = false;

        for ($attempt = 0; $attempt < 100; $attempt++) {
            clearstatcache(true, $capturePath);
            $contents = is_file($capturePath) ? (string) file_get_contents($capturePath) : '';

            if (str_contains($contents, 'READY')) {
                $ready = true;
                break;
            }

            usleep(10_000);
        }

        if (! $ready) {
            proc_terminate($process);

            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }

            proc_close($process);
            $failure = is_file($capturePath) ? (string) file_get_contents($capturePath) : 'Proxy capture did not start.';

            if (is_file($capturePath)) {
                unlink($capturePath);
            }

            $this->fail($failure);
        }

        return [$process, $pipes, $capturePath, 'http://127.0.0.1:'.$port];
    }

    private function unusedLoopbackOrigin(): string
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        $this->assertIsResource($listener, $errorMessage ?? 'Unable to allocate an unused loopback origin.');
        $address = stream_socket_get_name($listener, false);
        $this->assertIsString($address);
        fclose($listener);

        return 'http://'.$address;
    }

    /**
     * @param  list<Response|\Throwable>  $queue
     */
    private function client(
        array $queue,
        ?RequestHistory $history = null,
        ?ArrayLogger $logger = null,
        ?ContractManifest $manifest = null,
        int $maxRequestBytes = 2_000_000,
        int $maxResponseBytes = 4_000_000,
        ?string $token = self::TOKEN,
    ): Client {
        $mock = new MockHandler($queue);
        $stack = HandlerStack::create($mock);
        $history ??= new RequestHistory;
        $transactions = &$history->container();
        $stack->push(Middleware::history($transactions));

        return new Client(
            token: $token,
            http: new GuzzleClient(['handler' => $stack]),
            logger: $logger,
            manifest: $manifest,
            maxRequestBytes: $maxRequestBytes,
            maxResponseBytes: $maxResponseBytes,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    private function jsonResponse(int $status, array $payload, array $headers = []): Response
    {
        return new Response($status, ['Content-Type' => 'application/json', ...$headers], json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, string>  $headers
     */
    private function rpcResponse(int $id, array $result, array $headers = []): Response
    {
        return $this->jsonResponse(200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result], $headers);
    }

    private function contractResponse(): Response
    {
        return $this->jsonResponse(200, [
            'data' => [
                'contract_version' => '1.0.0', 'integration_version' => '1.0.0',
                'skill_version' => '1.0.0', 'schema_registry' => 'contracts/v1/mcp-tools.json',
            ],
            'meta' => ['contract_version' => '1.0.0'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $inputSchema
     * @param  array<string, mixed>  $outputSchema
     */
    private function singleToolManifest(array $inputSchema, array $outputSchema): ContractManifest
    {
        return new ContractManifest([
            'schema_version' => 1,
            'package' => ['name' => 'acceptora/verification-php', 'version' => '1.0.0'],
            'integration' => ['version' => '1.0.0'], 'skill' => ['version' => '1.0.0'],
            'contract' => ['version' => '1.0.0', 'mcp_protocol_version' => '2025-11-25'],
            'server' => ['name' => 'Acceptora Verification', 'version' => '1.0.0'],
            'http_endpoints' => [],
            'error_codes' => [],
            'tools' => [[
                'name' => 'get_verification_status',
                'required_scope' => 'features:read',
                'read_only' => true,
                'input_schema_sha256' => $this->digest($inputSchema),
                'output_schema_sha256' => $this->digest($outputSchema),
            ]],
        ]);
    }

    /** @param array<string, mixed> $schema */
    private function digest(array $schema): string
    {
        $canonicalize = function (mixed $value) use (&$canonicalize): mixed {
            if (! is_array($value)) {
                return $value;
            }

            foreach ($value as $key => $child) {
                $value[$key] = $canonicalize($child);
            }

            if (! array_is_list($value)) {
                ksort($value);
            }

            return $value;
        };

        return 'sha256:'.hash('sha256', json_encode($canonicalize($schema), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
