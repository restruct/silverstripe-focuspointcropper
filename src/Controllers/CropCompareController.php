<?php

namespace Restruct\SilverStripe\ImageCropper\Controllers;

use Restruct\Silverstripe\SVG\SVGImage;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Image;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
// ArrayData/ArrayList are no longer imported: they moved namespace in Silverstripe 6
// (View\ArrayData -> Model\ArrayData, ORM\ArrayList -> Model\List\ArrayList) with no alias left
// behind, so the class names are resolved per major - see arrayDataClass()/arrayListClass().
//use SilverStripe\View\ArrayData;
//use SilverStripe\ORM\ArrayList;

/**
 * Development controller to test crop functionality.
 *
 * Access at: /dev/crop-compare
 *
 * Usage:
 *   /dev/crop-compare - Shows options to install test images or provide IDs
 *   /dev/crop-compare?svg=123&png=456 - Run test with specific image IDs
 *   /dev/crop-compare?install=1 - Install test images and run test
 *   /dev/crop-compare?remove=1 - Remove test images
 */
class CropCompareController extends Controller
{
    private static $url_segment = 'dev/crop-compare';

    private static $allowed_actions = [
        'index',
    ];

    private static $test_folder = 'devtest-crop';
    private static $test_svg_name = 'croptest.svg';
    private static $test_png_name = 'croptest.png';

    protected function init(): void
    {
        parent::init();
        // Security handled by DevelopmentAdmin middleware (CSRF protection, auth)
    }

    /**
     * Override Link() for registered_controllers compatibility.
     * When accessed via DevelopmentAdmin, we need to return the full dev/* path.
     */
    public function Link($action = null): string
    {
        $link = '/' . self::config()->get('url_segment');
        if ($action) {
            $link .= $action;
        }
        return $link;
    }

    public function index(HTTPRequest $request)
    {
        // Handle install action
        if ($request->getVar('install')) {
            try {
                $error = $this->installTestImages();
                if ($error) {
                    return $this->renderSetup($error, false);
                }
            } catch (\Exception $e) {
                return $this->renderSetup('Install failed: ' . $e->getMessage(), false);
            }
            return $this->redirect($this->Link());
        }

        // Handle remove action
        if ($request->getVar('remove')) {
            $this->removeTestImages();
            return $this->redirect($this->Link());
        }

        $svgId = $request->getVar('svg');
        $pngId = $request->getVar('png');
        $testImagesInstalled = $this->testImagesInstalled();

        // If specific IDs provided, use those
        if ($svgId && $pngId) {
            $svgImage = File::get()->byID($svgId);
            $pngImage = File::get()->byID($pngId);

            if (!$svgImage || !$pngImage) {
                return $this->renderSetup(
                    'One or both of the specified images could not be found.',
                    $testImagesInstalled
                );
            }

            return $this->renderComparison($svgImage, $pngImage, false);
        }

        // If test images are installed, use those
        if ($testImagesInstalled) {
            $svgImage = $this->getBundledTestSVG();
            $pngImage = $this->getBundledTestPNG();

            return $this->renderComparison($svgImage, $pngImage, true);
        }

        // Otherwise show setup page
        return $this->renderSetup(null, false);
    }

    /**
     * Render the setup/welcome page.
     */
    protected function renderSetup(?string $error, bool $testImagesInstalled)
    {
        return $this->customise([
            'Title' => 'Crop Functionality Test',
            'ShowSetup' => true,
            'Error' => $error,
            'TestImagesInstalled' => $testImagesInstalled,
            'InstallURL' => $this->Link('?install=1'),
            'TestFolder' => self::config()->get('test_folder'),
        ])->renderWith(['Restruct/SilverStripe/ImageCropper/CropCompare']);
    }

    /**
     * Render the comparison page.
     */
    protected function renderComparison($svgImage, $pngImage, bool $usingTestImages)
    {
        $manipulations = $this->getManipulations();

        // Generate comparison data for SVG
        $svgComparisons = static::arrayListClass()::create();
        foreach ($manipulations as $manipulation) {
            $comparison = $this->generateComparison($svgImage, $manipulation);
            if ($comparison) {
                $svgComparisons->push($comparison);
            }
        }

        // Generate comparison data for PNG
        $pngComparisons = static::arrayListClass()::create();
        foreach ($manipulations as $manipulation) {
            $comparison = $this->generateComparison($pngImage, $manipulation);
            if ($comparison) {
                $pngComparisons->push($comparison);
            }
        }

        // Get FocusPoint data
        $svgFocusX = $svgImage->FocusPointX ?? 0;
        $svgFocusY = $svgImage->FocusPointY ?? 0;
        $pngFocusX = $pngImage->FocusPointX ?? 0;
        $pngFocusY = $pngImage->FocusPointY ?? 0;

        return $this->customise([
            'Title' => 'Crop Functionality Test',
            'ShowSetup' => false,
            'SVGImage' => $svgImage,
            'PNGImage' => $pngImage,
            'SVGComparisons' => $svgComparisons,
            'PNGComparisons' => $pngComparisons,
            'OriginalSVG' => $this->getImageData($svgImage),
            'OriginalPNG' => $this->getImageData($pngImage),
            'SVGHasCropData' => !empty($svgImage->CropData),
            'PNGHasCropData' => !empty($pngImage->CropData),
            'SVGCropData' => $svgImage->CropData,
            'PNGCropData' => $pngImage->CropData,
            'SVGHasFocusPoint' => ($svgFocusX != 0 || $svgFocusY != 0),
            'PNGHasFocusPoint' => ($pngFocusX != 0 || $pngFocusY != 0),
            'SVGFocusPointX' => round($svgFocusX, 2),
            'SVGFocusPointY' => round($svgFocusY, 2),
            'PNGFocusPointX' => round($pngFocusX, 2),
            'PNGFocusPointY' => round($pngFocusY, 2),
            'UsingTestImages' => $usingTestImages,
            'RemoveURL' => $this->Link('?remove=1'),
            'SVGEditURL' => '/admin/assets/EditForm/field/File/item/' . $svgImage->ID . '/edit',
            'PNGEditURL' => '/admin/assets/EditForm/field/File/item/' . $pngImage->ID . '/edit',
            'HasFocusPointModule' => $this->hasFocusPointModule(),
        ])->renderWith(['Restruct/SilverStripe/ImageCropper/CropCompare']);
    }

    /**
     * Install bundled test images to the database.
     */
    protected function installTestImages(): ?string
    {
        if ($this->testImagesInstalled()) {
            return null;
        }

        $folder = Folder::find_or_make(self::config()->get('test_folder'));
        $folderPath = rtrim($folder->getFilename(), '/');

        // Generate test content
        $svgContent = $this->generateTestSVG();
        $pngContent = $this->generateTestPNG();

        if (!$pngContent) {
            return 'Could not generate PNG test image. Is GD installed?';
        }

        // Check if SVGImage class exists
        $svgClass = class_exists(SVGImage::class) ? SVGImage::class : Image::class;

        // FocusPoint at pixel (125, 55) on 200x150 image - inside crop area
        // Converted to -1 to 1 scale: X = (125/200)*2-1 = 0.25, Y = (55/150)*2-1 = -0.27
        $focusPointX = 0.25;
        $focusPointY = -0.27;

        // CropData: crop region that includes the FocusPoint area
        $cropData = [
            'x' => 50,
            'y' => 37,
            'width' => 100,
            'height' => 75,
            'originalX' => 50,
            'originalY' => 37,
            'originalWidth' => 100,
            'originalHeight' => 75,
        ];

        // Install SVG
        $svg = $svgClass::create();
        $svg->setFromString($svgContent, $folderPath . '/' . self::config()->get('test_svg_name'));
        $svg->Title = 'Crop Test SVG';
        $svg->CropData = json_encode($cropData);
        // Set FocusPoint data
        $svg->FocusPointX = $focusPointX;
        $svg->FocusPointY = $focusPointY;
        $svg->write();
        $svg->publishSingle();

        // Install PNG
        $png = Image::create();
        $png->setFromString($pngContent, $folderPath . '/' . self::config()->get('test_png_name'));
        $png->Title = 'Crop Test PNG';
        $png->CropData = json_encode($cropData);
        // Set FocusPoint data
        $png->FocusPointX = $focusPointX;
        $png->FocusPointY = $focusPointY;
        $png->write();
        $png->publishSingle();

        return null;
    }

    /**
     * Remove test images from the database and filesystem.
     */
    protected function removeTestImages(): void
    {
        $folderName = self::config()->get('test_folder');

        $svg = $this->getBundledTestSVG();
        if ($svg) {
            // doArchive() removes from all stages and deletes the physical file
            $svg->doArchive();
        }

        $png = $this->getBundledTestPNG();
        if ($png) {
            $png->doArchive();
        }

        // Delete folder only if empty (no other files inside)
        $folder = Folder::find($folderName);
        if ($folder && $folder->myChildren()->count() === 0) {
            $folder->doArchive();
        }
    }

    /**
     * Check if bundled test images are installed.
     */
    protected function testImagesInstalled(): bool
    {
        return $this->getBundledTestSVG() !== null && $this->getBundledTestPNG() !== null;
    }

    /**
     * Get the bundled test SVG from database.
     */
    protected function getBundledTestSVG(): ?File
    {
        $folderName = self::config()->get('test_folder');
        $fileName = self::config()->get('test_svg_name');

        // Filter by folder path to avoid matching files with same name elsewhere
        return File::get()
            ->filter('FileFilename:StartsWith', $folderName . '/')
            ->filter('Name', $fileName)
            ->first();
    }

    /**
     * Get the bundled test PNG from database.
     */
    protected function getBundledTestPNG(): ?Image
    {
        $folderName = self::config()->get('test_folder');
        $fileName = self::config()->get('test_png_name');

        // Filter by folder path to avoid matching files with same name elsewhere
        return Image::get()
            ->filter('FileFilename:StartsWith', $folderName . '/')
            ->filter('Name', $fileName)
            ->first();
    }

    /**
     * Generate SVG test image (200x150).
     *
     * Crop area: x=50-150, y=37-112 (center 100x75)
     * FocusPoint at (125, 55) - inside crop area, offset to right
     *
     * Shapes inside crop area:
     * - Red circle: left side (center ~75, 75)
     * - Orange triangle: right side near FocusPoint
     * - Green bar: bottom of crop area
     *
     * Text "Outside" is above crop area to verify cropping works.
     */
    protected function generateTestSVG(): string
    {
        // FocusPoint at (125, 55) - inside crop area, right side
        // Converted to -1 to 1 scale: X = (125/200)*2-1 = 0.25, Y = (55/150)*2-1 = -0.27
        $fpX = 125;
        $fpY = 55;

        return <<<SVG
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 150" width="200" height="150">
  <rect width="200" height="150" fill="#3498db"/>
  <!-- Red circle: left side of crop area -->
  <circle cx="75" cy="75" r="25" fill="#e74c3c"/>
  <!-- Orange triangle: right side of crop area (near FocusPoint) -->
  <polygon points="125,42 105,78 145,78" fill="#f39c12"/>
  <!-- Green bar: bottom of crop area -->
  <rect x="55" y="90" width="90" height="18" fill="#2ecc71" rx="3"/>
  <!-- Text outside crop area (above y=37) -->
  <text x="100" y="22" text-anchor="middle" font-family="Arial, sans-serif" font-size="12" fill="white">Outside</text>
  <!-- FocusPoint marker (crosshair) -->
  <circle cx="{$fpX}" cy="{$fpY}" r="6" fill="none" stroke="white" stroke-width="2"/>
  <line x1="{$fpX}" y1="44" x2="{$fpX}" y2="66" stroke="white" stroke-width="2"/>
  <line x1="114" y1="{$fpY}" x2="136" y2="{$fpY}" stroke="white" stroke-width="2"/>
  <!-- Crop boundary lines (x=50,150 y=37,112) -->
  <line x1="50" y1="0" x2="50" y2="150" stroke="rgba(255,255,255,0.4)" stroke-dasharray="4,4"/>
  <line x1="150" y1="0" x2="150" y2="150" stroke="rgba(255,255,255,0.4)" stroke-dasharray="4,4"/>
  <line x1="0" y1="37" x2="200" y2="37" stroke="rgba(255,255,255,0.4)" stroke-dasharray="4,4"/>
  <line x1="0" y1="112" x2="200" y2="112" stroke="rgba(255,255,255,0.4)" stroke-dasharray="4,4"/>
</svg>
SVG;
    }

    /**
     * Generate PNG test image (200x150).
     *
     * Layout matches SVG - all shapes inside crop area (x=50-150, y=37-112).
     */
    protected function generateTestPNG(): ?string
    {
        if (!function_exists('imagecreatetruecolor')) {
            return null;
        }

        $width = 200;
        $height = 150;
        $fpX = 125; // FocusPoint X - inside crop area, right side
        $fpY = 55;  // FocusPoint Y - inside crop area

        $img = imagecreatetruecolor($width, $height);
        imagealphablending($img, true);
        imagesavealpha($img, true);
        imageantialias($img, true);

        $bg = imagecolorallocate($img, 52, 152, 219);
        $red = imagecolorallocate($img, 231, 76, 60);
        $green = imagecolorallocate($img, 46, 204, 113);
        $orange = imagecolorallocate($img, 243, 156, 18);
        $white = imagecolorallocate($img, 255, 255, 255);
        $gridColor = imagecolorallocatealpha($img, 255, 255, 255, 80);

        // Background
        imagefilledrectangle($img, 0, 0, $width, $height, $bg);

        // Red circle: left side of crop area
        imagefilledellipse($img, 75, 75, 50, 50, $red);

        // Orange triangle: right side of crop area (near FocusPoint)
        imagefilledpolygon($img, [125, 42, 105, 78, 145, 78], $orange);

        // Green bar: bottom of crop area
        imagefilledrectangle($img, 55, 90, 145, 108, $green);

        // FocusPoint crosshair marker
        imageellipse($img, $fpX, $fpY, 12, 12, $white);
        imageline($img, $fpX, $fpY - 11, $fpX, $fpY + 11, $white);
        imageline($img, $fpX - 11, $fpY, $fpX + 11, $fpY, $white);

        // Grid lines (crop boundaries x=50,150 y=37,112)
        imageline($img, 50, 0, 50, 150, $gridColor);
        imageline($img, 150, 0, 150, 150, $gridColor);
        imageline($img, 0, 37, 200, 37, $gridColor);
        imageline($img, 0, 112, 200, 112, $gridColor);

        // Text outside crop area
        $text = 'Outside';
        $textWidth = imagefontwidth(3) * strlen($text);
        imagestring($img, 3, (int)(($width - $textWidth) / 2), 10, $text, $white);

        ob_start();
        imagepng($img);
        $content = ob_get_clean();
        imagedestroy($img);

        return $content;
    }

    /**
     * Check if the FocusPoint module is available.
     */
    protected function hasFocusPointModule(): bool
    {
        return class_exists(\JonoM\FocusPoint\Extensions\FocusPointImageExtension::class);
    }

    /**
     * Get the list of manipulations to test.
     */
    protected function getManipulations(): array
    {
        $hasFocusPoint = $this->hasFocusPointModule();

        $manipulations = [
            // === BASIC MANIPULATIONS (no crop, no focuspoint) ===
            ['method' => 'Fit', 'args' => [100, 100], 'label' => 'Fit(100, 100)', 'cropped' => false, 'group' => 'basic'],
            ['method' => 'Fill', 'args' => [100, 100], 'label' => 'Fill(100, 100)', 'cropped' => false, 'group' => 'basic'],
            ['method' => 'ScaleWidth', 'args' => [100], 'label' => 'ScaleWidth(100)', 'cropped' => false, 'group' => 'basic'],

            // === CROPPED MANIPULATIONS (applies CropData first) ===
            ['method' => 'CroppedImage', 'args' => [], 'label' => 'CroppedImage()', 'cropped' => true, 'group' => 'cropped'],
            ['method' => 'CroppedFit', 'args' => [100, 100], 'label' => 'CroppedFit(100, 100)', 'cropped' => true, 'group' => 'cropped'],
            ['method' => 'CroppedFill', 'args' => [100, 100], 'label' => 'CroppedFill(100, 100)', 'cropped' => true, 'group' => 'cropped'],
            ['method' => 'CroppedScaleWidth', 'args' => [100], 'label' => 'CroppedScaleWidth(100)', 'cropped' => true, 'group' => 'cropped'],
        ];

        // === FOCUSPOINT vs CENTER COMPARISON (only when jonom/focuspoint is installed) ===
        if ($hasFocusPoint) {
            $manipulations = array_merge($manipulations, [
                // Narrow vertical crop (50px wide) - should show FocusPoint keeping the triangle visible
                ['method' => 'Fill', 'args' => [50, 120], 'label' => 'Fill(50, 120) - center crop', 'cropped' => false, 'group' => 'focus-compare'],
                ['method' => 'FocusFill', 'args' => [50, 120], 'label' => 'FocusFill(50, 120) - keeps triangle', 'cropped' => false, 'group' => 'focus-compare', 'focuspoint' => true],

                // Wide horizontal crop (30px tall) - should show FocusPoint keeping the triangle visible
                ['method' => 'Fill', 'args' => [180, 30], 'label' => 'Fill(180, 30) - center crop', 'cropped' => false, 'group' => 'focus-compare'],
                ['method' => 'FocusFill', 'args' => [180, 30], 'label' => 'FocusFill(180, 30) - keeps triangle', 'cropped' => false, 'group' => 'focus-compare', 'focuspoint' => true],

                // Square crop comparison
                ['method' => 'Fill', 'args' => [80, 80], 'label' => 'Fill(80, 80) - center crop', 'cropped' => false, 'group' => 'focus-compare'],
                ['method' => 'FocusFill', 'args' => [80, 80], 'label' => 'FocusFill(80, 80) - keeps triangle', 'cropped' => false, 'group' => 'focus-compare', 'focuspoint' => true],

                // === CROPPED + FOCUSPOINT COMBINATIONS ===
                // Square crops
                ['method' => 'CroppedFocusFill', 'args' => [60, 60], 'label' => 'CroppedFocusFill(60, 60)', 'cropped' => true, 'group' => 'cropped-focus', 'focuspoint' => true],
                ['method' => 'CroppedFocusFillMax', 'args' => [80, 80], 'label' => 'CroppedFocusFillMax(80, 80)', 'cropped' => true, 'group' => 'cropped-focus', 'focuspoint' => true],

                // Narrow vertical (should keep triangle, may lose circle)
                ['method' => 'CroppedFill', 'args' => [40, 70], 'label' => 'CroppedFill(40, 70) - center', 'cropped' => true, 'group' => 'cropped-focus'],
                ['method' => 'CroppedFocusFill', 'args' => [40, 70], 'label' => 'CroppedFocusFill(40, 70) - focus', 'cropped' => true, 'group' => 'cropped-focus', 'focuspoint' => true],

                // Wide horizontal (should keep triangle, may lose green bar)
                ['method' => 'CroppedFill', 'args' => [90, 30], 'label' => 'CroppedFill(90, 30) - center', 'cropped' => true, 'group' => 'cropped-focus'],
                ['method' => 'CroppedFocusFill', 'args' => [90, 30], 'label' => 'CroppedFocusFill(90, 30) - focus', 'cropped' => true, 'group' => 'cropped-focus', 'focuspoint' => true],

                // Width/Height only crops
                ['method' => 'CroppedFocusCropWidth', 'args' => [50], 'label' => 'CroppedFocusCropWidth(50)', 'cropped' => true, 'group' => 'cropped-focus', 'focuspoint' => true],
                ['method' => 'CroppedFocusCropHeight', 'args' => [40], 'label' => 'CroppedFocusCropHeight(40)', 'cropped' => true, 'group' => 'cropped-focus', 'focuspoint' => true],
            ]);
        }

        return $manipulations;
    }

    /**
     * Generate comparison data for a manipulation.
     */
    protected function generateComparison($image, array $manipulation): ?object
    {
        $label = $manipulation['label'];
        $isCropped = $manipulation['cropped'] ?? false;
        $group = $manipulation['group'] ?? 'default';
        $usesFocusPoint = $manipulation['focuspoint'] ?? false;

        try {
            $result = $this->applyManipulation($image, $manipulation);
            $resultData = $result ? $this->getImageData($result) : null;

            return static::arrayDataClass()::create([
                'Label' => $label,
                'Result' => $resultData,
                'IsCropped' => $isCropped,
                'UsesFocusPoint' => $usesFocusPoint,
                'Group' => $group,
                'HasResult' => $resultData !== null,
            ]);
        } catch (\Exception $e) {
            return static::arrayDataClass()::create([
                'Label' => $label,
                'Error' => $e->getMessage(),
                'IsCropped' => $isCropped,
                'UsesFocusPoint' => $usesFocusPoint,
                'Group' => $group,
            ]);
        }
    }

    /**
     * Apply a manipulation to an image.
     */
    protected function applyManipulation($image, array $manipulation)
    {
        $method = $manipulation['method'];
        $args = $manipulation['args'];

        if (!$image->hasMethod($method)) {
            throw new \Exception("Method {$method} does not exist");
        }

        return $image->{$method}(...$args);
    }

    /**
     * Get display data for an image result.
     */
    protected function getImageData($image): object
    {
        $url = $image->getURL();
        $filename = basename($url);
        $width = method_exists($image, 'getWidth') ? $image->getWidth() : 0;
        $height = method_exists($image, 'getHeight') ? $image->getHeight() : 0;

        return static::arrayDataClass()::create([
            'URL' => $url,
            'Filename' => $filename,
            'Width' => $width,
            'Height' => $height,
            'Dimensions' => $width && $height ? "{$width}x{$height}" : 'unknown',
            'IsSVG' => pathinfo($filename, PATHINFO_EXTENSION) === 'svg',
        ]);
    }

    /**
     * ArrayData class for the running Silverstripe major.
     *
     * Silverstripe 6 moved it from SilverStripe\View to SilverStripe\Model and removed the old
     * name outright, so importing either one breaks this controller on the other major.
     */
    protected static function arrayDataClass(): string
    {
        return class_exists('SilverStripe\\Model\\ArrayData')
            ? 'SilverStripe\\Model\\ArrayData'
            : 'SilverStripe\\View\\ArrayData';
    }

    /**
     * ArrayList class for the running Silverstripe major (moved to SilverStripe\Model\List in 6).
     */
    protected static function arrayListClass(): string
    {
        return class_exists('SilverStripe\\Model\\List\\ArrayList')
            ? 'SilverStripe\\Model\\List\\ArrayList'
            : 'SilverStripe\\ORM\\ArrayList';
    }
}
