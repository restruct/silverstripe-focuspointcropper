<?php

namespace Restruct\FpcBrowser;

use SilverStripe\AssetAdmin\Controller\AssetAdmin;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Image;
use SilverStripe\ORM\DataObject;

/**
 * BROWSER-TEST FIXTURE ONLY - the content behind the README screenshots
 * (tests/browser/screenshots/, see its README.md): one drawn landscape in a "Showcase" folder, with
 * a stored crop region and focus point, which FpcShowcaseController renders at /crop-showcase.
 *
 * Never loaded by a real install: it lives under tests/browser/, which carries a _manifest_exclude
 * marker, and the browser-test runner copies it into a scratch host's app/ before dev/build.
 * Written to load on both Silverstripe 5 and 6, like FpcBSeed.
 *
 * As in FpcBSeed, the record itself is never used: a DataObject is simply the class whose
 * requireDefaultRecords() every dev/build calls. Each build resets the image, its crop and its
 * focus point, so the screenshots always start from the same state.
 */
class FpcShowcase extends DataObject
{
    # Short table name: no namespaced default.
    private static $table_name = 'FpcShowcase';

    /** Folder in Files that the CMS screenshot opens. */
    public const FOLDER = 'Showcase';

    public const FILENAME = self::FOLDER . '/lighthouse.png';

    public const TITLE = 'Lighthouse';

    /** Size of the drawing, in pixels. */
    public const WIDTH = 1200;
    public const HEIGHT = 800;

    /**
     * The crop an editor made: the trees on the left cut away, 4:3, in ORIGINAL pixels. The x/y/
     * width/height keys are the 400x267 CMS preview's (a third), as the cropper script stores them.
     */
    public const CROP = [
        'x' => 113, 'y' => 40, 'width' => 287, 'height' => 215,
        'originalX' => 340, 'originalY' => 120, 'originalWidth' => 860, 'originalHeight' => 645,
    ];

    /**
     * The focus point an editor clicked: the lighthouse lamp at (1010, 300). FocusPoint's own
     * coordinates run -1..1 from the left and from the top.
     */
    public const FOCUS = ['X' => 1010 / self::WIDTH * 2 - 1, 'Y' => 300 / self::HEIGHT * 2 - 1];

    public function requireDefaultRecords()
    {
        parent::requireDefaultRecords();

        if (!function_exists('imagecreatetruecolor')) {
            return;
        }

        $folder = Folder::find_or_make(self::FOLDER);
        $image = Image::get()->filter('FileFilename', self::FILENAME)->first();
        if (!$image) {
            $image = Image::create();
            $image->ParentID = $folder->ID;
        } else {
            # Drop the file and its variants, so they are generated again by the code under test.
            $image->deleteFile();
        }
        $image->setFromString(self::png(), self::FILENAME);
        $image->Title = self::TITLE;
        $image->CropData = json_encode(self::CROP);
        $image->FocusPointX = self::FOCUS['X'];
        $image->FocusPointY = self::FOCUS['Y'];
        $image->write();
        $image->publishSingle();

        # The Files gallery never generates thumbnails on display (asset-admin "Generates: false"),
        # so an image seeded from code shows an empty tile without this.
        AssetAdmin::singleton()->generateThumbnails($image);
    }

    /**
     * A plain landscape drawn with GD: pine trees on the left (what the editor crops away), the
     * sea with a sailboat, the sun, and a lighthouse on a cliff on the right (the focus point).
     */
    private static function png(): string
    {
        $w = self::WIDTH;
        $h = self::HEIGHT;
        $gd = imagecreatetruecolor($w, $h);
        $c = fn (int $rgb) => imagecolorallocate($gd, ($rgb >> 16) & 0xff, ($rgb >> 8) & 0xff, $rgb & 0xff);

        # Sky down to the horizon at y 500, then the sea: two vertical gradients.
        self::gradient($gd, 0, 500, [0x93, 0xc5, 0xfd], [0xe0, 0xf2, 0xfe]);
        self::gradient($gd, 500, $h, [0x25, 0x63, 0xeb], [0x1e, 0x3a, 0x8a]);

        # Sun and a few wave lines.
        imagefilledellipse($gd, 700, 210, 140, 140, $c(0xfde68a));
        imagesetthickness($gd, 3);
        foreach ([[420, 560, 520], [600, 620, 720], [380, 690, 500], [640, 740, 780]] as [$x1, $y, $x2]) {
            imageline($gd, $x1, $y, $x2, $y, $c(0x93c5fd));
        }
        imagesetthickness($gd, 1);

        # Sailboat: hull and two sails.
        imagefilledpolygon($gd, [470, 590, 610, 590, 585, 615, 495, 615], $c(0x7c2d12));
        imagefilledpolygon($gd, [540, 585, 540, 450, 600, 585], $c(0xf8fafc));
        imagefilledpolygon($gd, [532, 585, 532, 470, 485, 585], $c(0xe2e8f0));

        # Cliff on the right, the lighthouse on top of it.
        imagefilledpolygon($gd, [820, $h, 860, 530, 940, 480, $w, 470, $w, $h], $c(0x57534e));
        imagefilledpolygon($gd, [860, 530, 940, 480, $w, 470, $w, 500, 930, 505], $c(0x65a30d));
        $white = $c(0xf8fafc);
        $red = $c(0xdc2626);
        # Tower: a trapezoid from 70px wide at its base (y 480) to 44px under the lamp room (y 300).
        for ($y = 300; $y <= 480; $y++) {
            $half = (int) round(22 + ($y - 300) / 180 * 13);
            $band = (int) floor(($y - 300) / 45) % 2 === 1;
            imageline($gd, 1010 - $half, $y, 1010 + $half, $y, $band ? $red : $white);
        }
        imagefilledrectangle($gd, 984, 292, 1036, 300, $c(0x1f2937));
        imagefilledrectangle($gd, 990, 262, 1030, 292, $c(0xfbbf24));
        imagefilledpolygon($gd, [982, 262, 1010, 236, 1038, 262], $red);

        # Pine trees on the left, in front of everything: what the editor's crop leaves out.
        foreach ([[70, 260, 150], [200, 330, 120], [-10, 380, 130], [280, 420, 90]] as [$x, $top, $half]) {
            imagefilledrectangle($gd, $x - 10, $h - 120, $x + 10, $h, $c(0x422006));
            for ($tier = 0; $tier < 3; $tier++) {
                $ty = $top + $tier * (int) (($h - 120 - $top) / 3);
                $hw = (int) ($half * (0.55 + $tier * 0.25));
                imagefilledpolygon($gd, [$x, $ty, $x + $hw, $ty + 200, $x - $hw, $ty + 200], $c($tier % 2 ? 0x166534 : 0x14532d));
            }
        }

        ob_start();
        imagepng($gd);
        $bytes = (string) ob_get_clean();
        imagedestroy($gd);
        return $bytes;
    }

    /** Fill rows $from..$to-1 with a vertical gradient between two RGB colours. */
    private static function gradient($gd, int $from, int $to, array $top, array $bottom): void
    {
        $width = imagesx($gd);
        for ($y = $from; $y < $to; $y++) {
            $f = ($y - $from) / max(1, $to - $from - 1);
            $rgb = array_map(fn ($a, $b) => (int) round($a + ($b - $a) * $f), $top, $bottom);
            imageline($gd, 0, $y, $width - 1, $y, imagecolorallocate($gd, ...$rgb));
        }
    }
}
