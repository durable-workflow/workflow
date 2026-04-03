<?php

declare(strict_types=1);

namespace Workflow\Traits;

use Illuminate\Database\QueryException;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;
use Workflow\Exceptions\VersionNotSupportedException;
use Workflow\Serializers\Serializer;

trait Versions
{
    public static function getVersion(
        string $changeId,
        int $minSupported = self::DEFAULT_VERSION,
        int $maxSupported = 1
    ): PromiseInterface {
        $log = self::$context->storedWorkflow->findLogByIndex(self::$context->index);

        if ($log) {
            $version = Serializer::unserialize($log->result);

            if ($version < $minSupported || $version > $maxSupported) {
                throw new VersionNotSupportedException(
                    "Version {$version} for change ID '{$changeId}' is not supported. " .
                    "Supported range: [{$minSupported}, {$maxSupported}]"
                );
            }

            ++self::$context->index;
            return resolve($version);
        }

        if (self::isProbing()) {
            ++self::$context->index;
            return (new \React\Promise\Deferred())->promise();
        }

        $version = $maxSupported;

        if (! self::$context->replaying) {
            try {
                self::$context->storedWorkflow->createLog([
                    'index' => self::$context->index,
                    'now' => self::$context->now,
                    'class' => 'version:' . $changeId,
                    'result' => Serializer::serialize($version),
                ]);
            } catch (QueryException $exception) {
                $log = self::$context->storedWorkflow->findLogByIndex(self::$context->index, true);

                if ($log) {
                    $version = Serializer::unserialize($log->result);

                    if ($version < $minSupported || $version > $maxSupported) {
                        throw new VersionNotSupportedException(
                            "Version {$version} for change ID '{$changeId}' is not supported. " .
                            "Supported range: [{$minSupported}, {$maxSupported}]"
                        );
                    }

                    ++self::$context->index;
                    return resolve($version);
                }

                throw $exception;
            }
        }

        ++self::$context->index;
        return resolve($version);
    }
}
