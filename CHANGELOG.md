# Changelog

All notable changes to this package are documented in this file.

The format follows Keep a Changelog, and releases follow Semantic Versioning.

## [Unreleased]

### Added

- Document the optional `evidence_sufficiency` and structured `blocker_reason` fields for automated evidence, and pin the updated reconciliation and verification-exception input schema digests.
- Accept Acceptora Agent Skill `1.1.0` during compatibility preflight while retaining package, integration, contract, and server version `1.0.0`.

### Changed

- Remove the obsolete reauthentication error from the bundled public error-code contract and manifest.

## [1.0.0] - 2026-08-11

### Added

- Framework-independent Guzzle client for Acceptora readiness, contract information, completion-gate, and MCP endpoints.
- Public OpenAPI 3.1 discovery through `GET /api/v1/integrations/openapi.json` without bearer authentication when the target server exposes REST v1.
- Credential-bound project metadata through `GET /api/v1/integrations/project` when the target server exposes REST v1.
- Generic and named REST clients for all eight versioned Acceptora verification operations while preserving the MCP workflow and legacy completion-gate hook.
- Typed immutable results for readiness, contract metadata, completion-gate decisions, MCP tool calls, and compatibility reports.
- Typed project metadata and REST operation results, canonical route metadata, and REST transport tests.
- Named client methods, required-scope metadata, and read-only or mutating annotations for all eight verification MCP v1 tools.
- MCP Streamable HTTP initialization, session handling, paginated tool discovery, and JSON or Server-Sent Events JSON-RPC parsing.
- Compatibility preflight for contract versions, MCP protocol metadata, tool names, and canonical tool-schema SHA-256 digests.
- Bundled JSON Schema 2020-12 request, success-response, and error-envelope documents for all eight REST/MCP operations.
- Typed exception hierarchy that preserves server error codes, retryability, retry timing, correlation IDs, and safe details.
- Credential-free access to public readiness and contract-information endpoints, with local failure before authenticated network access when no token is configured.
- Explicit redirect, TLS, timeout, request-size, response-size, correlation-ID, token-redaction, and structured logging safeguards.
- PHPUnit, PHPStan level 8, Composer validation, dependency-audit, and supported-PHP CI gates.
- Public contribution, issue-reporting, pull-request, conduct, and security guidance.
- Explicit PHP 8.2 through 8.5 and Guzzle 7.9 or 8.x continuous-integration lanes, including release-archive inspection.

### Fixed

- Reject control characters, backslashes, malformed URL authorities, and unsafe or oversized user-agent values before the HTTP client receives them.
- Synchronize compatibility-preflight schema digests with the bundled v1 contract schemas.
- Accept nullable connection metadata exactly as declared by the public REST schema.
- Reject invalid OpenAPI documents, non-finite timeouts, and non-JSON request values before use.
- Classify response-stream failures as retryable transport failures and redact token values from error-detail keys.
- Require non-empty OpenAPI paths and component schemas, and reject base URLs that are not origin-only.
- Bound cumulative paginated MCP tool metadata by byte size and definition count.
- Reject MCP session-identifier changes after initialization.
- Prevent rejected configuration and outbound arguments, decoded HTTP or MCP errors, and malformed, oversized, unreadable, or semantically invalid success responses from retaining token values in native exception traces or serialized exceptions.
- Disable ambient HTTP proxies for permitted plain-HTTP loopback origins so bearer credentials cannot be redirected to a proxy.
- Redact recognized Acceptora and GitHub token forms, private-key markers, credential-named values, and secret-bearing array keys from bounded remote error surfaces.
