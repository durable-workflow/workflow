<?php

declare(strict_types=1);

namespace Workflow\Serializers;

use InvalidArgumentException;

/**
 * Registry mapping canonical payload codec names to serializer classes.
 *
 * Codec names are part of the worker protocol wire contract — see
 * docs/configuration/worker-protocol.md. They travel alongside payload bytes
 * so any SDK can pick the right decoder without sniffing.
 *
 * Canonical names:
 *   - "avro"                    — Apache Avro binary codec (default for new workflows)
 *   - "json"                    — language-neutral JSON (v1 migration reads only, not for new v2 workflows)
 *   - "workflow-serializer-y"   — PHP SerializableClosure with byte-escape encoding (legacy)
 *   - "workflow-serializer-base64" — PHP SerializableClosure with base64 encoding (legacy)
 *
 * Legacy fully-qualified class names (e.g. "Workflow\\Serializers\\Y") are
 * accepted as aliases so rows persisted before the codec rename keep working.
 */
final class CodecRegistry
{
    /** @var array<string, class-string<SerializerInterface>> */
    private const CODECS = [
        'json' => Json::class,
        'avro' => Avro::class,
        'workflow-serializer-y' => Y::class,
        'workflow-serializer-base64' => Base64::class,
    ];

    /** @var array<string, string> legacy FQCN → canonical name */
    private const LEGACY_ALIASES = [
        Json::class => 'json',
        Y::class => 'workflow-serializer-y',
        Base64::class => 'workflow-serializer-base64',
    ];

    /**
     * Resolve a codec name (or legacy FQCN) to its serializer class.
     *
     * @return class-string<SerializerInterface>
     */
    public static function resolve(?string $codec): string
    {
        $name = self::canonicalize($codec);

        if (! isset(self::CODECS[$name])) {
            throw new InvalidArgumentException(sprintf('Unknown payload codec "%s".', $codec ?? ''));
        }

        return self::CODECS[$name];
    }

    /**
     * Normalize a codec name: accept canonical names, legacy FQCNs, and null (→ default).
     */
    public static function canonicalize(?string $codec): string
    {
        if ($codec === null || $codec === '') {
            return self::defaultCodec();
        }

        if (isset(self::CODECS[$codec])) {
            return $codec;
        }

        if (isset(self::LEGACY_ALIASES[$codec])) {
            return self::LEGACY_ALIASES[$codec];
        }

        // Tolerate leading backslashes in persisted FQCNs.
        $trimmed = ltrim($codec, '\\');
        if (isset(self::LEGACY_ALIASES[$trimmed])) {
            return self::LEGACY_ALIASES[$trimmed];
        }

        throw new InvalidArgumentException(sprintf('Unknown payload codec "%s".', $codec));
    }

    /**
     * The default codec, derived from config('workflows.serializer').
     *
     * Defaults to "avro" for new deployments. Installations that pin
     * "json" (or a legacy PHP codec) via config keep that codec for new
     * runs; legacy fully-qualified class names resolve to their canonical
     * codec names so v1 deployments keep working.
     */
    public static function defaultCodec(): string
    {
        $configured = function_exists('config') ? config('workflows.serializer') : null;

        if (is_string($configured) && $configured !== '') {
            if (isset(self::CODECS[$configured])) {
                return $configured;
            }
            $trimmed = ltrim($configured, '\\');
            if (isset(self::LEGACY_ALIASES[$trimmed])) {
                return self::LEGACY_ALIASES[$trimmed];
            }
        }

        return 'avro';
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::CODECS);
    }

    /**
     * Language-neutral codecs that any SDK is expected to be able to decode.
     *
     * Public wire contract: only these codec names should be advertised to
     * polyglot clients on `/api/cluster/info` and equivalent public endpoints.
     * PHP-specific codecs are exposed separately via {@see engineSpecific()}.
     *
     * @return list<string>
     */
    public static function universal(): array
    {
        return ['avro'];
    }

    /**
     * Codecs that require an engine-specific runtime to decode.
     *
     * Keyed by engine name so polyglot SDKs can selectively opt in to a codec
     * they know how to decode without PHP-flavored identifiers leaking into
     * the primary `payload_codecs` wire field.
     *
     * @return array<string, list<string>>
     */
    public static function engineSpecific(): array
    {
        $universal = self::universal();

        $phpOnly = array_values(array_diff(array_keys(self::CODECS), $universal));

        if ($phpOnly === []) {
            return [];
        }

        return ['php' => $phpOnly];
    }
}
