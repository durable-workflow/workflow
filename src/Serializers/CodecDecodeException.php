<?php

declare(strict_types=1);

namespace Workflow\Serializers;

use RuntimeException;
use Throwable;

/**
 * Thrown when a payload labelled with a specific codec cannot be decoded.
 *
 * The exception names the declared codec, describes what the decoder
 * actually saw, and includes a remediation hint so that operators looking
 * at a wire-protocol or HTTP-API failure can reach the right answer
 * without spelunking through the codec internals.
 *
 * Loud, typed ingress failures are required by the Avro release-gating
 * acceptance criteria — a JSON blob arriving under an `avro` codec tag
 * (or vice versa) must surface as a clearly attributable error instead
 * of a generic RuntimeException with binary noise.
 *
 * @see https://github.com/zorporation/durable-workflow/issues/362
 */
final class CodecDecodeException extends RuntimeException
{
    public function __construct(
        public readonly string $declaredCodec,
        public readonly string $detail,
        public readonly string $remediation,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('%s Remediation: %s', $detail, $remediation),
            0,
            $previous,
        );
    }
}
