<?php

namespace Restruct\FpcBrowser;

use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Image;
use SilverStripe\ORM\DataObject;

/**
 * BROWSER-TEST FIXTURE ONLY - seeds the images the specs open in the asset admin.
 *
 * Never loaded by a real install: it lives under tests/browser/, which carries a _manifest_exclude
 * marker, and the browser-test runner copies it into a scratch host's app/ before dev/build.
 * Written to load on both Silverstripe 5 and 6 (no class imports that moved between the two).
 *
 * The record itself is never used; a DataObject is simply the class whose requireDefaultRecords()
 * every dev/build calls. Each build finds or creates the images (stable IDs and files across runs)
 * and resets their CropData and FocusPoint, so a run starts from the same state.
 */
class FpcBSeed extends DataObject
{
    # Short table name: no namespaced default.
    private static $table_name = 'FpcBSeed';

    /** Folder of the seeded images; the specs open them in the asset admin by ID. */
    public const FOLDER = 'fpc-browser';

    /**
     * The seeded images: file name => [title, CropData]. One image without a crop (the cropper
     * starts on the whole image) and one with a stored crop region (the cropper must open on it).
     * "Fpc drag" is a second image without a crop, for the spec that drags the crop box and saves:
     * that spec stores a crop, so on a shared image every later spec (or a --repeat-each pass)
     * would open on the saved region instead of the whole image. Its title must not contain
     * another title ("Fpc crop drag" would also match the "Fpc crop" tile locator).
     * The preset region is in ORIGINAL pixels: x 200-600, y 150-450 of the 800x600 image, which
     * is x 100-300, y 75-225 on the 400x300 preview.
     */
    public const IMAGES = [
        'fpc-crop.png' => ['Fpc crop', null],
        'fpc-drag.png' => ['Fpc drag', null],
        'fpc-preset.png' => ['Fpc preset', [
            'x' => 200, 'y' => 150, 'width' => 400, 'height' => 300,
            'originalX' => 200, 'originalY' => 150, 'originalWidth' => 400, 'originalHeight' => 300,
        ]],
    ];

    public function requireDefaultRecords()
    {
        parent::requireDefaultRecords();

        $folder = Folder::find_or_make(self::FOLDER);
        foreach (self::IMAGES as $filename => [$title, $cropData]) {
            $image = Image::get()->filter('FileFilename', self::FOLDER . '/' . $filename)->first();
            if (!$image) {
                $image = Image::create();
                $image->ParentID = $folder->ID;
            } else {
                # Drop the file and every variant (Cropped* results included) from the asset store,
                # so each run generates them again from the module code under test. Variants are
                # cached by name, and the name does not change when the crop code does: a stale
                # variant would hide a broken manipulation.
                $image->deleteFile();
            }
            # 800x600: twice the 400x300 preview the module sets on FocusPointField, so the specs
            # can check that the crop is converted between preview and original pixels.
            $image->setFromString(self::png(800, 600), self::FOLDER . '/' . $filename);
            $image->Title = $title;
            $image->CropData = $cropData ? json_encode($cropData) : null;
            $image->FocusPointX = 0;
            $image->FocusPointY = 0;
            $image->write();
            $image->publishSingle();
        }
    }

    /**
     * A plain PNG with a few shapes (GD is a Silverstripe image requirement anyway).
     */
    private static function png(int $width, int $height): string
    {
        $img = imagecreatetruecolor($width, $height);
        imagefilledrectangle($img, 0, 0, $width, $height, imagecolorallocate($img, 52, 152, 219));
        imagefilledellipse($img, (int) ($width * 0.3), (int) ($height * 0.5), 200, 200, imagecolorallocate($img, 231, 76, 60));
        imagefilledrectangle($img, (int) ($width * 0.55), (int) ($height * 0.2), (int) ($width * 0.85), (int) ($height * 0.6), imagecolorallocate($img, 46, 204, 113));
        ob_start();
        imagepng($img);
        $content = ob_get_clean();
        imagedestroy($img);
        return $content;
    }
}
