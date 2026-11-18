<?php

declare(strict_types=1);

namespace Workflow\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SignatureAuthenticator implements WebhookAuthenticator
{
    public function validate(Request $request): Request
    {
        $secret = config('workflows.webhook_auth.signature.secret');

        // Signature auth with no secret configured refuses every request
        // instead of signing with an empty key.
        if (! is_string($secret) || $secret === '') {
            Log::warning(
                'Webhook signature authentication is selected but DW_WEBHOOKS_SECRET is not set; refusing webhook requests. Set it, or use the none method for webhooks without authentication.'
            );
            abort(503, 'Webhook signature authentication is not configured.');
        }

        if (! hash_equals(
            (string) $request->header(config('workflows.webhook_auth.signature.header')),
            hash_hmac('sha256', $request->getContent(), $secret)
        )) {
            abort(401, 'Unauthorized');
        }

        return $request;
    }
}
