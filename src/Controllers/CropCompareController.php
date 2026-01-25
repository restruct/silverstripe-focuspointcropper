<?php

namespace Restruct\SilverStripe\ImageCropper\Controllers;

use Restruct\Silverstripe\SVG\SVGImage;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Image;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use SilverStripe\View\ArrayData;
use SilverStripe\ORM\ArrayList;

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

    private static $test_folder = 'crop-compare-test';
    private static $test_svg_name = 'crop-test.svg';
    private static $test_png_name = 'crop-test.png';

    protected function init(): void
    {
        parent::init();

        // Only allow in dev mode or for admins
        if (!Director::isDev() && !Permission::check('ADMIN')) {
            Security::permissionFailure($this);
        }
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
        ])->renderWith(['Restruct/SilverStripe/ImageCropper/CropCompare']);
    }

    /**
     * Render the comparison page.
     */
    protected function renderComparison($svgImage, $pngImage, bool $usingTestImages)
    {
        $manipulations = $this->getManipulations();

        // Generate comparison data for SVG
        $svgComparisons = ArrayList::create();
        foreach ($manipulations as $manipulation) {
            $comparison = $this->generateComparison($svgImage, $manipulation);
            if ($comparison) {
                $svgComparisons->push($comparison);
            }
        }

        // Generate comparison data for PNG
        $pngComparisons = ArrayList::create();
        foreach ($manipulations as $manipulation) {
            $comparison = $this->generateComparison($pngImage, $manipulation);
            if ($comparison) {
                $pngComparisons->push($comparison);
            }
        }

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
            'UsingTestImages' => $usingTestImages,
            'RemoveURL' => $this->Link('?remove=1'),
            'SVGEditURL' => '/admin/assets/EditForm/field/File/item/' . $svgImage->ID . '/edit',
            'PNGEditURL' => '/admin/assets/EditForm/field/File/item/' . $pngImage->ID . '/edit',
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

        // Install SVG
        $svg = $svgClass::create();
        $svg->setFromString($svgContent, $folderPath . '/' . self::config()->get('test_svg_name'));
        $svg->Title = 'Crop Test SVG';
        // Set some test CropData (crop to center 100x75)
        $svg->CropData = json_encode([
            'x' => 50,
            'y' => 37,
            'width' => 100,
            'height' => 75,
            'originalX' => 50,
            'originalY' => 37,
            'originalWidth' => 100,
            'originalHeight' => 75,
        ]);
        $svg->write();
        $svg->publishSingle();

        // Install PNG
        $png = Image::create();
        $png->setFromString($pngContent, $folderPath . '/' . self::config()->get('test_png_name'));
        $png->Title = 'Crop Test PNG';
        // Set same test CropData
        $png->CropData = json_encode([
            'x' => 50,
            'y' => 37,
            'width' => 100,
            'height' => 75,
            'originalX' => 50,
            'originalY' => 37,
            'originalWidth' => 100,
            'originalHeight' => 75,
        ]);
        $png->write();
        $png->publishSingle();

        return null;
    }

    /**
     * Remove test images from the database.
     */
    protected function removeTestImages(): void
    {
        $folderName = self::config()->get('test_folder');

        $svg = $this->getBundledTestSVG();
        if ($svg) {
            $svg->deleteFromStage('Live');
            $svg->delete();
        }

        $png = $this->getBundledTestPNG();
        if ($png) {
            $png->deleteFromStage('Live');
            $png->delete();
        }

        // Delete folder if empty
        $folder = Folder::find($folderName);
        if ($folder && $folder->myChildren()->count() === 0) {
            $folder->delete();
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
        $fileName = self::config()->get('test_svg_name');
        return File::get()->filter('Name', $fileName)->first()
            ?: File::get()->filter('FileFilename:EndsWith', $fileName)->first();
    }

    /**
     * Get the bundled test PNG from database.
     */
    protected function getBundledTestPNG(): ?Image
    {
        $fileName = self::config()->get('test_png_name');
        return Image::get()->filter('Name', $fileName)->first()
            ?: Image::get()->filter('FileFilename:EndsWith', $fileName)->first();
    }

    /**
     * Generate SVG test image (200x150).
     */
    protected function generateTestSVG(): string
    {
        return <<<SVG
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 150" width="200" height="150">
  <rect width="200" height="150" fill="#3498db"/>
  <circle cx="60" cy="75" r="40" fill="#e74c3c"/>
  <rect x="110" y="35" width="70" height="80" fill="#2ecc71" rx="5"/>
  <polygon points="145,115 110,145 180,145" fill="#f39c12"/>
  <text x="100" y="25" text-anchor="middle" font-family="Arial, sans-serif" font-size="14" font-weight="bold" fill="white">Crop Test</text>
  <!-- Grid lines for crop visualization -->
  <line x1="50" y1="0" x2="50" y2="150" stroke="rgba(255,255,255,0.3)" stroke-dasharray="5,5"/>
  <line x1="150" y1="0" x2="150" y2="150" stroke="rgba(255,255,255,0.3)" stroke-dasharray="5,5"/>
  <line x1="0" y1="37" x2="200" y2="37" stroke="rgba(255,255,255,0.3)" stroke-dasharray="5,5"/>
  <line x1="0" y1="112" x2="200" y2="112" stroke="rgba(255,255,255,0.3)" stroke-dasharray="5,5"/>
</svg>
SVG;
    }

    /**
     * Generate PNG test image (200x150).
     */
    protected function generateTestPNG(): ?string
    {
        if (!function_exists('imagecreatetruecolor')) {
            return null;
        }

        $width = 200;
        $height = 150;

        $img = imagecreatetruecolor($width, $height);
        imagealphablending($img, true);
        imagesavealpha($img, true);

        $bg = imagecolorallocate($img, 52, 152, 219);
        $red = imagecolorallocate($img, 231, 76, 60);
        $green = imagecolorallocate($img, 46, 204, 113);
        $orange = imagecolorallocate($img, 243, 156, 18);
        $white = imagecolorallocate($img, 255, 255, 255);
        $gridColor = imagecolorallocatealpha($img, 255, 255, 255, 80);

        imagefilledrectangle($img, 0, 0, $width, $height, $bg);
        imagefilledellipse($img, 60, 75, 80, 80, $red);
        imagefilledrectangle($img, 110, 35, 180, 115, $green);
        imagefilledpolygon($img, [145, 115, 110, 145, 180, 145], $orange);

        // Grid lines
        imageline($img, 50, 0, 50, 150, $gridColor);
        imageline($img, 150, 0, 150, 150, $gridColor);
        imageline($img, 0, 37, 200, 37, $gridColor);
        imageline($img, 0, 112, 200, 112, $gridColor);

        $text = 'Crop Test';
        $textWidth = imagefontwidth(3) * strlen($text);
        imagestring($img, 3, (int)(($width - $textWidth) / 2), 10, $text, $white);

        ob_start();
        imagepng($img);
        $content = ob_get_clean();
        imagedestroy($img);

        return $content;
    }

    /**
     * Get the list of manipulations to test.
     */
    protected function getManipulations(): array
    {
        return [
            // Regular manipulations (without crop)
            ['method' => 'Fit', 'args' => [100, 100], 'label' => 'Fit(100, 100)', 'cropped' => false],
            ['method' => 'Fill', 'args' => [100, 100], 'label' => 'Fill(100, 100)', 'cropped' => false],
            ['method' => 'ScaleWidth', 'args' => [100], 'label' => 'ScaleWidth(100)', 'cropped' => false],

            // Cropped manipulations
            ['method' => 'CroppedImage', 'args' => [], 'label' => 'CroppedImage()', 'cropped' => true],
            ['method' => 'CroppedFit', 'args' => [100, 100], 'label' => 'CroppedFit(100, 100)', 'cropped' => true],
            ['method' => 'CroppedFill', 'args' => [100, 100], 'label' => 'CroppedFill(100, 100)', 'cropped' => true],
            ['method' => 'CroppedScaleWidth', 'args' => [100], 'label' => 'CroppedScaleWidth(100)', 'cropped' => true],
            ['method' => 'CroppedFitMax', 'args' => [150, 150], 'label' => 'CroppedFitMax(150, 150)', 'cropped' => true],
            ['method' => 'CroppedFillMax', 'args' => [150, 150], 'label' => 'CroppedFillMax(150, 150)', 'cropped' => true],

            // FocusPoint + Crop combinations
            ['method' => 'CroppedFocusFill', 'args' => [80, 80], 'label' => 'CroppedFocusFill(80, 80)', 'cropped' => true],
        ];
    }

    /**
     * Generate comparison data for a manipulation.
     */
    protected function generateComparison($image, array $manipulation): ?ArrayData
    {
        $label = $manipulation['label'];
        $isCropped = $manipulation['cropped'] ?? false;

        try {
            $result = $this->applyManipulation($image, $manipulation);
            $resultData = $result ? $this->getImageData($result) : null;

            return ArrayData::create([
                'Label' => $label,
                'Result' => $resultData,
                'IsCropped' => $isCropped,
                'HasResult' => $resultData !== null,
            ]);
        } catch (\Exception $e) {
            return ArrayData::create([
                'Label' => $label,
                'Error' => $e->getMessage(),
                'IsCropped' => $isCropped,
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
    protected function getImageData($image): ArrayData
    {
        $url = $image->getURL();
        $filename = basename($url);
        $width = method_exists($image, 'getWidth') ? $image->getWidth() : 0;
        $height = method_exists($image, 'getHeight') ? $image->getHeight() : 0;

        return ArrayData::create([
            'URL' => $url,
            'Filename' => $filename,
            'Width' => $width,
            'Height' => $height,
            'Dimensions' => $width && $height ? "{$width}x{$height}" : 'unknown',
            'IsSVG' => pathinfo($filename, PATHINFO_EXTENSION) === 'svg',
        ]);
    }
}
