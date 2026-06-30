<?php

declare(strict_types=1);

namespace Workflow\Traits;

use Illuminate\Database\QueryException;
use React\Promise\Deferred;
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
            // Only treat the recorded log as this change's version marker when its
            // class matches. A different class means getVersion() was added to a
            // workflow whose history was recorded before the change existed.
            // Consuming the slot here would shift every later event, so fall back to
            // the minimum supported version and leave the index untouched so the
            // original event still replays in its recorded position.
            if ($log->class !== 'version:' . $changeId) {
                return resolve($minSupported);
            }

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
            self::markProbePendingBeforeMatch();
            ++self::$context->index;
            return (new Deferred())->promise();
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
