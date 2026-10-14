<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\RequestMetrics;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class QualificationContext
{
    public function handle(Request $request, Closure $next)
    {
        $identity = config('qualification.users')[$request->bearerToken() ?? ''] ?? null;
        abort_unless(is_array($identity), 401);
        $requestId = $request->header('X-Request-Id', 'unspecified');
        abort_unless(is_string($requestId) && preg_match('/^[a-zA-Z0-9_-]{1,80}$/', $requestId) === 1, 422);
        $request->attributes->set('qualification_identity', $identity);
        $metrics = app(RequestMetrics::class);
        $metrics->reset();
        $context = [
            'request_id' => $requestId,
            'user' => $identity['user'],
            'namespace' => $identity['namespace'],
            'optional_context' => $request->header('X-Optional-Context'),
        ];
        Log::withContext($context);
        $started = hrtime(true);
        $status = 500;

        try {
            $response = $next($request);
            $status = $response->getStatusCode();
            $response->headers->add([
                'X-Request-Id' => $requestId,
                'X-Worker-Pid' => (string) getmypid(),
                'X-Qualification-User' => $identity['user'],
                'X-Qualification-Namespace' => $identity['namespace'],
                'X-DB-Queries' => (string) $metrics->queries,
                'X-History-Queries' => (string) $metrics->historyQueries,
                'X-DB-Connections' => implode(',', array_keys($metrics->connections)),
                'X-Optional-Context' => (string) ($context['optional_context'] ?? ''),
            ]);

            return $response;
        } finally {
            Log::info('qualification.request', [
                'pid' => getmypid(), 'path' => $request->path(), 'status' => $status,
                'elapsed_ms' => (hrtime(true) - $started) / 1e6,
                'queries' => $metrics->queries, 'history_queries' => $metrics->historyQueries,
                'connections' => array_keys($metrics->connections),
            ]);
            Log::withoutContext();
        }
    }
}
