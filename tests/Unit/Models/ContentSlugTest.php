<?php

use App\Support\ContentSlug;

test('it lower-cases and hyphenates a plain title', function () {
    expect(ContentSlug::canonicalize('Hello World'))->toBe('hello-world');
    expect(ContentSlug::canonicalize('already-a-slug'))->toBe('already-a-slug');
});

test('it drops punctuation and folds mixed case', function (string $title, string $slug) {
    expect(ContentSlug::canonicalize($title))->toBe($slug);
})->with([
    ['Mixed CASE Title!', 'mixed-case-title'],
    ['Rock & Roll: A Story', 'rock-roll-a-story'],
    ["It's a Test", 'its-a-test'],
    ['100% Coverage', '100-coverage'],
    ['C++', 'c'],
]);

test('it trims leading and trailing whitespace', function () {
    expect(ContentSlug::canonicalize('  Hello World  '))->toBe('hello-world');
    expect(ContentSlug::canonicalize("\tHello World\n"))->toBe('hello-world');
});

test('it collapses runs of separators into a single hyphen', function (string $title, string $slug) {
    expect(ContentSlug::canonicalize($title))->toBe($slug);
})->with([
    ['foo___bar--baz', 'foo-bar-baz'],
    ['foo   bar', 'foo-bar'],
    ['foo - bar', 'foo-bar'],
    ['Writing   about   PHP', 'writing-about-php'],
]);

test('dots are stripped rather than kept, so feed.atom is unreachable as a derived slug', function () {
    // Str::slug() removes every character that is not a separator, letter, number or
    // whitespace — a dot is simply deleted, it does not become a hyphen.
    expect(ContentSlug::canonicalize('feed.atom'))->toBe('feedatom');
    expect(ContentSlug::canonicalize('feed.atom'))->not->toBe('feed.atom');
    expect(ContentSlug::canonicalize('Node.js in Depth'))->toBe('nodejs-in-depth');
    expect(ContentSlug::canonicalize('a.b.c'))->toBe('abc');
});

test('it transliterates non-Latin letters that have an ASCII mapping', function (string $title, string $slug) {
    expect(ContentSlug::canonicalize($title))->toBe($slug);
})->with([
    ['Café résumé', 'cafe-resume'],
    ['Déjà Vu — A Résumé', 'deja-vu-a-resume'],
    ['Ångström Ö', 'angstrom-o'],
    ['Привет, мир!', 'privet-mir'],
]);

test('CJK input canonicalises to an empty string', function () {
    // Surprising but deliberate: voku/portable-ascii has no pinyin or kana mapping, so
    // Str::slug() strips every character and returns ''. HasSlug substitutes the
    // 'untitled' fallback before persisting, which is why this stays a pure helper.
    expect(ContentSlug::canonicalize('日本語テキスト'))->toBe('');
    expect(ContentSlug::canonicalize('中文字符'))->toBe('');
});

test('it returns an empty string for input with no alphanumeric characters', function (string $value) {
    expect(ContentSlug::canonicalize($value))->toBe('');
})->with([
    [''],
    ['   '],
    ["\t\n"],
    ['!!!'],
    ['---'],
    ['...'],
]);

test('it truncates to the 191-character column limit', function () {
    // slug is varchar(191) on every content table and on tags (§2.2), so the canonical
    // form must never outgrow it. Prose titles are capped at 150 characters by the title
    // column, so a normal title always fits without trimming.
    $realistic = ContentSlug::canonicalize(str_repeat('word ', 30));

    expect(strlen($realistic))->toBe(149);
    expect(strlen($realistic))->toBeLessThanOrEqual(191);

    // The one input class that can outgrow its title: '@' is dictionary-expanded to
    // 'at', so 150 '@' characters (the title maximum) canonicalise to 449 characters
    // before the guard cuts them back to the column limit.
    $expanded = ContentSlug::canonicalize(str_repeat('@', 150));

    expect(strlen($expanded))->toBe(191);
    expect($expanded)->not->toEndWith('-');
});

test('it joins a long title into hyphen-separated words and truncates at the limit', function () {
    $slug = ContentSlug::canonicalize(str_repeat('word ', 60));

    expect(strlen($slug))->toBe(191);
    expect($slug)->toStartWith('word-word');
    expect($slug)->not->toEndWith('-');
});

test('a suffixed slug still fits the column limit', function () {
    // The collision base at full width must be shortened before the suffix is
    // appended, or base + suffix overflows varchar(191).
    $base = ContentSlug::canonicalize(str_repeat('@', 150));

    $suffixed = ContentSlug::withSuffix(ContentSlug::truncateForSuffix($base, 2), 2);

    expect(strlen($suffixed))->toBe(191)
        ->and($suffixed)->toEndWith('-2')
        ->and($suffixed)->not->toEndWith('--');

    // A short base is untouched.
    expect(ContentSlug::truncateForSuffix('hello-world', 2))->toBe('hello-world');
});

test('withSuffix appends the collision counter to a canonical slug', function (int $n, string $suffix) {
    expect(ContentSlug::withSuffix('laravel-tips', $n))->toBe("laravel-tips-{$suffix}");
})->with([
    [2, '2'],
    [3, '3'],
    [10, '10'],
    [42, '42'],
]);

test('withSuffix is a pure formatter and does not canonicalise its input', function () {
    // Callers pass an already-canonicalised slug; the suffix is plain concatenation, so
    // a second collision on the same base produces slug-2, slug-3, … in that order.
    expect(ContentSlug::withSuffix('MiXeD Case', 2))->toBe('MiXeD Case-2');
    expect(ContentSlug::withSuffix('', 2))->toBe('-2');
});

test('canonicalize and withSuffix compose into a collision slug', function () {
    $base = ContentSlug::canonicalize('Hello World');

    expect($base)->toBe('hello-world');
    expect(ContentSlug::withSuffix($base, 2))->toBe('hello-world-2');
    expect(ContentSlug::withSuffix($base, 3))->toBe('hello-world-3');
});
