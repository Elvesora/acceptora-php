# Contributing

Thank you for contributing to Acceptora Verification PHP.

## Development requirements

- PHP 8.2 or newer
- Composer 2
- JSON and Mbstring PHP extensions

Install dependencies and run the complete package gate:

```bash
composer install
composer format
composer check
```

The formatter applies the package style. The gate validates Composer metadata,
runs the PHPUnit suite, performs PHPStan level 8 analysis, and audits the locked
dependency graph.

## Change expectations

- Keep the package framework-independent.
- Preserve PHP 8.2 compatibility unless a major release changes the supported platform.
- Keep public methods explicitly typed and result objects immutable where practical.
- Add PHPUnit class coverage for successful behavior, failures, and boundary conditions.
- Do not add automatic retries. Preserve server retry metadata for the caller.
- Never log bearer tokens, request payloads, response bodies, or secrets.
- Keep redirects disabled, TLS verification enabled, and request-size, response-size, and timeout controls explicit.
- Update `resources/contract-manifest.json`, compatibility tests, README, and changelog whenever a supported server contract changes.
- Keep the package independently installable; do not add path repositories or dependencies on another Acceptora package.

## Contract changes

Changes to endpoints, authentication, scopes, versions, MCP tools, schemas, or stable error codes must be confirmed against the corresponding server release. Update the bundled manifest and compatibility coverage in the same contribution.

Schema digests use canonical JSON with recursively sorted object keys, preserved array ordering, unescaped slashes, and unescaped Unicode before SHA-256 hashing.

## Pull requests

Provide a focused description of the public behavior being changed and the verification performed. Keep unrelated changes separate. All continuous-integration jobs must pass across the supported PHP matrix before merge.

## Security reports

Do not disclose vulnerabilities in a public issue. Follow [SECURITY.md](SECURITY.md).
