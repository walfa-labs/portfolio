{!! '<'.'?xml version="1.0" encoding="UTF-8"?>'."\n" !!}<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach ($urls as $url)
    <url>
        <loc>{{ $url['loc'] }}</loc>
@if ($url['lastmod'] !== null)
        <lastmod>{{ $url['lastmod'] }}</lastmod>
@endif
        <priority>{{ $url['priority'] }}</priority>
    </url>
@endforeach
</urlset>
{{-- Rendered by Public\SitemapController (§3.9). No layout: the response is XML. --}}
{{--
    The prolog above is assembled from `'<'.'?…'` rather than written literally, and it
    has to be. Blade tokenizes a template with `token_get_all()` and only compiles the
    `T_INLINE_HTML` tokens (`BladeCompiler::parseToken()`), so a literal `<?` starts a
    PHP token whenever `short_open_tag` is on — which it is under FrankenPHP. That split
    the line in two, the `{!! … !!}` pair was never seen whole, and the resulting bare
    `<?xml` prolog failed to parse as PHP ("syntax error, unexpected identifier
    version"). Only `<?` is special to the lexer; the `?>` inside the string is fine.
--}}
