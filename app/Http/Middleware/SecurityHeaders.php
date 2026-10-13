<?php

namespace App\Http\Middleware;

use App\Support\Privacy\PrivacyState;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * The security headers, including the report-only CSP (§8.6).
 *
 * Registered **globally** so `/api/v1`, `/up` and error responses carry them too.
 * `Vite::useCspNonce()` runs on the way *in*, before `$next`, because the Blade
 * shell needs the nonce to render `@vite(...)` and its inline tags; the headers
 * are set on the way *out*, after `$next`, because the CSP variant comes from the
 * privacy state the `web` group resolves downstream of here. On a route that
 * never resolves a state the strict variant is used.
 *
 * The CSP ships report-only with **no** `report-uri`/`Report-To` (decision 12):
 * the sink endpoint is deferred, and pointing a browser at a URL that does not
 * exist would only produce 404s and console noise.
 */
class SecurityHeaders
{
    /**
     * Clarity's own CSP requirement (§8.4 item 5).
     */
    private const CLARITY_HOSTS = 'https://www.clarity.ms https://*.clarity.ms https://c.bing.com';

    /**
     * AdSense's origins (§8.5).
     */
    private const ADS_HOSTS = 'https://pagead2.googlesyndication.com https://*.googlesyndication.com https://*.doubleclick.net https://*.google.com https://*.googleadservices.com';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $state = $request->attributes->get(ResolvePrivacyState::ATTRIBUTE);

        foreach ($this->headers($request, $nonce, $state) as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }

    /**
     * The header set §8.6 names.
     *
     * @return array<string, string>
     */
    private function headers(Request $request, string $nonce, mixed $state): array
    {
        $headers = [
            'Content-Security-Policy-Report-Only' => $this->contentSecurityPolicy($nonce, $state),
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Frame-Options' => 'DENY',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Cross-Origin-Opener-Policy' => 'same-origin-allow-popups',
            'Permissions-Policy' => $this->permissionsPolicy(),
        ];

        /*
         * Never hardcoded: on plain HTTP it would lock returning browsers out of
         * localhost, and there is no deployment in this run (§8.6).
         */
        if ($request->secure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        return $headers;
    }

    /**
     * Build the report-only CSP for this request's variant (§8.6).
     *
     * Variants B and C add the §8.6 hosts to `script-src`, `connect-src` and
     * `img-src`; the strict variant is used whenever no state was resolved.
     */
    private function contentSecurityPolicy(string $nonce, mixed $state): string
    {
        $variant = $state instanceof PrivacyState ? $state->cspVariant() : 'A';

        $hosts = match ($variant) {
            'C' => self::CLARITY_HOSTS.' '.self::ADS_HOSTS,
            'B' => self::CLARITY_HOSTS,
            default => '',
        };

        $suffix = $hosts === '' ? '' : ' '.$hosts;

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' 'strict-dynamic'{$suffix}",
            "style-src 'self' 'nonce-{$nonce}'",
            "font-src 'self' data:",
            "img-src 'self' data:{$suffix}",
            "connect-src 'self'{$suffix}",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }

    /**
     * The `Permissions-Policy` §8.6 pins.
     *
     * `publickey-credentials-get=(self)` is mandatory: the project ships passkeys,
     * and a deny-by-default `publickey-credentials-get=()` would break WebAuthn.
     */
    private function permissionsPolicy(): string
    {
        return implode(', ', [
            'camera=()',
            'microphone=()',
            'geolocation=()',
            'payment=()',
            'usb=()',
            'browsing-topics=()',
            'attribution-reporting=()',
            'publickey-credentials-get=(self)',
        ]);
    }
}
