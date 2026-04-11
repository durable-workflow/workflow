<?php

declare(strict_types=1);

namespace Workflow\V2;

use Illuminate\Http\Request;

final class CommandContext
{
    /**
     * @param array<string, mixed> $context
     */
    private function __construct(
        private readonly string $source,
        private readonly array $context,
    ) {
    }

    public static function phpApi(): self
    {
        return new self('php', [
            'caller' => [
                'type' => 'php',
                'label' => 'PHP API',
            ],
            'auth' => [
                'status' => 'not_applicable',
                'method' => 'none',
            ],
        ]);
    }

    public static function webhook(Request $request, string $authMethod): self
    {
        return new self('webhook', [
            'caller' => [
                'type' => 'webhook',
                'label' => 'Webhook',
            ],
            'auth' => [
                'status' => $authMethod === 'none' ? 'not_configured' : 'authorized',
                'method' => $authMethod,
            ],
            'request' => self::requestMetadata($request),
        ]);
    }

    public static function waterline(Request $request): self
    {
        return new self('waterline', [
            'caller' => [
                'type' => 'waterline',
                'label' => 'Waterline UI',
            ],
            'auth' => [
                'status' => 'authorized',
                'method' => 'waterline',
            ],
            'request' => self::requestMetadata($request),
        ]);
    }

    public static function workflow(
        string $parentInstanceId,
        string $parentRunId,
        int $sequence,
        ?string $childCallId = null,
    ): self {
        return new self('workflow', [
            'caller' => [
                'type' => 'workflow',
                'label' => 'Workflow',
            ],
            'auth' => [
                'status' => 'not_applicable',
                'method' => 'none',
            ],
            'workflow' => array_filter([
                'parent_instance_id' => $parentInstanceId,
                'parent_run_id' => $parentRunId,
                'sequence' => $sequence,
                'child_call_id' => $childCallId,
            ], static fn (mixed $value): bool => $value !== null),
        ]);
    }

    /**
     * @return array{source: string, context: array<string, mixed>}
     */
    public function attributes(): array
    {
        return [
            'source' => $this->source,
            'context' => $this->context,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function requestMetadata(Request $request): array
    {
        $path = '/' . ltrim($request->path(), '/');
        $headers = array_filter([
            'x_request_id' => self::headerValue($request, 'X-Request-Id'),
            'x_correlation_id' => self::headerValue($request, 'X-Correlation-Id'),
        ], static fn ($value): bool => is_string($value) && $value !== '');

        $payload = self::normalize($request->all());
        $fingerprintPayload = [
            'method' => $request->method(),
            'path' => $path,
            'payload' => $payload,
            'headers' => $headers,
        ];

        $encodedFingerprintPayload = json_encode(
            $fingerprintPayload,
            JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        return array_filter([
            'method' => $request->method(),
            'path' => $path,
            'route_name' => $request->route()?->getName(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'request_id' => $headers['x_request_id'] ?? null,
            'correlation_id' => $headers['x_correlation_id'] ?? null,
            'fingerprint' => $encodedFingerprintPayload === false
                ? null
                : 'sha256:' . hash('sha256', $encodedFingerprintPayload),
            'headers' => $headers === [] ? null : $headers,
        ], static fn ($value): bool => $value !== null && $value !== '' && $value !== []);
    }

    private static function headerValue(Request $request, string $name): ?string
    {
        $value = $request->headers->get($name);

        return is_string($value) && $value !== ''
            ? $value
            : null;
    }

    private static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(static fn ($item) => self::normalize($item), $value);
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = self::normalize($item);
        }

        return $value;
    }
}
