<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use InvalidArgumentException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/** Original request graph only; this portable data grants no application authority. */
final readonly class FinancialDescriptor
{
    private function __construct(
        public Request $authorize,
        public Request $capture,
        public Request $void,
        public Request $refund,
    ) {
    }

    public static function fromArray(array $input): self
    {
        $keys = array_keys($input);
        sort($keys);
        if ($keys !== ['authorize', 'capture', 'refund', 'void']) {
            throw new InvalidArgumentException('The financial descriptor has unexpected or missing fields.');
        }
        $requests = [];
        foreach ($input as $key => $value) {
            if (! is_array($value)) {
                throw new InvalidArgumentException('The financial descriptor must contain four original requests.');
            }
            $requests[$key] = Request::fromArray($value);
        }
        $authorization = $requests['authorize'];
        $terms = AuthorizationTerms::fromArray($authorization->terms);
        $actions = [
            'authorize' => 'authorize',
            'capture' => 'capture-authorized',
            'void' => 'void-authorization',
            'refund' => 'refund-authorized',
        ];
        foreach ($requests as $key => $request) {
            $target = match ($key) {
                'authorize' => null,
                'capture', 'void' => $authorization->identity->toArray(),
                'refund' => $requests['capture']->identity->toArray(),
            };
            $expected = $key === 'refund' ? $terms->captureTerms() : $terms->toArray();
            if ($request->action !== $actions[$key] || $request->target?->toArray() !== $target
                || ! $request->identity->sameOwner($authorization->identity)
                || $request->terms !== PortableDescriptor::snapshot($expected)) {
                throw new InvalidArgumentException(
                    'Financial requests must preserve the original owner, target and terms.'
                );
            }
        }
        if (count(array_unique(array_map(static fn (Request $r): string => $r->identity->key(), $requests))) !== 4) {
            throw new InvalidArgumentException(
                'Each original financial operation requires a distinct idempotency identity.'
            );
        }
        return new self($requests['authorize'], $requests['capture'], $requests['void'], $requests['refund']);
    }

    /**
     * @return array{authorize: Request, capture: Request, void: Request, refund: Request}
     */
    public function requests(): array
    {
        return [
            'authorize' => $this->authorize,
            'capture' => $this->capture,
            'void' => $this->void,
            'refund' => $this->refund,
        ];
    }

    public function toArray(): array
    {
        return array_map(static fn (Request $request): array => $request->toArray(), $this->requests());
    }

    public function fingerprint(): string
    {
        return hash('sha256', PortableDescriptor::encode($this->toArray()));
    }
}
