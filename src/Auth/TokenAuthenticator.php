<?php

declare(strict_types=1);

namespace Workflow\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TokenAuthenticator implements WebhookAuthenticator
{
    public function validate(Request $request): Request
    {
        $expected = config('workflows.webhook_auth.token.token');

        // Token auth with no token configured must refuse every request: an
        // empty expected value would otherwise match a request without the
        // header.
        if (! is_string($expected) || $expected === '') {
            Log::warning(
                'Webhook token authentication is selected but DW_WEBHOOKS_TOKEN is not set; refusing webhook requests. Set it, or use the none method for webhooks without authentication.'
            );
            abort(503, 'Webhook token authentication is not configured.');
        }

        if (! hash_equals($expected, (string) $request->header(config('workflows.webhook_auth.token.header')))) {
            abort(401, 'Unauthorized');
        }

        return $request;
    }
}
