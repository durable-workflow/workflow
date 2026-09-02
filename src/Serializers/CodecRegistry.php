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
 * Durable Workflow 2.0 has one public codec: Avro. Legacy PHP serializers are
 * deliberately kept out of this registry and remain reachable only through
 * Serializer's untagged v1 import/drain path.
 */
final class CodecRegistry
{
    /**
     * @var array<string, class-string<SerializerInterface>>
     */
    private const CODECS = [
        'avro' => Avro::class,
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
            throw self::unsupported($codec);
        }

        return self::CODECS[$name];
    }

    /**
     * Normalize a public v2 codec name. Omission selects Avro; every explicit
     * non-Avro value fails closed.
     */
    public static function canonicalize(?string $codec): string
    {
        if ($codec === null || $codec === '') {
            return self::defaultCodec();
        }

        if ($codec === 'avro') {
            return 'avro';
        }

        throw self::unsupported($codec);
    }

    /**
     * The default codec for new v2 payloads.
     *
     * v2 is unreleased, so there is no supported v2-to-v2 codec migration
     * surface. New v2 payloads always use Avro. Explicit row/envelope codec
     * tags fail closed through {@see resolve()}. Untagged PHP v1 import/drain
     * data uses Serializer's separate internal legacy reader; deployment config
     * cannot change the new-run v2 default away from Avro.
     */
    public static function defaultCodec(): string
    {
        return 'avro';
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::CODECS);
    }

    /**
     * Language-neutral codecs that any SDK is expected to be able to decode.
     *
     * Public wire contract: only these codec names should be advertised to
     * polyglot clients on `/api/cluster/info` and equivalent public endpoints.
     *
     * @return list<string>
     */
    public static function universal(): array
    {
        return ['avro'];
    }

    private static function unsupported(?string $codec): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf(
            'unsupported_payload_codec: payload codec "%s" is not supported by Durable Workflow 2.0; use codec="avro" with the fixed Avro Value schema and single-object framing. JSON remains the HTTP document transport, not a workflow payload codec.',
            $codec ?? '',
        ));
    }
}
