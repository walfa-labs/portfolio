<?php

namespace App\Http\Middleware;

use App\Support\Privacy\PrivacyState;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Vite;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve the per-visitor privacy state before anything renders (§8.2).
 *
 * Appended to the `web` group immediately before `HandleInertiaRequests`, so the
 * `privacy` prop is part of the shared set and the Blade shell can read the same
 * state through `View::share` (the `HandleAppearance` precedent). The state is
 * also stashed on the request, because the global `SecurityHeaders` runs
 * *outside* the web group and can only read it on the way back out.
 *
 * The `consent` cookie is client-written and therefore untrusted: it is only ever
 * used to pick a CSP variant and SSR the Clarity tag, never for authorisation.
 * Tampering only affects the tamperer's own tracking state.
 */
class ResolvePrivacyState
{
    /**
     * The request attribute the resolved state is stashed under.
     */
    public const ATTRIBUTE = 'privacy';

    /**
     * Header values that mean "object" (§8.2, decision 10).
     *
     * `1` and `01` are the forms the Tracking Preference Expression and the GPC
     * spec use; `yes` and `on` are the legacy forms older user agents send.
     *
     * @var list<string>
     */
    private const OPT_OUT_VALUES = ['1', '01', 'yes', 'on'];

    /**
     * The decision values the banner may store (§8.3, decision 6).
     *
     * @var list<string>
     */
    private const DECISIONS = ['accept_all', 'reject_all', 'custom'];

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $state = $this->resolve($request);

        $request->attributes->set(self::ATTRIBUTE, $state);

        Inertia::share('privacy', $state);
        View::share('privacy', $state);

        return $next($request);
    }

    /**
     * Build the state for this request.
     */
    private function resolve(Request $request): PrivacyState
    {
        $optOut = $this->detectsOptOut($request);

        $consent = $this->consent($request, $optOut);

        /*
         * The full conjunction (decision 1). §8.4 item 1 gates the Blade include
         * on `analyticsAllowed` alone, so a state that ignored the kill switch or
         * the `/admin` rule would load Clarity where it must never load. Clarity
         * shows real personal data on `/admin` and has no analytics value there.
         */
        $analyticsAllowed = (bool) config('consent.analytics_enabled')
            && ! $optOut
            && ($consent['analytics'] ?? false) === true
            && ! $request->is('admin*');

        /*
         * Ads are `writing.show` only (§8.5 item 1) and require advertising
         * consent — never merely analytics consent (decision 2).
         */
        $adsAllowed = (bool) config('consent.ads_enabled')
            && ! $optOut
            && ($consent['advertising'] ?? false) === true
            && $request->routeIs('writing.show');

        return new PrivacyState(
            optOut: $optOut,
            analyticsAllowed: $analyticsAllowed,
            adsAllowed: $adsAllowed,
            consent: $consent,
            cspNonce: (string) Vite::cspNonce(),
            clarityProjectId: $this->stringConfig('consent.clarity_project_id'),
            adsenseClient: $this->stringConfig('consent.adsense_client'),
            adsenseSlot: $this->stringConfig('consent.adsense_slot'),
        );
    }

    /**
     * Whether the request carries an opt-out signal (§8.2, decision 10).
     *
     * The server only reads the headers; the client unions them with
     * `navigator.doNotTrack` / `navigator.globalPrivacyControl` and is
     * authoritative for gating, because a proxy or extension can strip a header
     * but cannot change what the page's own JavaScript observes.
     */
    private function detectsOptOut(Request $request): bool
    {
        return in_array($this->header($request, 'Sec-GPC'), self::OPT_OUT_VALUES, true)
            || in_array($this->header($request, 'DNT'), self::OPT_OUT_VALUES, true);
    }

    /**
     * Read a header as a lower-cased, trimmed string.
     */
    private function header(Request $request, string $name): string
    {
        $value = $request->header($name);

        return is_string($value) ? strtolower(trim($value)) : '';
    }

    /**
     * Read, validate and version-check the `consent` cookie (§8.3).
     *
     * A record that is malformed, stale, or contradicted by an opt-out signal is
     * **deleted** rather than left in place: the client reads the same cookie
     * directly, so leaving it would let it be honoured there (decision 20). A
     * stored accept never overrides an opt-out signal (§8.2).
     *
     * @return array{v: int, ts: string|null, analytics: bool, advertising: bool, decision: string}|null
     */
    private function consent(Request $request, bool $optOut): ?array
    {
        $raw = $request->cookie('consent');

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        /*
         * PHP has already URL-decoded a real request's cookie, but a test may set
         * the value verbatim; the client writes `encodeURIComponent(JSON)`, so the
         * fallback covers a request that reaches us still encoded.
         */
        $record = json_decode($raw, true);

        if (! is_array($record)) {
            $record = json_decode(urldecode($raw), true);
        }

        if (! is_array($record) || ! $this->isCurrent($record)) {
            Cookie::queue(Cookie::forget('consent'));

            return null;
        }

        if ($optOut) {
            Cookie::queue(Cookie::forget('consent'));

            return null;
        }

        $decision = $record['decision'] ?? null;

        return [
            'v' => (int) $record['v'],
            'ts' => is_string($record['ts'] ?? null) ? $record['ts'] : null,
            'analytics' => (bool) ($record['analytics'] ?? false),
            'advertising' => (bool) ($record['advertising'] ?? false),
            'decision' => is_string($decision) && in_array($decision, self::DECISIONS, true) ? $decision : 'custom',
        ];
    }

    /**
     * Whether a stored record is well-formed and keyed to the live policy version.
     *
     * Both category flags must be real booleans: a truthy string would otherwise
     * grant consent to a tampered record, and this gate fails closed.
     *
     * @param  array<array-key, mixed>  $record
     */
    private function isCurrent(array $record): bool
    {
        return (int) ($record['v'] ?? 0) === $this->policyVersion()
            && is_bool($record['analytics'] ?? null)
            && is_bool($record['advertising'] ?? null);
    }

    /**
     * The policy version a stored record must match (§8.3).
     */
    private function policyVersion(): int
    {
        return max(1, (int) config('consent.policy_version', 1));
    }

    /**
     * Read a configured value that must be a non-empty string, or null.
     */
    private function stringConfig(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
