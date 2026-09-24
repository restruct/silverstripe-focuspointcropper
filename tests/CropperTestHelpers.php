<?php

namespace Restruct\ImageCropper\Tests;

use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\Image;

/**
 * Shared set-up for the suite.
 *
 * A trait rather than an abstract base test class on purpose: nothing in a module's tests/ may be
 * abstract if it could ever end up in a consumer's class manifest (see the SOP's abstract-fixture
 * trap), and a trait sidesteps the question entirely.
 *
 * Compatibility note: the suite runs under PHPUnit 9 (Silverstripe 5) and PHPUnit 11
 * (Silverstripe 6). Keep it free of doc-comment metadata (@test, @dataProvider) and of
 * assertions removed after PHPUnit 9.
 */
trait CropperTestHelpers
{
    /**
     * RGB of each quadrant of the reference image, so a crop can be checked by what it shows.
     */
    protected static array $quadrants = [
        'top-left' => [231, 76, 60],     // red
        'top-right' => [46, 204, 113],   // green
        'bottom-left' => [52, 152, 219], // blue
        'bottom-right' => [241, 196, 15], // yellow
    ];

    protected function activateTestAssetStore(): void
    {
        // Every file this suite writes goes to a throwaway store under the temp folder, never the
        // host project's assets/.
        TestAssetStore::activate('FocusPointCropperTest');
    }

    protected function resetTestAssetStore(): void
    {
        TestAssetStore::reset();
    }

    /**
     * A PNG in four solid quadrants (200x150 by default, so each quadrant is 100x75), written and
     * optionally published.
     *
     * Unpublished, it is written exactly once: focuspoint only caches the image size
     * (FocusPointWidth/Height) once the record exists, so it is the state of an image created in
     * code and left in draft. Publishing writes again and fills that cache.
     */
    protected function makeQuadrantImage(
        string $name = 'quadrants.png',
        array $fields = [],
        bool $publish = true,
        int $width = 200,
        int $height = 150
    ): Image {
        $img = imagecreatetruecolor($width, $height);
        $midX = intdiv($width, 2);
        $midY = intdiv($height, 2);
        $boxes = [
            'top-left' => [0, 0, $midX - 1, $midY - 1],
            'top-right' => [$midX, 0, $width - 1, $midY - 1],
            'bottom-left' => [0, $midY, $midX - 1, $height - 1],
            'bottom-right' => [$midX, $midY, $width - 1, $height - 1],
        ];
        foreach ($boxes as $quadrant => [$x1, $y1, $x2, $y2]) {
            [$r, $g, $b] = static::$quadrants[$quadrant];
            imagefilledrectangle($img, $x1, $y1, $x2, $y2, imagecolorallocate($img, $r, $g, $b));
        }
        ob_start();
        imagepng($img);
        $content = ob_get_clean();
        imagedestroy($img);

        $image = Image::create();
        $image->setFromString($content, 'croppertest/' . $name);
        foreach ($fields as $field => $value) {
            $image->$field = $value;
        }
        $image->write();
        if ($publish) {
            $image->publishSingle();
        }

        return $image;
    }

    /**
     * CropData JSON the way the CMS cropper stores it (original-image pixel coordinates).
     */
    protected function cropData(int $x, int $y, int $width, int $height): string
    {
        return json_encode([
            'x' => $x, 'y' => $y, 'width' => $width, 'height' => $height,
            'originalX' => $x, 'originalY' => $y, 'originalWidth' => $width, 'originalHeight' => $height,
        ]);
    }

    /**
     * "WxH" of a manipulation result.
     */
    protected function sizeOf($image): string
    {
        return $image->getWidth() . 'x' . $image->getHeight();
    }

    /**
     * RGB of one pixel of a manipulation result, read back from its stored file.
     */
    protected function pixelOf($image, int $x, int $y): array
    {
        $gd = imagecreatefromstring($image->getString());
        $rgb = imagecolorat($gd, $x, $y);
        imagedestroy($gd);

        return [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
    }

    /**
     * Assert a pixel is (close to) the colour of the named quadrant. A small tolerance absorbs
     * resampling at the edges; the quadrant colours are far apart.
     */
    protected function assertQuadrant(string $expected, array $rgb, string $message = ''): void
    {
        $want = static::$quadrants[$expected];
        $distance = abs($want[0] - $rgb[0]) + abs($want[1] - $rgb[1]) + abs($want[2] - $rgb[2]);
        $this->assertLessThan(
            30,
            $distance,
            ($message ? $message . ': ' : '') . "expected the $expected quadrant, got rgb(" . implode(',', $rgb) . ')'
        );
    }
}
