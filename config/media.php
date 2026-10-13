<?php

/*
 * The media pipeline's single source of truth (§9.5).
 *
 * `collections` carries the upload contract: the hard size ceiling and the
 * finfo-checked MIME allow-list the form request validates against, plus the
 * cardinality the controller enforces. `variants` carries the fixed variant
 * matrix the queued job generates — the keys are contractual with the frontend
 * (`ResponsiveImage.vue`), so they are never derived at runtime. `ratios` is
 * the aspect-ratio box each collection renders in, so the frontend can reserve
 * space and keep CLS at zero.
 *
 * `og_image` is not an uploadable collection: it is the extra `1200×630` cover
 * variant a writing's cover produces for `writings.og_image_path` (§9.5).
 */

return [
    'collections' => [
        'cover' => ['max_kb' => 5120, 'mimetypes' => ['image/jpeg', 'image/png', 'image/webp', 'image/avif'], 'cardinality' => 'single'],
        'screenshot' => ['max_kb' => 5120, 'mimetypes' => ['image/jpeg', 'image/png', 'image/webp', 'image/avif'], 'cardinality' => 'many', 'max_items' => 8],
        'logo' => ['max_kb' => 1024, 'mimetypes' => ['image/svg+xml', 'image/png', 'image/webp'], 'cardinality' => 'single'],
        'badge' => ['max_kb' => 1024, 'mimetypes' => ['image/png', 'image/webp', 'image/jpeg'], 'cardinality' => 'single'],
        'pdf' => ['max_kb' => 10240, 'mimetypes' => ['application/pdf'], 'cardinality' => 'single'],
        'inline' => ['max_kb' => 5120, 'mimetypes' => ['image/jpeg', 'image/png', 'image/webp', 'image/avif'], 'cardinality' => 'many', 'max_items' => 12],
        'avatar' => ['max_kb' => 2048, 'mimetypes' => ['image/jpeg', 'image/png', 'image/webp', 'image/avif'], 'cardinality' => 'single'],
        'cv' => ['max_kb' => 5120, 'mimetypes' => ['application/pdf'], 'cardinality' => 'single'],
    ],

    'variants' => [
        'cover' => ['cover_1600x900', 'cover_800x450', 'cover_400x225'],
        'og_image' => ['cover_1200x630'],
        'screenshot' => ['screenshot_1600w', 'screenshot_800w', 'screenshot_400w'],
        'avatar' => ['avatar_512x512', 'avatar_256x256', 'avatar_64x64'],
        'badge' => ['badge_256x256', 'badge_128x128'],
        'inline' => ['inline_1200w', 'inline_800w', 'inline_400w'],
        'logo' => ['logo_256w'],
        'pdf' => [],
        'cv' => [],
    ],

    'ratios' => ['cover' => '16/9', 'screenshot' => '16/9', 'avatar' => '1/1', 'badge' => '1/1', 'inline' => null, 'logo' => null, 'pdf' => null, 'cv' => null],
];
