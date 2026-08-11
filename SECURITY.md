# Security Policy

## Supported versions

| Version | Security updates |
| --- | --- |
| 1.x | Supported |

Security fixes are released on the latest supported minor line. Consumers
should use its newest patch release.

## Reporting a vulnerability

Do not open a public issue for a suspected vulnerability. Use GitHub's private
vulnerability-reporting flow for the `Elvesora/acceptora-php` repository when
it is available. If the private-reporting button is unavailable, use the
[Acceptora contact page](https://www.acceptora.com/contact) only to request a
secure reporting channel. Do not put vulnerability details, reproduction
steps, credentials, or private data in that initial contact request.

Include:

- the affected package version and PHP version;
- the security impact and affected operation;
- reproducible steps using synthetic data;
- whether credentials, logs, redirects, TLS, response limits, or MCP session handling are involved;
- any proposed mitigation.

Do not include live bearer tokens, private source material, production customer data, credentials, cookies, or complete sensitive request or response bodies. Revoke any credential that may have been exposed during investigation.

## Security model

The package applies the following controls:

- authenticated operations use a bearer token supplied by the caller;
- readiness and contract-information requests do not transmit the bearer token;
- redirects are not followed;
- HTTPS certificate verification is enabled;
- plain HTTP is limited to loopback development hosts;
- total and connection timeouts are explicit;
- request size is bounded before network access;
- response size is bounded;
- MCP session identifiers are validated before reuse;
- remote error messages and details redact the configured token;
- PSR-3 events contain operational metadata rather than payload or response bodies;
- retries are controlled by the consuming application.

Consumers must protect tokens in an environment-specific secret store, restrict token scopes, rotate exposed credentials, secure their logger and dependency cache, and keep this package and its transitive dependencies current.
