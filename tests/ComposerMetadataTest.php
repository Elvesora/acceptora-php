<?php

declare(strict_types=1);

namespace Acceptora\Verification\Tests;

use Acceptora\Verification\Exception\InvalidResponseException;
use Acceptora\Verification\Support\ContractManifest;
use PHPUnit\Framework\TestCase;

final class ComposerMetadataTest extends TestCase
{
    public function test_package_metadata_is_standalone_and_publishable(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__.'/../composer.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('acceptora/verification-php', $composer['name']);
        $this->assertSame('library', $composer['type']);
        $this->assertSame('MIT', $composer['license']);
        $this->assertSame('https://github.com/Elvesora/acceptora-php/issues', $composer['support']['issues']);
        $this->assertSame('https://github.com/Elvesora/acceptora-php', $composer['support']['source']);
        $this->assertArrayNotHasKey('repositories', $composer);
        $this->assertArrayNotHasKey('acceptora/verification-laravel', $composer['require']);
        $this->assertSame('src/', $composer['autoload']['psr-4']['Acceptora\\Verification\\']);
        $this->assertSame('^1.29', $composer['require-dev']['laravel/pint']);
        $this->assertSame('pint --format agent', $composer['scripts']['format']);
        $this->assertSame('@composer validate --strict', $composer['scripts']['validate-package']);
        $this->assertSame('@composer audit --locked --no-interaction', $composer['scripts']['security-audit']);

        $attributes = (string) file_get_contents(__DIR__.'/../.gitattributes');
        $this->assertStringContainsString('* text=auto eol=lf', $attributes);
        $this->assertStringContainsString('/.git export-ignore', $attributes);

        foreach ([
            '/.editorconfig',
            '/.git',
            '/.gitattributes',
            '/.github',
            '/.gitignore',
            '/.phpstan.cache',
            '/.phpunit.cache',
            '/build',
            '/composer.lock',
            '/phpstan.neon.dist',
            '/phpunit.xml.dist',
            '/tests',
            '/vendor',
        ] as $excluded) {
            $this->assertContains($excluded, $composer['archive']['exclude']);
        }

        foreach ([
            'CODE_OF_CONDUCT.md',
            '.github/ISSUE_TEMPLATE/bug_report.yml',
            '.github/ISSUE_TEMPLATE/config.yml',
            '.github/PULL_REQUEST_TEMPLATE.md',
        ] as $communityFile) {
            $this->assertFileExists(__DIR__.'/../'.$communityFile);
        }

        $workflow = (string) file_get_contents(__DIR__.'/../.github/workflows/tests.yml');
        $this->assertStringContainsString("guzzle: '^7.9'", $workflow);
        $this->assertStringContainsString("guzzle: '^8.0'", $workflow);
        $this->assertStringContainsString('composer archive --format=zip', $workflow);
        $this->assertStringContainsString('resources/contracts/v1/error\\.schema\\.json', $workflow);
    }

    public function test_contract_manifest_rejects_incomplete_metadata(): void
    {
        $this->expectException(InvalidResponseException::class);

        new ContractManifest([
            'schema_version' => 1,
            'contract' => ['version' => '1.0.0'],
        ]);
    }

    public function test_manifest_declares_the_exact_tool_scope_contract(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(__DIR__.'/../resources/contract-manifest.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame(1, $manifest['schema_version']);
        $this->assertSame(['name' => 'acceptora/verification-php', 'version' => '1.0.0'], $manifest['package']);
        $this->assertSame(['version' => '1.0.0'], $manifest['integration']);
        $this->assertSame(['version' => '1.1.0'], $manifest['skill']);
        $this->assertSame(['version' => '1.0.0', 'mcp_protocol_version' => '2025-11-25'], $manifest['contract']);
        $this->assertSame(['name' => 'Acceptora Verification', 'version' => '1.0.0'], $manifest['server']);
        $this->assertSame([
            'AUTH_REQUIRED',
            'SCOPE_DENIED',
            'FEATURE_AMBIGUOUS',
            'FEATURE_ARCHIVED',
            'REVISION_CONFLICT',
            'IDEMPOTENCY_CONFLICT',
            'SOURCE_STALE',
            'UNCOVERED_CHANGED_SURFACE',
            'EXCEPTION_POLICY_DENIED',
            'SECRET_REJECTED',
            'PAYLOAD_TOO_LARGE',
            'RATE_LIMITED',
            'CONTRACT_UNSUPPORTED',
            'SERVICE_UNAVAILABLE',
        ], $manifest['error_codes']);
        $this->assertSame([
            'resolve_feature' => 'features:resolve',
            'get_feature_context' => 'features:read',
            'reconcile_checklist' => 'checklists:write',
            'get_verification_feedback' => 'feedback:read',
            'address_feedback' => 'feedback:address',
            'get_verification_status' => 'features:read',
            'check_completion_gate' => 'gates:read',
            'record_verification_exception' => 'exceptions:write',
        ], array_column($manifest['tools'], 'required_scope', 'name'));
        $this->assertSame([
            'resolve_feature' => false,
            'get_feature_context' => true,
            'reconcile_checklist' => false,
            'get_verification_feedback' => true,
            'address_feedback' => false,
            'get_verification_status' => true,
            'check_completion_gate' => true,
            'record_verification_exception' => false,
        ], array_column($manifest['tools'], 'read_only', 'name'));
        $this->assertSame([
            ['name' => 'readiness', 'method' => 'GET', 'path' => '/api/health', 'authentication' => 'none'],
            ['name' => 'contract_info', 'method' => 'GET', 'path' => '/api/contract-version', 'authentication' => 'none'],
            ['name' => 'openapi', 'method' => 'GET', 'path' => '/api/v1/integrations/openapi.json', 'authentication' => 'none'],
        ], array_slice($manifest['http_endpoints'], 0, 3));

        $this->assertSame([
            'project' => '/api/v1/integrations/project',
            'resolve_feature' => '/api/v1/integrations/features/resolve',
            'get_feature_context' => '/api/v1/integrations/features/context',
            'reconcile_checklist' => '/api/v1/integrations/checklists/reconcile',
            'get_verification_feedback' => '/api/v1/integrations/feedback/query',
            'address_feedback' => '/api/v1/integrations/feedback/address',
            'get_verification_status' => '/api/v1/integrations/status',
            'check_completion_gate' => '/api/v1/integrations/completion-gate',
            'record_verification_exception' => '/api/v1/integrations/verification-exceptions',
            'legacy_completion_gate' => '/api/integrations/completion-gate',
        ], array_column(array_values(array_filter(
            $manifest['http_endpoints'],
            static fn (array $endpoint): bool => ($endpoint['authentication'] ?? null) === 'bearer'
                && ($endpoint['name'] ?? null) !== 'mcp',
        )), 'path', 'name'));
    }

    public function test_public_readme_discloses_installation_and_server_availability_boundaries(): void
    {
        $readme = (string) file_get_contents(__DIR__.'/../README.md');

        $this->assertStringContainsString('composer require acceptora/verification-php:^1.0', $readme);
        $this->assertStringNotContainsString('No tagged '.'release is currently available', $readme);
        $this->assertStringContainsString('Settings > Credentials', $readme);
        $this->assertStringContainsString('Settings > Connection', $readme);
        $this->assertStringContainsString('HTTP `404` means that server', $readme);
        $this->assertStringContainsString('does not expose REST v1', $readme);
        $this->assertStringContainsString('origin without embedded credentials, a path, a query, or a fragment', $readme);
        $this->assertStringContainsString('non-empty `paths` and `components.schemas` objects', $readme);
        $this->assertStringContainsString('Use the OpenAPI document plus `projectMetadata()` as the REST', $readme);
        $this->assertStringContainsString('Run `verifyCompatibility()` before relying on MCP.', $readme);
        $this->assertStringContainsString('check-completion-gate.input.schema.json', $readme);
        $this->assertStringContainsString('`evidence_sufficiency`', $readme);
        $this->assertStringContainsString('`missing_credentials`', $readme);
        $this->assertStringContainsString('no automated result creates a human decision or final', $readme);
        $this->assertStringContainsString('## Support and contributing', $readme);
        $this->assertStringContainsString('[CONTRIBUTING.md](CONTRIBUTING.md)', $readme);
        $this->assertStringContainsString('[SECURITY.md](SECURITY.md)', $readme);

        foreach ([
            'projects:read',
            'features:resolve',
            'features:read',
            'checklists:write',
            'feedback:read',
            'feedback:address',
            'gates:read',
            'exceptions:write',
        ] as $scope) {
            $this->assertStringContainsString('`'.$scope.'`', $readme);
        }
    }

    public function test_bundled_operation_schemas_match_the_contract_manifest_digests(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(__DIR__.'/../resources/contract-manifest.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $expected = [];
        $actual = [];

        foreach ($manifest['tools'] as $tool) {
            $stem = str_replace('_', '-', $tool['name']);
            $input = $this->resolveSchema(__DIR__.'/../resources/contracts/v1/tools/'.$stem.'.input.schema.json');
            $output = $this->resolveSchema(__DIR__.'/../resources/contracts/v1/tools/'.$stem.'.output.schema.json');

            $expected[$tool['name']] = [
                'input' => $tool['input_schema_sha256'],
                'output' => $tool['output_schema_sha256'],
            ];
            $actual[$tool['name']] = [
                'input' => $this->schemaDigest($input),
                'output' => $this->schemaDigest($output),
            ];
        }

        $this->assertSame($expected, $actual);
    }

    public function test_bundled_automated_evidence_distinguishes_sufficiency_and_blockers(): void
    {
        $common = json_decode(
            (string) file_get_contents(__DIR__.'/../resources/contracts/v1/common.schema.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $automatedEvidence = $common['$defs']['automatedEvidence'];

        $this->assertSame(
            ['sufficient', 'insufficient'],
            $automatedEvidence['properties']['evidence_sufficiency']['enum'],
        );
        $this->assertSame([
            'missing_credentials',
            'insufficient_permissions',
            'missing_prerequisite',
            'environment_unavailable',
            'external_dependency_unavailable',
            'resource_limit',
            'other',
        ], $automatedEvidence['properties']['blocker_reason']['enum']);
        $this->assertNotContains('evidence_sufficiency', $automatedEvidence['required']);
        $this->assertNotContains('blocker_reason', $automatedEvidence['required']);
        $this->assertSame('not_run', $automatedEvidence['allOf'][0]['then']['properties']['outcome']['const']);
        $this->assertSame(
            'insufficient',
            $automatedEvidence['allOf'][0]['then']['properties']['evidence_sufficiency']['const'],
        );
        $this->assertSame('not_run', $automatedEvidence['allOf'][1]['if']['properties']['outcome']['const']);
        $this->assertSame(
            'insufficient',
            $automatedEvidence['allOf'][1]['then']['properties']['evidence_sufficiency']['const'],
        );
    }

    public function test_every_bundled_contract_document_is_valid_json_object(): void
    {
        $files = glob(__DIR__.'/../resources/contracts/v1/*.json') ?: [];

        foreach (glob(__DIR__.'/../resources/contracts/v1/{enums,tools}/*.json', GLOB_BRACE) ?: [] as $file) {
            $files[] = $file;
        }

        $this->assertCount(28, $files);

        foreach ($files as $file) {
            $decoded = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

            $this->assertIsArray($decoded, $file);
            $this->assertFalse(array_is_list($decoded), $file);
        }

        $manifest = json_decode(
            (string) file_get_contents(__DIR__.'/../resources/contract-manifest.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $errorCodeSchema = json_decode(
            (string) file_get_contents(__DIR__.'/../resources/contracts/v1/enums/error-code.schema.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame($manifest['error_codes'], $errorCodeSchema['enum']);
    }

    public function test_customer_release_documents_keep_release_status_current(): void
    {
        $readme = (string) file_get_contents(__DIR__.'/../README.md');
        $changelog = (string) file_get_contents(__DIR__.'/../CHANGELOG.md');
        $security = (string) file_get_contents(__DIR__.'/../SECURITY.md');

        $this->assertStringContainsString('## [Unreleased]', $changelog);
        $this->assertStringContainsString('## [1.0.0] - 2026-08-11', $changelog);
        $this->assertStringNotContainsString('No tagged '.'release', $readme.$security);
        $this->assertStringNotContainsString('Unreleased '.'source', $security);
        $this->assertStringContainsString('| 1.x | Supported |', $security);
    }

    public function test_release_assets_do_not_contain_internal_paths_or_working_notes(): void
    {
        $files = array_map(
            static fn (string $file): string => __DIR__.'/../'.$file,
            [
                'README.md',
                'CHANGELOG.md',
                'CODE_OF_CONDUCT.md',
                'CONTRIBUTING.md',
                'SECURITY.md',
                'LICENSE',
                'composer.json',
                '.github/ISSUE_TEMPLATE/bug_report.yml',
                '.github/ISSUE_TEMPLATE/config.yml',
                '.github/PULL_REQUEST_TEMPLATE.md',
                '.editorconfig',
                '.gitattributes',
                '.gitignore',
                'phpstan.neon.dist',
                'phpunit.xml.dist',
            ],
        );

        foreach (['.github', 'src', 'resources', 'tests'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
                __DIR__.'/../'.$directory,
                \FilesystemIterator::SKIP_DOTS,
            ));

            foreach ($iterator as $item) {
                if ($item->isFile()) {
                    $files[] = $item->getPathname();
                }
            }
        }

        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);
            $displayName = str_replace('\\', '/', substr($file, strlen(__DIR__.'/../')));

            $this->assertDoesNotMatchRegularExpression('/\b[A-Z]:\\\\/i', $contents, $displayName);
            $this->assertDoesNotMatchRegularExpression('/\b(?:TO'.'DO|FIX'.'ME|W'.'IP|T'.'BD)\b/i', $contents, $displayName);
            $this->assertDoesNotMatchRegularExpression('/\b(?:acceptora\.lo'.'cal|acceptora\.inter'.'nal|lara'.'gon)\b/i', $contents, $displayName);
            $this->assertDoesNotMatchRegularExpression('/\binter'.'nal[-_ ](?:only|team|process|release)\b/i', $contents, $displayName);
            $this->assertDoesNotMatchRegularExpression('/(?:[A-Z]:\\\\Users\\\\|\/Users\/|\/ho'.'me\/|\.codex(?:-output)?[\\\\\/])/i', $contents, $displayName);
            $this->assertDoesNotMatchRegularExpression('/(?:AK'.'IA[0-9A-Z]{16}|AS'.'IA[0-9A-Z]{16}|gh'.'[pousr]_[A-Za-z0-9]{30,}|github_'.'pat_[A-Za-z0-9_]{30,})/', $contents, $displayName);
            $this->assertDoesNotMatchRegularExpression('/\bav'.'t_[0-9A-HJKMNP-TV-Z]{26}_[A-Za-z0-9]{48}\b/', $contents, $displayName);
            $this->assertDoesNotMatchRegularExpression('/-----BEGIN (?:[A-Z ]+ )?PRIVATE '.'KEY-----/', $contents, $displayName);
        }
    }

    /** @return array<string, mixed> */
    private function resolveSchema(string $file): array
    {
        $root = realpath(__DIR__.'/../resources/contracts/v1');
        $realFile = realpath($file);

        $this->assertNotFalse($root);
        $this->assertNotFalse($realFile, $file);
        $this->assertStringStartsWith($root.DIRECTORY_SEPARATOR, $realFile);

        $resolved = $this->resolveSchemaValue($this->readSchema($realFile, $root), $realFile, $root);
        $this->assertIsArray($resolved);
        unset($resolved['$schema'], $resolved['$id']);

        return $resolved;
    }

    private function resolveSchemaValue(mixed $value, string $currentFile, string $root): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (isset($value['$ref']) && is_string($value['$ref'])) {
            [$relative, $pointer] = array_pad(explode('#', $value['$ref'], 2), 2, '');
            $targetFile = $relative === ''
                ? $currentFile
                : dirname($currentFile).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $realTarget = realpath($targetFile);

            $this->assertNotFalse($realTarget, $targetFile);
            $target = $this->readSchema($realTarget, $root);

            if ($pointer !== '') {
                foreach (explode('/', ltrim($pointer, '/')) as $segment) {
                    $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
                    $this->assertIsArray($target);
                    $this->assertArrayHasKey($segment, $target);
                    $target = $target[$segment];
                }
            }

            $siblings = $value;
            unset($siblings['$ref']);
            $resolvedTarget = $this->resolveSchemaValue($target, $realTarget, $root);

            return is_array($resolvedTarget)
                ? array_merge($resolvedTarget, $this->resolveSchemaValue($siblings, $currentFile, $root))
                : $resolvedTarget;
        }

        foreach ($value as $key => $child) {
            $value[$key] = $this->resolveSchemaValue($child, $currentFile, $root);
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function readSchema(string $file, string $root): array
    {
        $this->assertStringStartsWith($root.DIRECTORY_SEPARATOR, $file);
        $decoded = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        $this->assertFalse(array_is_list($decoded));

        return $decoded;
    }

    /** @param array<string, mixed> $schema */
    private function schemaDigest(array $schema): string
    {
        return 'sha256:'.hash('sha256', json_encode(
            $this->canonicalize($schema),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $child) {
            $value[$key] = $this->canonicalize($child);
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
