<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/**
 * `robots.txt` (§3.9).
 *
 * `public/robots.txt` is deleted so this route can emit an absolute `Sitemap:`
 * line from `config('app.url')` — a static file has no idea what origin it is
 * being served from. The body is cached for an hour.
 */
class RobotsController extends Controller
{
    /**
     * Render the crawler directives.
     */
    public function __invoke(): Response
    {
        return response($this->body(), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /**
     * Build the document.
     *
     * While the site is not indexable the body collapses to a single `Disallow: /`
     * — advertising a sitemap we have told crawlers not to read would be
     * incoherent (§18). The `Sitemap:` line stays either way, because the sitemap
     * route is still the canonical location for the document.
     */
    private function body(): string
    {
        $lines = ['User-agent: *'];

        if (config('app.indexable')) {
            $lines = [...$lines,
                'Disallow: /admin',
                'Disallow: /settings',
                'Disallow: /api/',
                'Disallow: /search',
                'Disallow: /*?q=',
            ];
        } else {
            $lines[] = 'Disallow: /';
        }

        $lines[] = 'Sitemap: '.rtrim((string) config('app.url'), '/').'/sitemap.xml';

        return implode("\n", $lines)."\n";
    }
}
