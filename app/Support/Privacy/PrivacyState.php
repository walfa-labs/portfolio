<?php

namespace App\Support\Privacy;

use JsonSerializable;

/**
 * The privacy decision resolved for a single request (§8.2).
 *
 * Immutable and per-visitor. `ResolvePrivacyState` builds one and hands the same
 * instance to the `privacy` Inertia prop, to the `$privacy` Blade variable and to
 * a request attribute, so the shell, the client and the global `SecurityHeaders`
 * middleware can never disagree about what was decided.
 *
 * @phpstan-type ConsentRecord array{v: int, ts: string|null, analytics: bool, advertising: bool, decision: string}
 */
final readonly class PrivacyState implements JsonSerializable
{
    /**
     * @param  ConsentRecord|null  $consent
     */
    public function __construct(
        public bool $optOut,
        public bool $analyticsAllowed,
        public bool $adsAllowed,
        public ?array $consent,
        public string $cspNonce,
        public ?string $clarityProjectId,
        public ?string $adsenseClient,
        public ?string $adsenseSlot,
    ) {}

    /**
     * The CSP variant this request needs (§8.6): `A` strict, `B` analytics,
     * `C` analytics + ads.
     *
     * `adsAllowed` already carries the opt-out, kill-switch, consent and route
     * gates, so a request that reaches `C` has genuinely consented to
     * advertising. §8.6's own "variant B and `routeIs('writing.show')`" would
     * have handed the Google origins to a visitor who never consented to ads
     * (decision 2).
     */
    public function cspVariant(): string
    {
        return match (true) {
            $this->adsAllowed => 'C',
            $this->analyticsAllowed => 'B',
            default => 'A',
        };
    }

    /**
     * The shape the client consumes (§8.9 item 2).
     *
     * The two vendor ids reach the browser here and nowhere else — never through
     * a `VITE_*` variable, so the kill switches work without a rebuild.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'optOut' => $this->optOut,
            'analyticsAllowed' => $this->analyticsAllowed,
            'adsAllowed' => $this->adsAllowed,
            'consent' => $this->consent,
            'cspNonce' => $this->cspNonce,
            'clarityProjectId' => $this->clarityProjectId,
            'adsenseClient' => $this->adsenseClient,
            'adsenseSlot' => $this->adsenseSlot,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
