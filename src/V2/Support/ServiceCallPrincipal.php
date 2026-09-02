<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

/**
 * Principal recorded as the actor on a service-boundary decision.
 *
 * The boundary stores the caller's authenticated identity directly on
 * the audit row so accepted *and* rejected calls share the same
 * audit shape. This object is intentionally narrow — only the fields
 * that survive into the durable audit row — so it can be reconstructed
 * cleanly from server-side `Principal`, from worker-supplied identity
 * headers, or from a synthetic "system" caller.
 *
 * Privacy boundary: this DTO carries identity fields (subject, method,
 * roles, tenant, optional opaque claims). Payload material — request
 * arguments, results, failures — is not part of the principal and is
 * never copied into the audit row by the boundary; payload privacy
 * stays under the codec / data-converter trust boundaries already
 * enforced by the workflow engine.
 *
 * @api stable contract surface consumed by both workflow and server.
 */
final class ServiceCallPrincipal
{
    /**
     * @param list<string> $roles
     * @param array<string, mixed> $claims
     */
    public function __construct(
        public readonly string $subject,
        public readonly string $method = 'none',
        public readonly array $roles = [],
        public readonly ?string $tenant = null,
        public readonly array $claims = [],
    ) {
    }

    public static function system(string $subject = 'system'): self
    {
        return new self(subject: $subject, method: 'system');
    }

    /**
     * @return array{subject: string, method: string, roles: list<string>, tenant: string|null, claims: array<string, mixed>}
     */
    public function toAuditArray(): array
    {
        return [
            'subject' => $this->subject,
            'method' => $this->method,
            'roles' => array_values($this->roles),
            'tenant' => $this->tenant,
            'claims' => $this->claims,
        ];
    }

    /**
     * @param array{subject?: string|null, method?: string|null, roles?: list<string>|null, tenant?: string|null, claims?: array<string, mixed>|null} $audit
     */
    public static function fromAuditArray(array $audit): self
    {
        return new self(
            subject: (string) ($audit['subject'] ?? 'unknown'),
            method: (string) ($audit['method'] ?? 'none'),
            roles: array_values(array_filter(
                $audit['roles'] ?? [],
                static fn (mixed $role): bool => is_string($role) && $role !== '',
            )),
            tenant: isset($audit['tenant']) ? (string) $audit['tenant'] : null,
            claims: is_array($audit['claims'] ?? null) ? $audit['claims'] : [],
        );
    }
}
