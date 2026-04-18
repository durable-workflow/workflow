<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use RuntimeException;

/**
 * Compression envelope for history payloads in the worker protocol.
 *
 * When the history event count exceeds the compression threshold and the
 * caller accepts a supported encoding, the bridge or server may replace
 * the 'history_events' array with:
 *
 *   - 'history_events_compressed': base64-encoded compressed payload
 *   - 'history_events_encoding': the algorithm used ('gzip' or 'deflate')
 *   - 'history_events': [] (empty, to signal that events are in the compressed key)
 *
 * Callers that do not understand compression can check whether
 * 'history_events_compressed' is present and fall back to re-fetching
 * without compression.
 *
 * @api Stable class surface consumed by the standalone workflow-server.
 *      The public static method signatures on this class are covered by
 *      the workflow package's semver guarantee. See docs/api-stability.md.
 */
final class HistoryPayloadCompression
{
    /**
     * Compress a history payload array if the event count exceeds the threshold
     * and the requested encoding is supported.
     *
     * Returns the payload array unchanged if compression is not applicable.
     *
     * @param array<string, mixed> $payload  A historyPayload or historyPayloadPaginated response.
     * @param string|null $acceptEncoding    The encoding requested by the caller (e.g. 'gzip').
     * @return array<string, mixed>
     */
    public static function compress(array $payload, ?string $acceptEncoding): array
    {
        if ($acceptEncoding === null) {
            return $payload;
        }

        $encoding = self::resolveEncoding($acceptEncoding);

        if ($encoding === null) {
            return $payload;
        }

        $events = $payload['history_events'] ?? [];

        if (count($events) < WorkerProtocolVersion::COMPRESSION_THRESHOLD) {
            return $payload;
        }

        $json = json_encode($events, JSON_THROW_ON_ERROR);
        $compressed = self::encode($json, $encoding);

        $payload['history_events'] = [];
        $payload['history_events_compressed'] = base64_encode($compressed);
        $payload['history_events_encoding'] = $encoding;

        return $payload;
    }

    /**
     * Decompress a history payload that was compressed by the bridge or server.
     *
     * If the payload does not contain compressed events, it is returned unchanged.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function decompress(array $payload): array
    {
        if (! isset($payload['history_events_compressed'])) {
            return $payload;
        }

        $encoding = $payload['history_events_encoding'] ?? null;

        if ($encoding === null || ! in_array($encoding, WorkerProtocolVersion::SUPPORTED_HISTORY_ENCODINGS, true)) {
            throw new RuntimeException("Unsupported history payload encoding: {$encoding}");
        }

        $raw = base64_decode($payload['history_events_compressed'], true);

        if ($raw === false) {
            throw new RuntimeException('Failed to base64-decode compressed history payload.');
        }

        $json = self::decode($raw, $encoding);

        /** @var list<array<string, mixed>> $events */
        $events = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $payload['history_events'] = $events;
        unset($payload['history_events_compressed'], $payload['history_events_encoding']);

        return $payload;
    }

    /**
     * Check whether a payload contains compressed history events.
     */
    public static function isCompressed(array $payload): bool
    {
        return isset($payload['history_events_compressed']);
    }

    /**
     * Resolve the best supported encoding from an Accept-Encoding header value.
     *
     * Supports a simple comma-separated list (e.g. "gzip, deflate").
     * Returns null if no supported encoding is found.
     */
    public static function resolveEncoding(string $acceptEncoding): ?string
    {
        $candidates = array_map('trim', explode(',', strtolower($acceptEncoding)));

        foreach (WorkerProtocolVersion::SUPPORTED_HISTORY_ENCODINGS as $supported) {
            if (in_array($supported, $candidates, true)) {
                return $supported;
            }
        }

        return null;
    }

    /**
     * Compress a string using the given encoding.
     */
    private static function encode(string $data, string $encoding): string
    {
        $result = match ($encoding) {
            'gzip' => gzencode($data),
            'deflate' => gzdeflate($data),
            default => throw new RuntimeException("Unsupported encoding: {$encoding}"),
        };

        if ($result === false) {
            throw new RuntimeException("Failed to compress with {$encoding}.");
        }

        return $result;
    }

    /**
     * Decompress a string using the given encoding.
     */
    private static function decode(string $data, string $encoding): string
    {
        $result = match ($encoding) {
            'gzip' => gzdecode($data),
            'deflate' => gzinflate($data),
            default => throw new RuntimeException("Unsupported encoding: {$encoding}"),
        };

        if ($result === false) {
            throw new RuntimeException("Failed to decompress with {$encoding}.");
        }

        return $result;
    }
}
