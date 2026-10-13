<?php

namespace App\Support;

use App\Jobs\GenerateMediaVariants;
use GdImage;

/**
 * Deterministic, generated placeholder imagery (§9.9).
 *
 * Seeding never fetches and never commits a binary: the seeder asks this class
 * for bytes, writes them to the disk, and dispatches the *same*
 * {@see GenerateMediaVariants} job a production upload would. The
 * composition is drawn from `mt_srand(crc32($itemKey))`, so re-seeding the same
 * item produces byte-identical files.
 *
 * The vocabulary is §6's: a bordered slab over a light grid, a couple of flat
 * geometric shapes in ink/primary/accent, and a 2 px frame. It is deliberately
 * simple — these are honest placeholders, not art.
 */
class PlaceholderImage
{
    /**
     * Draw the composition for an item.
     *
     * The caller owns the returned image and is responsible for destroying it.
     */
    public static function render(string $itemKey, int $width, int $height): GdImage
    {
        $width = max(1, $width);
        $height = max(1, $height);

        mt_srand(crc32($itemKey));

        $image = imagecreatetruecolor($width, $height);

        $ink = (int) imagecolorallocate($image, 17, 17, 17);
        $paper = (int) imagecolorallocate($image, 246, 245, 240);
        $grid = (int) imagecolorallocate($image, 214, 211, 202);
        $primary = (int) imagecolorallocate($image, 15, 118, 110);
        $accent = (int) imagecolorallocate($image, 245, 201, 72);

        imagefilledrectangle($image, 0, 0, $width, $height, $paper);

        $shortest = max(1, min($width, $height));
        $margin = max(2, (int) round($shortest * 0.08));
        $stroke = max(2, (int) round($shortest * 0.01));

        $step = max(8, (int) round($shortest / 8));

        for ($x = $step; $x < $width; $x += $step) {
            imageline($image, $x, 0, $x, $height, $grid);
        }

        for ($y = $step; $y < $height; $y += $step) {
            imageline($image, 0, $y, $width, $y, $grid);
        }

        imagesetthickness($image, $stroke);

        $slabWidth = max(4, (int) round($width * (0.40 + mt_rand(0, 25) / 100)));
        $slabHeight = max(4, (int) round($height * (0.35 + mt_rand(0, 25) / 100)));
        $slabX = mt_rand($margin, max($margin, $width - $slabWidth - $margin));
        $slabY = mt_rand($margin, max($margin, $height - $slabHeight - $margin));

        imagefilledrectangle($image, $slabX, $slabY, $slabX + $slabWidth, $slabY + $slabHeight, $primary);
        imagerectangle($image, $slabX, $slabY, $slabX + $slabWidth, $slabY + $slabHeight, $ink);

        $diameter = max(4, (int) round($shortest * (0.20 + mt_rand(0, 15) / 100)));
        $centerX = mt_rand($diameter, max($diameter, $width - $diameter));
        $centerY = mt_rand($diameter, max($diameter, $height - $diameter));

        imagefilledellipse($image, $centerX, $centerY, $diameter, $diameter, $accent);
        imageellipse($image, $centerX, $centerY, $diameter, $diameter, $ink);

        $side = max(4, (int) round($shortest * 0.18));
        $apexX = mt_rand($side, max($side, $width - $side));
        $apexY = mt_rand($side, max($side, $height - $side));

        imagefilledpolygon($image, [
            $apexX, max(0, $apexY - $side),
            max(0, $apexX - $side), min($height, $apexY + $side),
            min($width, $apexX + $side), min($height, $apexY + $side),
        ], $accent);

        imagerectangle($image, 0, 0, $width - 1, $height - 1, $ink);

        mt_srand();

        return $image;
    }

    /**
     * Encode the composition for an item.
     *
     * This is the seeding entry point: the returned bytes go straight to the
     * disk. WebP is the default because every raster collection declares it;
     * JPEG is the fallback when the GD build cannot write WebP.
     */
    public static function bytes(
        string $itemKey,
        int $width,
        int $height,
        string $format = 'webp',
        int $quality = 82,
    ): string {
        $image = self::render($itemKey, $width, $height);

        ob_start();

        if ($format === 'jpg' || $format === 'jpeg' || ! function_exists('imagewebp')) {
            imagejpeg($image, null, $quality);
        } else {
            imagewebp($image, null, $quality);
        }

        $binary = (string) ob_get_clean();

        imagedestroy($image);

        return $binary;
    }
}
