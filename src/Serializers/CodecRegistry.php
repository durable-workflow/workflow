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
 *   - "json"                    — language-neutral JSON (default for new workflows)
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
     * Defaults to "json" for new deployments; existing deployments that pin
     * the PHP codec via config keep the legacy behavior.
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

        return 'json';
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::CODECS);
    }
}
