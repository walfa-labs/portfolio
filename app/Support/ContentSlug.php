<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Pure, database-free helpers for deriving content slugs.
 */
class ContentSlug
{
    /**
     * The varchar length of every slug column (§2.2).
     */
    public const MAX_LENGTH = 191;

    /**
     * Canonicalise an arbitrary string into a slug.
     *
     * Mirrors the behaviour of {@see Str::slug()}: the value is transliterated to
     * ASCII, lower-cased and reduced to hyphen-separated words. Punctuation and
     * dots are stripped, so `feed.atom` becomes `feedatom` and is therefore
     * unreachable as a derived slug. Input with no ASCII transliteration (for
     * example CJK) canonicalises to an empty string; callers that persist a slug
     * are responsible for supplying a fallback.
     *
     * The result never exceeds {@see self::MAX_LENGTH}: an over-long slug is cut
     * back to the column limit and any trailing hyphen the cut left behind is
     * trimmed, so a mid-word break never ends the slug on a separator.
     */
    public static function canonicalize(string $value): string
    {
        $slug = Str::slug($value);

        if (strlen($slug) > self::MAX_LENGTH) {
            $slug = rtrim(substr($slug, 0, self::MAX_LENGTH), '-');
        }

        return $slug;
    }

    /**
     * Append a collision suffix to an already-canonicalised slug.
     */
    public static function withSuffix(string $slug, int $n): string
    {
        return "{$slug}-{$n}";
    }

    /**
     * Shorten an already-canonicalised slug so a collision suffix still fits the column.
     *
     * The cut leaves room for the "-{n}" suffix but does not trim a trailing hyphen:
     * the cut position is chosen so the suffixed slug fills the column exactly, and a
     * suffix always ends in a digit, so the result never ends on a double hyphen.
     */
    public static function truncateForSuffix(string $slug, int $n): string
    {
        $limit = self::MAX_LENGTH - strlen((string) $n) - 1;

        if ($limit < 1 || strlen($slug) <= $limit) {
            return $slug;
        }

        return substr($slug, 0, $limit);
    }
}
