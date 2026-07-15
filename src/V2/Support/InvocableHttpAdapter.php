<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use JsonException;
use Workflow\V2\Contracts\ExternalPayloadStorageDriver;

/**
 * Framework-agnostic HTTP adapter for invocable activity carriers.
 *
 * Accepts a raw request body, delegates to InvocableActivityHandler, and
 * returns a structured HTTP response array. The carrier stays activities-only:
 * workflow-task inputs are rejected by the underlying handler.
 *
 * Response shape:
 *   [
 *     'status'  => int,
 *     'headers' => array<string, string>,
 *     'body'    => string,
 *   ]
 *
 * Success (HTTP 200): body is the external-task result envelope JSON with
 * Content-Type application/vnd.durable-workflow.external-task-result+json.
 *
 * Bad request (HTTP 400): body is a JSON error object; returned when the
 * request body cannot be parsed into a JSON object so no durable task
 * identity can be recovered for a structured result envelope.
 */
final class InvocableHttpAdapter
{
    public const RESULT_MEDIA_TYPE = 'application/vnd.durable-workflow.external-task-result+json';

    public const ERROR_MEDIA_TYPE = 'application/json';

    /**
     * @param  array<string, callable>  $handlers
     */
    public function __construct(
        private readonly array $handlers,
        private readonly string $carrier = 'php-invocable-http',
        private readonly string $resultCodec = 'avro',
        private readonly ?ExternalPayloadStorageDriver $externalStorage = null,
    ) {
    }

    /**
     * Handle a raw HTTP request body and return a structured HTTP response.
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function handle(string $rawBody): array
    {
        try {
            $envelope = json_decode($rawBody, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return $this->badRequest(
                'invalid_invocable_request',
                'Request body is not valid JSON: ' . $e->getMessage()
            );
        }

        if (! is_array($envelope) || array_is_list($envelope)) {
            return $this->badRequest('invalid_invocable_request', 'Request body must decode to a JSON object.');
        }

        $handler = new InvocableActivityHandler(
            $this->handlers,
            carrier: $this->carrier,
            resultCodec: $this->resultCodec,
            externalStorage: $this->externalStorage,
        );

        try {
            $result = $handler->handle($envelope);
        } catch (\Throwable $e) {
            return $this->badRequest('invalid_invocable_request', $e->getMessage());
        }

        return [
            'status' => 200,
            'headers' => [
                'Content-Type' => self::RESULT_MEDIA_TYPE,
            ],
            'body' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function badRequest(string $error, string $message): array
    {
        return [
            'status' => 400,
            'headers' => [
                'Content-Type' => self::ERROR_MEDIA_TYPE,
            ],
            'body' => json_encode([
                'error' => $error,
                'message' => $message,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ];
    }
}
