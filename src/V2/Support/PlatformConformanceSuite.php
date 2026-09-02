<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use JsonException;
use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Workflow\V2\Conformance\PlatformArtifactSourceIdentity;
use Workflow\V2\Conformance\WorkerProtocolArtifactBindings;

/**
 * Canonical, machine-readable mirror of the public platform conformance suite.
 *
 * The complete authority document ships with the package so server endpoints,
 * release gates, and third-party harnesses all consume the same target,
 * category, and pass/fail semantics as the public docs site.
 *
 * @api Stable class surface consumed by the standalone workflow-server,
 * which re-exports the manifest from `GET /api/cluster/info` under the
 * `platform_conformance_suite` key.
 */
final class PlatformConformanceSuite
{
    public const SCHEMA = 'durable-workflow.v2.platform-conformance.suite';

    public const VERSION = 47;

    public const MIRROR_SHA256 = '985f8a657003c7b4f4db0670ae0be4212537bcb4609a9534149704eaee11e83f';

    public const RUNTIME_SOURCE_REVISION = '75dfd5c869823409ef3d6c4b009a7882159ae9a2';

    public const FIXTURE_SOURCE_REVISION = self::RUNTIME_SOURCE_REVISION;

    public const PROTOCOL_SOURCE_REVISION = 'f781ced1ae33c8697835bd527a125bdf3eaf4321';

    public const RESULT_SCHEMA = 'durable-workflow.v2.platform-conformance.result';

    public const RESULT_VERSION = 1;

    public const AUTHORITY_DOC = 'docs/platform-conformance.md';

    public const AUTHORITY_URL = 'https://durable-workflow.github.io/docs/2.0/platform-conformance';

    public const CATEGORY_STATUS_STABLE = 'stable';

    public const CATEGORY_STATUS_PROVISIONAL = 'provisional';

    public const CONFORMANCE_LEVEL_FULL = 'full';

    public const CONFORMANCE_LEVEL_PARTIAL = 'partial';

    public const CONFORMANCE_LEVEL_PROVISIONAL = 'provisional';

    public const CONFORMANCE_LEVEL_NONCONFORMING = 'nonconforming';

    public const CONFORMANCE_LEVELS = [
        self::CONFORMANCE_LEVEL_FULL,
        self::CONFORMANCE_LEVEL_PARTIAL,
        self::CONFORMANCE_LEVEL_PROVISIONAL,
        self::CONFORMANCE_LEVEL_NONCONFORMING,
    ];

    private const SUITE_SOURCE_DIRECTORY = 'resources/conformance/suite-v38/';

    private const CURRENT_PROTOCOL_SPEC_DIRECTORY = 'resources/conformance/suite-v47/platform-protocol-specs/';

    private const RUNTIME_SOURCE_DIRECTORY = self::SUITE_SOURCE_DIRECTORY . 'platform-conformance/';

    private const CLI_FIXTURE_BASE_URL = 'https://durable-workflow.github.io/cli-json-envelopes/v3';

    private const CLI_FIXTURE_DIRECTORY = 'resources/conformance/suite-v40/cli-json-envelopes/';

    private const CLI_MANIFEST_ARTIFACT_ID = 'durable-workflow.cli.output-schema-manifest@3';

    private const CLI_SCHEMA_ARTIFACT_PREFIX = 'durable-workflow.cli.output-schema/';

    private const CLI_SCHEMA_ARTIFACT_SUFFIX = '@3';

    /**
     * @var array<string, mixed>|null
     */
    private static ?array $manifest = null;

    /**
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        if (self::$manifest !== null) {
            return self::$manifest;
        }

        $path = dirname(__DIR__, 3) . '/resources/platform-conformance-contract.json';
        $json = file_get_contents($path);

        if ($json === false) {
            throw new RuntimeException("Platform conformance suite mirror is missing at {$path}.");
        }

        if (hash('sha256', $json) !== self::MIRROR_SHA256) {
            throw new RuntimeException(
                'Platform conformance suite mirror digest does not match the packaged authority.'
            );
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Platform conformance suite mirror is not valid JSON.', 0, $exception);
        }

        if (! is_array(
            $decoded
        ) || ($decoded['schema'] ?? null) !== self::SCHEMA || ($decoded['version'] ?? null) !== self::VERSION) {
            throw new RuntimeException('Platform conformance suite mirror identity does not match the class contract.');
        }

        PlatformArtifactSourceIdentity::fromManifest($decoded);
        self::assertStableFixtureSources($decoded);
        self::assertCliJsonEnvelopeClosure($decoded);
        self::assertStableSourceReferenceClosure($decoded);
        WorkerProtocolArtifactBindings::assertManifest($decoded);

        self::$manifest = $decoded;

        return self::$manifest;
    }

    public static function workflowSourceRelease(): string
    {
        $path = dirname(__DIR__, 3) . '/composer.json';
        $json = file_get_contents($path);

        if ($json === false) {
            throw new RuntimeException("Workflow package metadata is missing at {$path}.");
        }

        try {
            /** @var mixed $composer */
            $composer = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Workflow package metadata is not valid JSON.', 0, $exception);
        }

        $release = is_array($composer) ? ($composer['extra']['durable-workflow']['product-train'] ?? null) : null;
        if (! is_string($release) || preg_match('/\A2\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\z/D', $release) !== 1) {
            throw new RuntimeException('Workflow package metadata must declare one exact stable 2.x release.');
        }

        return $release;
    }

    /**
     * @return array<int, string>
     */
    public static function targetNames(): array
    {
        return array_keys(self::manifest()['targets']);
    }

    /**
     * @return array<int, string>
     */
    public static function fixtureCategoryNames(): array
    {
        return array_keys(self::manifest()['fixture_catalog']);
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private static function assertStableFixtureSources(array $manifest): void
    {
        $fixtureCatalog = $manifest['fixture_catalog'] ?? null;

        if (! is_array($fixtureCatalog)) {
            throw new RuntimeException('Platform conformance suite fixture catalog is missing.');
        }

        foreach ($fixtureCatalog as $categoryName => $category) {
            if (! is_array($category) || ($category['status'] ?? null) !== self::CATEGORY_STATUS_STABLE) {
                continue;
            }

            $sources = $category['sources'] ?? null;
            if (! is_array($sources) || $sources === []) {
                throw new RuntimeException(
                    "Stable platform conformance fixture category [{$categoryName}] must declare a source."
                );
            }

            foreach ($sources as $source) {
                if (! is_array($source)) {
                    throw new RuntimeException(
                        "Stable platform conformance fixture category [{$categoryName}] has an invalid source."
                    );
                }

                self::assertStableFixtureSource($categoryName, $source);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private static function assertStableFixtureSource(string $categoryName, array $source): void
    {
        $artifactId = $source['artifact_id'] ?? null;
        if (! is_string($artifactId) || trim($artifactId) === '') {
            throw new RuntimeException(
                "Stable platform conformance fixture category [{$categoryName}] must declare an artifact identity."
            );
        }

        $resolverUrl = $source['resolver_url'] ?? null;
        if (! is_string($resolverUrl)) {
            throw new RuntimeException("Stable fixture source [{$artifactId}] must declare an immutable resolver URL.");
        }

        $sourcePath = self::localFixtureSourcePath($artifactId, $resolverUrl);
        $declaredDigest = $source['sha256'] ?? null;
        if (! is_string($declaredDigest) || preg_match('/\Asha256:[0-9a-f]{64}\z/D', $declaredDigest) !== 1) {
            throw new RuntimeException("Stable fixture source [{$artifactId}] must declare a SHA-256 byte binding.");
        }

        $actualDigest = hash_file('sha256', $sourcePath);
        if ($actualDigest === false || ! hash_equals($declaredDigest, 'sha256:' . $actualDigest)) {
            throw new RuntimeException(
                "Stable fixture source [{$artifactId}] does not match its declared SHA-256 byte binding."
            );
        }
    }

    private static function localFixtureSourcePath(string $artifactId, string $resolverUrl): string
    {
        $relativePath = self::relativeFixtureSourcePath($artifactId, $resolverUrl);

        $sourcePath = dirname(__DIR__, 3) . '/' . $relativePath;
        if (! is_file($sourcePath)) {
            throw new RuntimeException("Vendored stable fixture source [{$artifactId}] is missing.");
        }

        return $sourcePath;
    }

    /**
     * @param  array<string, mixed>  $suite
     */
    private static function assertCliJsonEnvelopeClosure(array $suite): void
    {
        $category = $suite['fixture_catalog']['cli_json_envelopes'] ?? null;
        if (! is_array($category) || ($category['status'] ?? null) !== self::CATEGORY_STATUS_STABLE) {
            throw new RuntimeException('The CLI JSON envelope fixture category must be stable.');
        }

        $sources = $category['sources'] ?? null;
        if (! is_array($sources) || $sources === []) {
            throw new RuntimeException('The CLI JSON envelope fixture closure is missing.');
        }

        /** @var array<string, array<string, mixed>> $sourcesByUrl */
        $sourcesByUrl = [];
        foreach ($sources as $source) {
            $artifactId = is_array($source) ? ($source['artifact_id'] ?? null) : null;
            if (
                ! is_array($source)
                || ! is_string($artifactId)
                || ! is_string($source['resolver_url'] ?? null)
            ) {
                throw new RuntimeException('The CLI JSON envelope fixture closure contains an invalid source.');
            }
            if (
                $artifactId !== self::CLI_MANIFEST_ARTIFACT_ID
                && preg_match(
                    '/\Adurable-workflow\.cli\.output-schema\/[a-z0-9.-]+\.schema\.json@3\z/D',
                    $artifactId,
                ) !== 1
            ) {
                throw new RuntimeException(
                    "The CLI JSON envelope fixture source [{$artifactId}] is not part of the manifest dependency closure."
                );
            }
            if (isset($sourcesByUrl[$source['resolver_url']])) {
                throw new RuntimeException('The CLI JSON envelope fixture closure repeats a resolver URL.');
            }
            $sourcesByUrl[$source['resolver_url']] = $source;
        }

        $manifestUrl = self::CLI_FIXTURE_BASE_URL . '/manifest.json';
        $manifestSource = $sourcesByUrl[$manifestUrl] ?? null;
        if (
            ! is_array($manifestSource)
            || ($manifestSource['artifact_id'] ?? null) !== self::CLI_MANIFEST_ARTIFACT_ID
        ) {
            throw new RuntimeException('The CLI output-schema manifest source binding is missing.');
        }

        $manifestPath = self::localFixtureSourcePath(self::CLI_MANIFEST_ARTIFACT_ID, $manifestUrl);
        $manifestJson = file_get_contents($manifestPath);
        if ($manifestJson === false) {
            throw new RuntimeException('The vendored CLI output-schema manifest is missing.');
        }

        try {
            /** @var mixed $manifest */
            $manifest = json_decode($manifestJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The vendored CLI output-schema manifest is invalid.', 0, $exception);
        }

        if (
            ! is_array($manifest)
            || ($manifest['schema'] ?? null) !== 'durable-workflow.cli.output-schema-manifest'
            || ($manifest['version'] ?? null) !== 3
            || ($manifest['artifact_id'] ?? null) !== self::CLI_MANIFEST_ARTIFACT_ID
            || ($manifest['resolver_url'] ?? null) !== $manifestUrl
            || ! is_array($manifest['commands'] ?? null)
            || $manifest['commands'] === []
            || ! is_array($manifest['jsonl_commands'] ?? null)
            || $manifest['jsonl_commands'] === []
        ) {
            throw new RuntimeException('The vendored CLI output-schema manifest identity is invalid.');
        }

        $expectedClosureUrls = [
            $manifestUrl => true,
        ];
        foreach (self::cliManifestMappings($manifest) as $mapping) {
            $command = $mapping['command'];
            $entry = $mapping['entry'];

            $schemaPath = $entry['schema'] ?? null;
            if (
                ! is_string($schemaPath)
                || preg_match('/\Aschemas\/output\/([a-z0-9.-]+\.schema\.json)\z/D', $schemaPath, $matches) !== 1
            ) {
                throw new RuntimeException(
                    "CLI output-schema manifest command [{$command}] has an invalid bundled schema path."
                );
            }

            $filename = $matches[1];
            $resolverUrl = self::CLI_FIXTURE_BASE_URL . '/schemas/' . $filename;
            if (
                ($entry['schema_id'] ?? null) !== $resolverUrl
                || ($entry['resolver_url'] ?? null) !== $resolverUrl
            ) {
                throw new RuntimeException(
                    "CLI output-schema manifest command [{$command}] has an invalid public schema identity."
                );
            }

            $source = $sourcesByUrl[$resolverUrl] ?? null;
            $expectedArtifactId = self::CLI_SCHEMA_ARTIFACT_PREFIX . $filename . self::CLI_SCHEMA_ARTIFACT_SUFFIX;
            if (
                ! is_array($source)
                || ($source['artifact_id'] ?? null) !== $expectedArtifactId
                || ($source['sha256'] ?? null) !== ($entry['sha256'] ?? null)
            ) {
                throw new RuntimeException(
                    "CLI output-schema manifest command [{$command}] is missing its suite byte binding."
                );
            }

            $publishedSchemaPath = self::localFixtureSourcePath($expectedArtifactId, $resolverUrl);
            $publishedSchemaJson = file_get_contents($publishedSchemaPath);
            if ($publishedSchemaJson === false) {
                throw new RuntimeException("Vendored CLI output schema [{$filename}] is missing.");
            }

            try {
                /** @var mixed $schema */
                $schema = json_decode($publishedSchemaJson, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException("Vendored CLI output schema [{$filename}] is invalid.", 0, $exception);
            }

            if (
                ! is_array($schema)
                || ($schema['$schema'] ?? null) !== 'https://json-schema.org/draft/2020-12/schema'
                || ($schema['$id'] ?? null) !== $resolverUrl
            ) {
                throw new RuntimeException("Vendored CLI output schema [{$filename}] has an invalid identity.");
            }

            if ($mapping['jsonl']) {
                self::assertCliJsonlRecordSchema($command, $entry, $schema, $sourcesByUrl);
            }

            $expectedClosureUrls[$resolverUrl] = true;
        }

        $actualClosureUrls = [];
        foreach ($sources as $source) {
            $artifactId = $source['artifact_id'] ?? null;
            if (
                $artifactId === self::CLI_MANIFEST_ARTIFACT_ID
                || (is_string($artifactId) && str_starts_with($artifactId, self::CLI_SCHEMA_ARTIFACT_PREFIX))
            ) {
                $actualClosureUrls[$source['resolver_url']] = true;
            }
        }

        if (array_keys($actualClosureUrls) !== array_keys($expectedClosureUrls)) {
            $actual = array_keys($actualClosureUrls);
            $expected = array_keys($expectedClosureUrls);
            sort($actual);
            sort($expected);
            if ($actual !== $expected) {
                throw new RuntimeException(
                    'The CLI JSON envelope suite sources do not exactly match the manifest dependency closure.'
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<array{command: string, entry: array<string, mixed>, jsonl: bool}>
     */
    private static function cliManifestMappings(array $manifest): array
    {
        $commands = $manifest['commands'];
        $jsonlCommands = $manifest['jsonl_commands'];
        $mappings = [];

        foreach ($commands as $command => $entry) {
            if (! is_string($command) || ! is_array($entry)) {
                throw new RuntimeException('The CLI output-schema manifest contains an invalid JSON command mapping.');
            }

            $mappings[] = [
                'command' => $command,
                'entry' => $entry,
                'jsonl' => false,
            ];
        }

        foreach ($jsonlCommands as $command => $entry) {
            if (
                ! is_string($command)
                || ! is_array($entry)
                || ! isset($commands[$command])
                || ($entry['output'] ?? null) !== '--output=jsonl'
                || ! is_string($entry['stream_items_from'] ?? null)
                || trim($entry['stream_items_from']) === ''
            ) {
                throw new RuntimeException('The CLI output-schema manifest contains an invalid JSONL command mapping.');
            }

            $mappings[] = [
                'command' => $command,
                'entry' => $entry,
                'jsonl' => true,
            ];
        }

        return $mappings;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $schema
     * @param  array<string, array<string, mixed>>  $sourcesByUrl
     */
    private static function assertCliJsonlRecordSchema(
        string $command,
        array $entry,
        array $schema,
        array $sourcesByUrl,
    ): void {
        $reference = $schema['$ref'] ?? null;
        if (
            ! is_string($reference)
            || preg_match(
                '/\A([a-z0-9.-]+\.schema\.json)#\/properties\/([a-z_]+)\/items\z/D',
                $reference,
                $matches,
            ) !== 1
            || ($entry['stream_items_from'] ?? null) !== $matches[2]
        ) {
            throw new RuntimeException(
                "CLI JSONL command [{$command}] does not bind its emitted envelope item schema."
            );
        }

        $referencedFilename = $matches[1];
        $referencedUrl = self::CLI_FIXTURE_BASE_URL . '/schemas/' . $referencedFilename;
        $referencedSource = $sourcesByUrl[$referencedUrl] ?? null;
        $referencedArtifactId = self::CLI_SCHEMA_ARTIFACT_PREFIX
            . $referencedFilename
            . self::CLI_SCHEMA_ARTIFACT_SUFFIX;
        if (
            ! is_array($referencedSource)
            || ($referencedSource['artifact_id'] ?? null) !== $referencedArtifactId
        ) {
            throw new RuntimeException("CLI JSONL command [{$command}] is missing its referenced envelope schema.");
        }

        $referencedPath = self::localFixtureSourcePath($referencedArtifactId, $referencedUrl);
        $referencedJson = file_get_contents($referencedPath);
        if ($referencedJson === false) {
            throw new RuntimeException("CLI JSONL command [{$command}] referenced envelope schema is missing.");
        }

        try {
            /** @var mixed $referencedSchema */
            $referencedSchema = json_decode($referencedJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                "CLI JSONL command [{$command}] referenced envelope schema is invalid.",
                0,
                $exception,
            );
        }

        $itemSchema = is_array($referencedSchema)
            ? ($referencedSchema['properties'][$matches[2]]['items'] ?? null)
            : null;
        if (! is_array($itemSchema) || ($itemSchema['type'] ?? null) !== 'object') {
            throw new RuntimeException(
                "CLI JSONL command [{$command}] referenced envelope item must describe an object."
            );
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private static function assertStableSourceReferenceClosure(array $manifest): void
    {
        $stableSources = self::stableReferenceSources($manifest);
        $boundSources = $stableSources + self::sourceDependencies($manifest);

        /** @var array<string, array<string, mixed>> $parsedDocuments */
        $parsedDocuments = [];

        foreach ($stableSources as $sourcePath => $source) {
            if (! self::isReferenceDocument($sourcePath)) {
                continue;
            }

            /** @var array<string, bool> $visited */
            $visited = [];
            self::assertDocumentReferenceClosure(
                $sourcePath,
                $source['artifact_id'],
                $boundSources,
                $visited,
                $parsedDocuments,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, array{artifact_id: string, resolver_url: string, source_path: string}>
     */
    private static function stableReferenceSources(array $manifest): array
    {
        $sources = [];

        foreach ($manifest['fixture_catalog'] as $category) {
            if (! is_array($category) || ($category['status'] ?? null) !== self::CATEGORY_STATUS_STABLE) {
                continue;
            }

            foreach ($category['sources'] as $source) {
                if (! is_array($source)) {
                    continue;
                }

                $artifactId = $source['artifact_id'];
                $resolverUrl = $source['resolver_url'];
                $sourcePath = self::relativeFixtureSourcePath($artifactId, $resolverUrl);

                if (isset($sources[$sourcePath])) {
                    throw new RuntimeException(
                        "Stable fixture source [{$artifactId}] duplicates the bound path [{$sourcePath}]."
                    );
                }

                $sources[$sourcePath] = [
                    'artifact_id' => $artifactId,
                    'resolver_url' => $resolverUrl,
                    'source_path' => $sourcePath,
                ];
            }
        }

        return $sources;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, array{artifact_id: string, resolver_url: string, source_path: string}>
     */
    private static function sourceDependencies(array $manifest): array
    {
        PlatformArtifactSourceIdentity::fromManifest($manifest);

        $declaredDependencies = $manifest['source_dependencies'] ?? null;
        if (! is_array($declaredDependencies) || $declaredDependencies === []) {
            throw new RuntimeException('Platform conformance suite source dependencies are missing.');
        }

        $dependencies = [];

        foreach ($declaredDependencies as $filename => $dependency) {
            if (
                ! is_string($filename)
                || preg_match('/\A[a-z0-9.-]+\.schema\.json\z/D', $filename) !== 1
                || ! is_array($dependency)
            ) {
                throw new RuntimeException('Platform conformance suite has an invalid source dependency.');
            }

            $artifactId = $dependency['artifact_id'] ?? null;
            if (! is_string($artifactId) || trim($artifactId) === '') {
                throw new RuntimeException(
                    "Stable source dependency [{$filename}] must declare an artifact identity."
                );
            }

            $sourceRelease = is_string($dependency['source_release'] ?? null)
                ? $dependency['source_release']
                : null;
            if (
                $filename === 'history-export-bundle.schema.json'
                && (
                    $sourceRelease === null
                    || preg_match('/\A2\.0\.0-rc\.[1-9][0-9]*\z/D', $sourceRelease) !== 1
                )
            ) {
                throw new RuntimeException(
                    'The history-export schema must declare its retained Workflow source release.'
                );
            }
            if (
                $filename === 'history-export-bundle.schema.json'
                && $artifactId !== sprintf(
                    'durable-workflow.v2.history-export-bundle@workflow-%s-schema-2',
                    $sourceRelease,
                )
            ) {
                throw new RuntimeException(
                    'The history-export schema artifact identity must derive from its retained source release.'
                );
            }

            $sourcePath = $dependency['source_path'] ?? null;
            $expectedSourcePath = self::CURRENT_PROTOCOL_SPEC_DIRECTORY . $filename;
            if (! is_string($sourcePath) || $sourcePath !== $expectedSourcePath) {
                throw new RuntimeException(
                    "Stable source dependency [{$artifactId}] must stay inside its retained protocol carrier."
                );
            }

            $resolverUrl = $dependency['resolver_url'] ?? null;
            if (! is_string($resolverUrl)) {
                throw new RuntimeException(
                    "Stable source dependency [{$artifactId}] must declare an immutable resolver URL."
                );
            }

            self::assertImmutableDependencyResolver($artifactId, $filename, $resolverUrl, $sourceRelease);

            $absolutePath = dirname(__DIR__, 3) . '/' . $sourcePath;
            if (! is_file($absolutePath)) {
                throw new RuntimeException("Vendored stable source dependency [{$artifactId}] is missing.");
            }

            $declaredDigest = $dependency['sha256'] ?? null;
            if (! is_string($declaredDigest) || preg_match('/\Asha256:[0-9a-f]{64}\z/D', $declaredDigest) !== 1) {
                throw new RuntimeException(
                    "Stable source dependency [{$artifactId}] must declare a SHA-256 byte binding."
                );
            }

            $actualDigest = hash_file('sha256', $absolutePath);
            if ($actualDigest === false || ! hash_equals($declaredDigest, 'sha256:' . $actualDigest)) {
                throw new RuntimeException(
                    "Stable source dependency [{$artifactId}] does not match its declared SHA-256 byte binding."
                );
            }

            $dependencies[$sourcePath] = [
                'artifact_id' => $artifactId,
                'resolver_url' => $resolverUrl,
                'source_path' => $sourcePath,
            ];
        }

        return $dependencies;
    }

    private static function assertImmutableDependencyResolver(
        string $artifactId,
        string $filename,
        string $resolverUrl,
        ?string $sourceRelease,
    ): void {
        $url = parse_url($resolverUrl);
        $expectedPath = '/durable-workflow/durable-workflow.github.io/' . self::PROTOCOL_SOURCE_REVISION
            . '/static/platform-protocol-specs/' . $filename;
        $retainedHistoryExportPath = '/durable-workflow/workflow/' . $sourceRelease
            . '/resources/conformance/suite-v43/platform-protocol-specs/history-export-bundle.schema.json';
        $isRetainedHistoryExport = $filename === 'history-export-bundle.schema.json'
            && is_array($url)
            && ($url['scheme'] ?? null) === 'https'
            && ($url['host'] ?? null) === 'raw.githubusercontent.com'
            && ($url['path'] ?? null) === $retainedHistoryExportPath
            && ! isset($url['user'])
            && ! isset($url['pass'])
            && ! isset($url['port'])
            && ! isset($url['query'])
            && ! isset($url['fragment']);

        if ($isRetainedHistoryExport) {
            return;
        }

        if (
            ! is_array($url)
            || ($url['scheme'] ?? null) !== 'https'
            || ($url['host'] ?? null) !== 'raw.githubusercontent.com'
            || isset($url['user'])
            || isset($url['pass'])
            || isset($url['port'])
            || isset($url['query'])
            || isset($url['fragment'])
            || ! isset($url['path'])
            || ! is_string($url['path'])
            || $url['path'] !== $expectedPath
        ) {
            throw new RuntimeException(
                "Stable source dependency [{$artifactId}] must use an immutable raw GitHub resolver."
            );
        }
    }

    /**
     * @param  array<string, array{artifact_id: string, resolver_url: string, source_path: string}>  $boundSources
     * @param  array<string, bool>  $visited
     * @param  array<string, array<string, mixed>>  $parsedDocuments
     */
    private static function assertDocumentReferenceClosure(
        string $sourcePath,
        string $artifactId,
        array $boundSources,
        array &$visited,
        array &$parsedDocuments,
    ): void {
        if (isset($visited[$sourcePath])) {
            return;
        }

        $visited[$sourcePath] = true;
        $document = self::parseReferenceDocument($sourcePath, $artifactId, $parsedDocuments);

        foreach (self::referencesIn($document, $artifactId) as $reference) {
            [$targetPath, $fragment] = self::referenceTarget($sourcePath, $artifactId, $reference, $boundSources);

            $target = $boundSources[$targetPath] ?? null;
            if ($target === null) {
                throw new RuntimeException(
                    "Stable source [{$artifactId}] references an unbound or missing local dependency [{$targetPath}]."
                );
            }

            $targetDocument = self::parseReferenceDocument($targetPath, $target['artifact_id'], $parsedDocuments);
            self::assertReferenceFragmentExists($targetDocument, $fragment, $artifactId, $reference);

            self::assertDocumentReferenceClosure(
                $targetPath,
                $target['artifact_id'],
                $boundSources,
                $visited,
                $parsedDocuments,
            );
        }
    }

    private static function isReferenceDocument(string $sourcePath): bool
    {
        return preg_match('/\.(?:schema\.json|openapi\.ya?ml|asyncapi\.ya?ml)\z/D', $sourcePath) === 1;
    }

    /**
     * @param  array<string, array<string, mixed>>  $parsedDocuments
     * @return array<string, mixed>
     */
    private static function parseReferenceDocument(
        string $sourcePath,
        string $artifactId,
        array &$parsedDocuments,
    ): array {
        if (isset($parsedDocuments[$sourcePath])) {
            return $parsedDocuments[$sourcePath];
        }

        if (! self::isReferenceDocument($sourcePath)) {
            throw new RuntimeException(
                "Stable source [{$artifactId}] references an unsupported document type [{$sourcePath}]."
            );
        }

        $absolutePath = dirname(__DIR__, 3) . '/' . $sourcePath;
        $contents = file_get_contents($absolutePath);
        if ($contents === false) {
            throw new RuntimeException("Stable source reference document [{$artifactId}] is missing.");
        }

        try {
            /** @var mixed $parsed */
            $parsed = str_ends_with($sourcePath, '.json')
                ? json_decode($contents, true, 512, JSON_THROW_ON_ERROR)
                : Yaml::parse($contents);
        } catch (JsonException|ParseException $exception) {
            throw new RuntimeException(
                "Stable source reference document [{$artifactId}] cannot be parsed.",
                0,
                $exception,
            );
        }

        if (! is_array($parsed)) {
            throw new RuntimeException("Stable source reference document [{$artifactId}] must contain an object.");
        }

        return $parsedDocuments[$sourcePath] = $parsed;
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<int, string>
     */
    private static function referencesIn(array $document, string $artifactId): array
    {
        $references = [];
        self::collectReferences($document, $artifactId, $references);

        return array_values(array_unique($references));
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  array<int, string>  $references
     */
    private static function collectReferences(array $node, string $artifactId, array &$references): void
    {
        foreach ($node as $key => $value) {
            if ($key === '$ref') {
                if (! is_string($value) || trim($value) === '') {
                    throw new RuntimeException("Stable source [{$artifactId}] contains an invalid reference.");
                }

                $references[] = $value;

                continue;
            }

            if (is_array($value)) {
                self::collectReferences($value, $artifactId, $references);
            }
        }
    }

    /**
     * @param  array<string, array{artifact_id: string, resolver_url: string, source_path: string}>  $boundSources
     * @return array{string, string}
     */
    private static function referenceTarget(
        string $sourcePath,
        string $artifactId,
        string $reference,
        array $boundSources,
    ): array {
        [$referencePath, $fragment] = array_pad(explode('#', $reference, 2), 2, '');

        if ($referencePath === '') {
            return [$sourcePath, $fragment];
        }

        $url = parse_url($referencePath);
        if (is_array($url) && isset($url['scheme'])) {
            self::assertImmutableReferenceUrl($artifactId, $referencePath);

            foreach ($boundSources as $boundSource) {
                if ($boundSource['resolver_url'] === $referencePath) {
                    return [$boundSource['source_path'], $fragment];
                }
            }

            throw new RuntimeException(
                "Stable source [{$artifactId}] references an unbound immutable source [{$referencePath}]."
            );
        }

        return [self::localReferencePath($sourcePath, $artifactId, $referencePath), $fragment];
    }

    private static function assertImmutableReferenceUrl(string $artifactId, string $referenceUrl): void
    {
        $url = parse_url($referenceUrl);

        if (
            ! is_array($url)
            || ($url['scheme'] ?? null) !== 'https'
            || ($url['host'] ?? null) !== 'raw.githubusercontent.com'
            || isset($url['user'])
            || isset($url['pass'])
            || isset($url['port'])
            || isset($url['query'])
            || ! isset($url['path'])
            || ! is_string($url['path'])
            || preg_match(
                '/\A\/durable-workflow\/[a-z0-9.-]+\/[0-9a-f]{40}\/[a-z0-9.\/-]+\z/D',
                $url['path'],
            ) !== 1
        ) {
            throw new RuntimeException(
                "Stable source [{$artifactId}] references a mutable or non-HTTPS source [{$referenceUrl}]."
            );
        }
    }

    private static function localReferencePath(
        string $sourcePath,
        string $artifactId,
        string $referencePath,
    ): string {
        $decodedPath = rawurldecode($referencePath);
        if (
            $decodedPath === ''
            || str_starts_with($decodedPath, '/')
            || str_contains($decodedPath, '\\')
            || str_contains($decodedPath, '?')
            || str_contains($decodedPath, "\0")
        ) {
            throw new RuntimeException(
                "Stable source [{$artifactId}] contains an invalid local reference [{$referencePath}]."
            );
        }

        $segments = [];
        foreach (explode('/', dirname($sourcePath) . '/' . $decodedPath) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        $targetPath = implode('/', $segments);
        $referenceDirectory = rtrim(dirname($sourcePath), '/') . '/';
        if (! str_starts_with($targetPath, $referenceDirectory)) {
            throw new RuntimeException(
                "Stable source [{$artifactId}] local reference [{$referencePath}] escapes its retained directory."
            );
        }

        return $targetPath;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private static function assertReferenceFragmentExists(
        array $document,
        string $fragment,
        string $artifactId,
        string $reference,
    ): void {
        $decodedFragment = rawurldecode($fragment);
        if ($decodedFragment === '') {
            return;
        }

        if (! str_starts_with($decodedFragment, '/')) {
            if (self::containsAnchor($document, $decodedFragment)) {
                return;
            }

            throw new RuntimeException(
                "Stable source [{$artifactId}] reference [{$reference}] contains an unresolved anchor."
            );
        }

        /** @var mixed $value */
        $value = $document;

        foreach (explode('/', substr($decodedFragment, 1)) as $encodedToken) {
            if (preg_match('/~(?:[^01]|$)/', $encodedToken) === 1) {
                throw new RuntimeException(
                    "Stable source [{$artifactId}] reference [{$reference}] contains an invalid JSON pointer."
                );
            }

            $token = str_replace(['~1', '~0'], ['/', '~'], $encodedToken);
            if (! is_array($value)) {
                throw new RuntimeException(
                    "Stable source [{$artifactId}] reference [{$reference}] contains an unresolved fragment."
                );
            }

            $key = $token;
            if (! array_key_exists($key, $value) && ctype_digit($token)) {
                $key = (int) $token;
            }

            if (! array_key_exists($key, $value)) {
                throw new RuntimeException(
                    "Stable source [{$artifactId}] reference [{$reference}] contains an unresolved fragment."
                );
            }

            $value = $value[$key];
        }
    }

    /**
     * @param  array<array-key, mixed>  $node
     */
    private static function containsAnchor(array $node, string $anchor): bool
    {
        foreach ($node as $key => $value) {
            if (($key === '$anchor' || $key === '$dynamicAnchor') && $value === $anchor) {
                return true;
            }

            if (is_array($value) && self::containsAnchor($value, $anchor)) {
                return true;
            }
        }

        return false;
    }

    private static function relativeFixtureSourcePath(string $artifactId, string $resolverUrl): string
    {
        $url = parse_url($resolverUrl);
        $runtimePrefix = '/durable-workflow/workflow/' . self::RUNTIME_SOURCE_REVISION
            . '/' . self::RUNTIME_SOURCE_DIRECTORY;
        $protocolPrefix = '/durable-workflow/durable-workflow.github.io/' . self::PROTOCOL_SOURCE_REVISION
            . '/static/platform-protocol-specs/';
        $currentWorkerProtocolPrefix = '/platform-protocol-specs/v1.19/';

        if (
            ! is_array($url)
            || ($url['scheme'] ?? null) !== 'https'
            || isset($url['user'])
            || isset($url['pass'])
            || isset($url['port'])
            || isset($url['query'])
            || isset($url['fragment'])
            || ! isset($url['path'])
            || ! is_string($url['path'])
        ) {
            throw new RuntimeException(
                "Stable fixture source [{$artifactId}] must use an immutable HTTPS resolver."
            );
        }

        if (
            ($url['host'] ?? null) === 'raw.githubusercontent.com'
            && str_starts_with($url['path'], $runtimePrefix)
        ) {
            $filename = substr($url['path'], strlen($runtimePrefix));
            $relativePath = self::RUNTIME_SOURCE_DIRECTORY . $filename;
        } elseif (
            ($url['host'] ?? null) === 'raw.githubusercontent.com'
            && str_starts_with($url['path'], $protocolPrefix)
        ) {
            $filename = substr($url['path'], strlen($protocolPrefix));
            $relativePath = self::CURRENT_PROTOCOL_SPEC_DIRECTORY . $filename;
        } elseif (
            ($url['host'] ?? null) === 'durable-workflow.github.io'
            && str_starts_with($url['path'], $currentWorkerProtocolPrefix)
        ) {
            $filename = substr($url['path'], strlen($currentWorkerProtocolPrefix));
            $relativePath = self::CURRENT_PROTOCOL_SPEC_DIRECTORY . $filename;
        } elseif (
            ($url['host'] ?? null) === 'durable-workflow.github.io'
            && str_starts_with($url['path'], '/cli-json-envelopes/v3/')
        ) {
            $filename = substr($url['path'], strlen('/cli-json-envelopes/v3/'));
            $relativePath = self::CLI_FIXTURE_DIRECTORY . $filename;

            if (
                $filename !== 'manifest.json'
                && preg_match('/\Aschemas\/[a-z0-9.-]+\.schema\.json\z/D', $filename) !== 1
            ) {
                throw new RuntimeException(
                    "Stable CLI fixture source [{$artifactId}] has an invalid retained path."
                );
            }

            return $relativePath;
        } else {
            throw new RuntimeException(
                "Stable fixture source [{$artifactId}] must use the declared runtime, protocol, or CLI carrier."
            );
        }

        if (
            preg_match('/\A[a-z0-9.-]+\.(?:json|ya?ml)\z/D', $filename) !== 1
            || preg_match(
                '/\Aresources\/conformance\/suite-v(?:38|47)\/platform-(?:conformance|protocol-specs)\/'
                    . '[a-z0-9.-]+\.(?:json|ya?ml)\z/D',
                $relativePath,
            ) !== 1
        ) {
            throw new RuntimeException(
                "Stable fixture source [{$artifactId}] must resolve to an approved retained JSON or YAML byte."
            );
        }

        return $relativePath;
    }
}
