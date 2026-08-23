# Acceptora Verification PHP

Framework-independent PHP client for the versioned Acceptora verification API and MCP server.

The package provides typed access to public readiness and contract information, credential-bound project metadata, REST equivalents for all eight verification operations, the backward-compatible completion-gate hook, and the complete Acceptora MCP v1 tool set. It includes compatibility preflight checks for server versions, MCP protocol metadata, tool names, and canonical input/output schema digests.

## Requirements

- PHP 8.2 or newer
- JSON and Mbstring PHP extensions
- Guzzle 7.9 or 8.x
- An Acceptora project-scoped bearer token for authenticated operations

## Installation

```bash
composer require acceptora/verification-php:^1.0
```

## Configure Acceptora

Authenticated requests require a project-scoped token created by a project
owner in the Acceptora application:

1. [Register](https://www.acceptora.com/register) or sign in, then create a
   project from **Projects**.
2. Open the project's **Settings > Credentials** tab. Create a short-lived
   credential and grant only the operations the integration needs. The normal
   verification workflow uses `projects:read`, `features:resolve`,
   `features:read`, `checklists:write`, `feedback:read`, `feedback:address`, and
   `gates:read`. Grant `exceptions:write` only when the project policy allows
   the integration to record source-bound verification exceptions.
3. Copy the token when it is shown. It cannot be retrieved after the page is
   left or reloaded. Store it in a secret manager or environment variable.
4. Open **Settings > Connection** to copy the project ID and canonical origin.
   The connection preflight checks project configuration and contract
   readiness without receiving the token secret.

Tokens are independently expirable and revocable. Revoking a credential stops
future requests that use it.

## Create a client

Keep the bearer token in an environment variable or secret manager. Do not place it in source code, configuration committed to version control, URLs, or logs.

```php
<?php

use Acceptora\Verification\Client;

$token = getenv('ACCEPTORA_AGENT_TOKEN');

if (! is_string($token) || $token === '') {
    throw new RuntimeException('ACCEPTORA_AGENT_TOKEN is required.');
}

$client = new Client(
    token: $token,
    baseUrl: 'https://www.acceptora.com',
    timeout: 15.0,
    connectTimeout: 5.0,
    maxRequestBytes: 2_000_000,
    maxResponseBytes: 4_000_000,
);
```

The base URL must be an absolute HTTP or HTTPS origin without embedded credentials, a path, a query, or a fragment. Plain HTTP is accepted only for `localhost`, `127.0.0.1`, or `::1`. TLS certificate verification is always enabled for HTTPS requests.

You may inject any Guzzle `ClientInterface` and any PSR-3 `LoggerInterface`. The default logger is `Psr\Log\NullLogger`.

## Supported surfaces and availability

| Method | Path | Client method | Authentication | Availability |
| --- | --- | --- | --- | --- |
| `GET` | `/api/health` | `readiness()` | None | Current public discovery |
| `GET` | `/api/contract-version` | `contractInfo()` | None | Current public discovery |
| `POST` | `/api/integrations/completion-gate` | `completionGate()` (legacy hook) | Bearer token with `gates:read` | Current backward-compatible hook |
| `POST` | `/mcp` | MCP methods below | Bearer token with the required tool scope | Current MCP surface |
| `GET` | `/api/v1/integrations/openapi.json` | `openApiDocument()` | None | Available only when the server exposes REST v1 |
| `GET` | `/api/v1/integrations/project` | `projectMetadata()` | Bearer token with `projects:read` | Available only when the server exposes REST v1 |
| `POST` | `/api/v1/integrations/*` | REST methods below | Bearer token with the operation scope | Available only when the server exposes REST v1 |

The package contains clients for both the current MCP/legacy surface and REST
v1. Do not infer REST availability from the installed package version. Before
calling any REST v1 method, request
`GET /api/v1/integrations/openapi.json` from the canonical origin. HTTP `200`
with an OpenAPI 3.1 document confirms the surface; HTTP `404` means that server
does not expose REST v1, so use MCP or the legacy completion-gate method as
appropriate. Use the OpenAPI document plus `projectMetadata()` as the REST
deployment proof. Run `verifyCompatibility()` before relying on MCP.

The package does not expose owner-session routes or any agent capability for human decisions, dismissal, final acceptance, or deletion.

## Public discovery

```php
$readiness = $client->readiness();

if (! $readiness->ready) {
    foreach ($readiness->components as $component => $state) {
        // Decide how your application should surface the component state.
    }
}

$contract = $client->contractInfo();

echo $contract->contractVersion;
echo $contract->integrationVersion;
echo $contract->skillVersion;

```

`readiness()` accepts both ready (`200`) and not-ready (`503`) readiness envelopes and returns `ReadinessResult`. `contractInfo()` returns `ContractInfo`. Both requests intentionally omit the bearer token.

After the REST availability check succeeds, `openApiDocument()` returns the
public OpenAPI 3.1 document as an array and also omits the bearer token. It
rejects documents without non-empty `paths` and `components.schemas` objects:

```php
$openApi = $client->openApiDocument();
echo $openApi['openapi'];
```

A token is optional when a client is used only for public discovery:

```php
$publicClient = new Client();
$health = $publicClient->readiness();
```

Authenticated methods fail locally with `AuthenticationException` before network access when no token is configured.
Configured tokens are validated against the public `avt_` credential format before any request is sent.

## Compatibility preflight

Run the read-only preflight before treating a package/server pairing as compatible:

```php
$compatibility = $client->verifyCompatibility();

echo $compatibility->contractVersion;
echo $compatibility->protocolVersion;
echo $compatibility->serverName;
```

The preflight checks:

- contract, integration, and skill versions;
- MCP protocol version `2025-11-25`;
- server name and version;
- the exact eight tool names;
- canonical SHA-256 digests for every tool input and output schema;
- every page returned by `tools/list`.

Expected metadata is bundled in `resources/contract-manifest.json`. Compatibility failures throw `ContractException` and fail closed.

## Completion gate

`completionGate(array $payload)` sends an already prepared v1 completion-gate payload to the HTTP adapter and returns `CompletionGateResult`.

The payload must contain the project ID, source identity, adapter metadata, baseline and current source descriptors and digests, source manifest, task-session correlation ID, optional feature ID, and the integration, skill, and contract versions. The server remains authoritative for schema, scope, identity, and verification-state validation.

The legacy hook and `check_completion_gate` operation use the same bundled
`resources/contracts/v1/tools/check-completion-gate.input.schema.json` request
and `check-completion-gate.output.schema.json` success-response contract.

```php
$result = $client->completionGate($completionGatePayload, 'sdk_release_gate_01');

echo $result->outcome;
echo $result->reasonCode;
echo $result->correlationId;

if ($result->recoveryInstruction !== null) {
    echo $result->recoveryInstruction;
}
```

Passing a correlation ID is optional. Supplied IDs must contain 1 to 120 letters, numbers, dots, underscores, colons, or hyphens. Otherwise, the client generates an `sdk_`-prefixed ID.

## REST operations

Use REST when a client can send ordinary JSON over HTTPS but does not support MCP. `projectMetadata()` discovers the project ID, granted scopes, versions, supported clients, and canonical endpoints bound to the bearer token:

```php
$project = $client->projectMetadata();

echo $project->projectId;
echo $project->endpoints['mcp'];
```

`sourceKind`, `platform`, `defaultEnvironment`, and `connectionStatus` are
nullable because a newly created project may not have completed its connection
setup.

Each MCP task has a REST convenience method with the `Rest` suffix. The client adds the package's current `versions` object when the caller does not provide one.

| Operation | REST method | Required scope | Bundled schema stem |
| --- | --- | --- | --- |
| `resolve_feature` | `resolveFeatureRest()` | `features:resolve` | `resolve-feature` |
| `get_feature_context` | `getFeatureContextRest()` | `features:read` | `get-feature-context` |
| `reconcile_checklist` | `reconcileChecklistRest()` | `checklists:write` | `reconcile-checklist` |
| `get_verification_feedback` | `getVerificationFeedbackRest()` | `feedback:read` | `get-verification-feedback` |
| `address_feedback` | `addressFeedbackRest()` | `feedback:address` | `address-feedback` |
| `get_verification_status` | `getVerificationStatusRest()` | `features:read` | `get-verification-status` |
| `check_completion_gate` | `checkCompletionGateRest()` | `gates:read` | `check-completion-gate` |
| `record_verification_exception` | `recordVerificationExceptionRest()` | `exceptions:write` | `record-verification-exception` |

```php
$result = $client->getVerificationStatusRest([
    'feature_id' => 'feat_01J00000000000000000000001',
]);

$status = $result->data;
```

For dynamic integrations, use the same stable operation names with `callRestOperation()`:

```php
$result = $client->callRestOperation('get_verification_status', [
    'feature_id' => 'feat_01J00000000000000000000001',
]);
```

REST responses are returned as `RestOperationResult`; the authoritative response object is in `$data`. REST calls do not initialize or retain an MCP session.

### Request and response schemas

The exact JSON Schema 2020-12 request and response documents are distributed
with the package under `resources/contracts/v1/tools`. For an operation's schema
stem in the table above, use:

- `<stem>.input.schema.json` for the request object;
- `<stem>.output.schema.json` for a successful response object.

Relative `$ref` targets are bundled alongside them under
`resources/contracts/v1`. The schemas specify every required field, enum,
format, length, and conditional requirement for all eight operations. The
SHA-256 values in `resources/contract-manifest.json` are computed from the same
fully resolved schemas and are checked during compatibility preflight.

REST request bodies are the schema-defined input object directly, not an
additional wrapper. The client supplies the current `versions` object when it
is omitted. Successful responses are the schema-defined output object directly;
the client exposes it as `RestOperationResult::$data`. Errors use the bundled
`resources/contracts/v1/error.schema.json` envelope and are converted to the
typed exceptions described below.

The server's OpenAPI document is authoritative for its deployed REST surface.
The bundled schemas remain useful for development, code review, and servers
where the discovery endpoint is not yet available, but they do not prove that a
particular server has enabled REST v1.

### Automated evidence semantics

`reconcile_checklist` and `record_verification_exception` accept structured
`automated_evidence` entries. `outcome` records whether a command or check ran
and what it returned. The optional `evidence_sufficiency` field separately says
whether that result is enough to support the claimed behavior: `sufficient` or
`insufficient`. A passing automated test can therefore still be marked
`insufficient`, and no automated result creates a human decision or final
acceptance.

When `outcome` is `not_run`, an explicit `evidence_sufficiency` can only be
`insufficient`. Use the optional `blocker_reason` for a machine-readable cause:
`missing_credentials`, `insufficient_permissions`, `missing_prerequisite`,
`environment_unavailable`, `external_dependency_unavailable`, `resource_limit`,
or `other`. A supplied blocker reason requires `outcome: not_run`; when
`evidence_sufficiency` is also supplied, it must be `insufficient`.

```php
$payload['automated_evidence'][] = [
    'semantic_key' => 'browser.authenticated_checkout',
    'kind' => 'browser',
    'name' => 'Authenticated checkout smoke test',
    'target' => '/checkout',
    'executed_at' => '2026-08-18T10:00:00Z',
    'outcome' => 'not_run',
    'evidence_sufficiency' => 'insufficient',
    'blocker_reason' => 'missing_credentials',
    'summary' => 'The test account credential was not available.',
    'source_revision' => 'git:0123456789abcdef',
];
```

## MCP tools

The client initializes a Streamable HTTP MCP session on the first tool call, sends the `notifications/initialized` notification, retains a validated optional MCP session ID, rejects session-ID changes after initialization, and accepts JSON or Server-Sent Events JSON-RPC responses.

All eight v1 tools have named methods:

| Tool | Method | Required scope | Mode |
| --- | --- | --- | --- |
| `resolve_feature` | `resolveFeature()` | `features:resolve` | Mutating |
| `get_feature_context` | `getFeatureContext()` | `features:read` | Read-only |
| `reconcile_checklist` | `reconcileChecklist()` | `checklists:write` | Mutating |
| `get_verification_feedback` | `getVerificationFeedback()` | `feedback:read` | Read-only |
| `address_feedback` | `addressFeedback()` | `feedback:address` | Mutating |
| `get_verification_status` | `getVerificationStatus()` | `features:read` | Read-only |
| `check_completion_gate` | `checkCompletionGate()` | `gates:read` | Read-only |
| `record_verification_exception` | `recordVerificationException()` | `exceptions:write` | Mutating |

Example:

```php
$versions = [
    'integration_name' => 'acceptora-verification-php',
    'integration_version' => '1.0.0',
    'skill_version' => '1.1.0',
    'contract_version' => '1.0.0',
];

$result = $client->getVerificationStatus([
    'feature_id' => 'feat_01J00000000000000000000001',
    'versions' => $versions,
]);

$status = $result->structuredContent;
```

The generic `callTool()` method is also available:

```php
$result = $client->callTool('get_verification_status', [
    'feature_id' => 'feat_01J00000000000000000000001',
    'versions' => $versions,
]);
```

Only tool names in the bundled contract manifest are accepted. A successful tool response must contain an object-valued `structuredContent`; missing or malformed structured output fails with `InvalidResponseException`.

## Results

All result objects are immutable and implement `JsonSerializable`:

- `ReadinessResult`
- `ContractInfo`
- `CompletionGateResult`
- `ProjectMetadataResult`
- `RestOperationResult`
- `ToolCallResult`
- `CompatibilityResult`

`ToolCallResult::$structuredContent` contains the authoritative structured MCP result. `ToolCallResult::$content` preserves the MCP content list for callers that also need display-oriented content.

## Errors and retry policy

All package exceptions extend `Acceptora\Verification\Exception\VerificationException` and expose:

- `errorCode`
- `statusCode`
- `retryable`
- `retryAfter`
- `correlationId`
- `details`
- `recoveryInstruction`

Specialized exceptions include:

- `AuthenticationException` for missing or invalid credentials;
- `AuthorizationException` for insufficient scope;
- `RateLimitException` for rate limiting;
- `ContractException` for contract or MCP compatibility failures;
- `InvalidResponseException` for malformed, oversized, or incompatible responses;
- `TransportException` for network transport failures;
- `ApiException` for other server errors, including future error codes unknown to this package.

Unknown server error codes are preserved in `errorCode`. The client never retries automatically. Use `retryable` and `retryAfter` to implement bounded retry behavior appropriate for your application.

```php
use Acceptora\Verification\Exception\RateLimitException;
use Acceptora\Verification\Exception\VerificationException;

try {
    $result = $client->getVerificationStatus($arguments);
} catch (RateLimitException $exception) {
    $retryAfter = $exception->retryAfter;
} catch (VerificationException $exception) {
    $correlationId = $exception->correlationId;
    $code = $exception->errorCode;
}
```

## HTTP safety behavior

- Redirect following is disabled.
- Connect and total request timeouts are explicit.
- Stream read timeouts use the same bounded total-request setting.
- TLS verification is enabled.
- Request bodies are capped at 2,000,000 bytes by default.
- Response bodies are capped at 4,000,000 bytes by default.
- MCP session IDs are accepted only when they match the package's restricted character and length policy.
- The bearer token is omitted from public readiness and contract requests.
- Configured Acceptora tokens, recognized Acceptora and GitHub token forms, values containing private-key markers, and values under credential-named detail keys are redacted. This is not arbitrary-secret detection.
- Ambient HTTP proxies are disabled for the permitted plain-HTTP loopback origins so bearer tokens are sent only to the configured local endpoint; HTTPS origins retain normal proxy behavior.
- Logs contain operational metadata, not bearer tokens, request payloads, or response bodies.

The package emits these PSR-3 event names when a logger is supplied:

- `acceptora.verification.request_completed`
- `acceptora.verification.request_failed`
- `acceptora.verification.transport_failed`
- `acceptora.verification.invalid_response`

`request_completed` means that an accepted HTTP response was received and decoded; its outcome is `response_received`. Typed result validation can still reject that response and then emits `invalid_response`, so consumers should not interpret transport receipt as contract-level success.

Applications remain responsible for configuring logger destinations, retention, access controls, and redaction of any context they add outside this package.

## Development

```bash
composer install
composer format
composer check
```

`composer format` applies the package style. `composer check` validates Composer
metadata, runs PHPUnit, runs PHPStan at level 8, and audits the locked dependency
graph.

## Versioning

The package follows Semantic Versioning. The bundled manifest identifies
package, integration, contract, and server version `1.0.0`, and compatible
Acceptora Agent Skill version `1.1.0`. Package releases declare compatibility
with Acceptora integration, skill, contract, and server versions in the bundled
manifest.
Additive package features use minor releases; compatible fixes use patch
releases; incompatible public API or supported-contract changes require a major
release.

## Support and contributing

- Read the [Acceptora documentation](https://www.acceptora.com/docs) for product
  setup and workflow guidance.
- Use [GitHub issues](https://github.com/Elvesora/acceptora-php/issues) for
  reproducible package defects and documentation problems.
- Follow [CONTRIBUTING.md](CONTRIBUTING.md) before opening a pull request.
- Report suspected vulnerabilities through the private process in
  [SECURITY.md](SECURITY.md), never through a public issue.

## Security

See [SECURITY.md](SECURITY.md) for vulnerability reporting and supported-version policy.

## License

Acceptora Verification PHP is released under the [MIT License](LICENSE).
