<?php

declare(strict_types=1);

namespace Workflow\Auth;

use Illuminate\Http\Request;

class TokenAuthenticator implements WebhookAuthenticator
{
    public function validate(Request $request): Request
    {
        $expected = config('workflows.webhook_auth.token.token');

        // Token auth with no token configured must refuse every request: an
        // empty expected value would otherwise match a request without the
        // header.
        if (! is_string($expected) || $expected === '') {
            abort(503, 'Webhook token authentication is not configured.');
        }

        if (! hash_equals($expected, (string) $request->header(config('workflows.webhook_auth.token.header')))) {
            abort(401, 'Unauthorized');
        }

        return $request;
    }
}
