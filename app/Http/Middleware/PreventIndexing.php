<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keep the authenticated surfaces out of search engines (§5.11).
 *
 * The response header is the authoritative layer, because a header cannot be
 * dropped by client-side rendering the way a `<meta>` tag can; the per-page
 * `<meta name="robots">` and `/robots.txt` are belt-and-braces behind it.
 *
 * Registered on the whole `web` group rather than on the `/admin` route group,
 * so `/settings/*` is covered by the same rule — desirable, and impossible if
 * the middleware were scoped to the admin group.
 *
 * `/search` is deliberately not in scope: §3.9 pins its header to the two
 * directives `noindex, nofollow` and `Public\SearchController` sets it itself.
 * Overwriting it here would silently widen a value the PRD specifies verbatim.
 */
class PreventIndexing
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->is('admin', 'admin/*', 'settings', 'settings/*')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        }

        return $response;
    }
}
