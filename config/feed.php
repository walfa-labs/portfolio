<?php

use App\Models\Writing;

return [
    'feeds' => [
        /*
         * The one Atom feed the site publishes, at `/writing/feed.atom` (§3.9).
         * Atom only — no RSS or JSON variant.
         *
         * `Route::feeds()` in `routes/web.php` registers it, and must be
         * registered *before* `/writing/{writing:slug}`: both URIs are two
         * segments deep and Laravel matches routes in registration order.
         */
        'writings' => [
            /*
             * The class and method that return the items that should appear in
             * the feed. `Writing` implements `Spatie\Feed\Feedable` and returns
             * the published writings newest first, limited to 20.
             */
            'items' => [Writing::class, 'getFeedItems'],

            /*
             * The feed will be available on this url.
             */
            'url' => '/writing/feed.atom',

            'title' => 'Writing',
            'description' => 'Writing from '.config('app.name').'.',
            'language' => 'en',

            /*
             * The image to display for the feed. For Atom feeds, this is displayed as
             * a banner/logo; for RSS and JSON feeds, it's displayed as an icon.
             * An empty value omits the image attribute from the feed.
             */
            'image' => '',

            /*
             * The format of the feed. Acceptable values are 'rss', 'atom', or 'json'.
             */
            'format' => 'atom',

            /*
             * The view that will render the feed.
             */
            'view' => 'feed::atom',
        ],
    ],
];
