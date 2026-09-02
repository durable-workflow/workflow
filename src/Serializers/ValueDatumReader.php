<?php

declare(strict_types=1);

namespace Workflow\Serializers;

use Apache\Avro\Datum\AvroIOBinaryDecoder;
use Apache\Avro\Datum\AvroIODatumReader;
use Apache\Avro\Datum\AvroIOSchemaMatchException;
use Apache\Avro\Schema\AvroNamedSchema;
use Apache\Avro\Schema\AvroSchema;
use Apache\Avro\Schema\AvroUnionSchema;

/**
 * Narrow Apache PHP adapter for recursive named-union resolution.
 *
 * Apache Avro 1.12.1 iterates a null aliases value while testing non-matching
 * named union branches. Match the fixed Value records by fullname first, then
 * delegate all datum reads to the package implementation.
 */
final class ValueDatumReader extends AvroIODatumReader
{
    /**
     * @param AvroSchema $writersSchema
     * @param AvroSchema $readersSchema
     * @param AvroIOBinaryDecoder $decoder
     */
    public function readData($writersSchema, $readersSchema, $decoder): mixed
    {
        if (
            $readersSchema instanceof AvroUnionSchema
            && ! $writersSchema instanceof AvroUnionSchema
        ) {
            foreach ($readersSchema->schemas() as $candidate) {
                if ($this->schemasMatchWithoutAliasWarning($writersSchema, $candidate)) {
                    return parent::readData($writersSchema, $candidate, $decoder);
                }
            }

            throw new AvroIOSchemaMatchException($writersSchema, $readersSchema);
        }

        return parent::readData($writersSchema, $readersSchema, $decoder);
    }

    private function schemasMatchWithoutAliasWarning(AvroSchema $writer, AvroSchema $reader): bool
    {
        if ($writer->type() !== $reader->type()) {
            return false;
        }

        if ($writer instanceof AvroNamedSchema && $reader instanceof AvroNamedSchema) {
            if ($writer->fullname() === $reader->fullname()) {
                return true;
            }

            $aliases = $reader->getAliases();

            return is_array($aliases) && in_array($writer->fullname(), $aliases, true);
        }

        return true;
    }
}
