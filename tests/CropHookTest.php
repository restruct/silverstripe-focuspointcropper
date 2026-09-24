<?php

namespace Restruct\ImageCropper\Tests;

use Restruct\ImageCropper\Tests\Stub\CropHookExtension;
use SilverStripe\Assets\Image;
use SilverStripe\Dev\SapphireTest;

/**
 * The applyCropData() hook: an owner that can crop itself (restruct/silverstripe-svg-images does
 * this for SVGs, which the raster backend cannot crop) is asked first.
 */
class CropHookTest extends SapphireTest
{
    use CropperTestHelpers;

    protected $usesDatabase = true;

    protected static $required_extensions = [
        Image::class => [CropHookExtension::class],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->activateTestAssetStore();
    }

    protected function tearDown(): void
    {
        // CropHookExtension keeps its state in public statics; SapphireTest restores Config
        // between tests, not statics, so reset them here.
        CropHookExtension::$result = null;
        CropHookExtension::$received = null;
        $this->resetTestAssetStore();
        parent::tearDown();
    }

    public function testAnOwnerThatCropsItselfIsHandedTheCropData(): void
    {
        $image = $this->makeQuadrantImage('hook.png', ['CropData' => $this->cropData(100, 0, 100, 75)]);
        $marker = $this->makeQuadrantImage('marker.png');
        CropHookExtension::$result = $marker;

        $this->assertSame($marker, $image->CroppedImage());
        $this->assertSame($this->cropData(100, 0, 100, 75), CropHookExtension::$received);
    }

    public function testAnOwnerThatCannotCropItselfFallsBackToTheRasterCrop(): void
    {
        $image = $this->makeQuadrantImage('hook2.png', ['CropData' => $this->cropData(100, 0, 100, 75)]);
        CropHookExtension::$result = null;

        $cropped = $image->CroppedImage();

        $this->assertNotNull(CropHookExtension::$received, 'the hook must have been asked first');
        $this->assertSame('100x75', $this->sizeOf($cropped));
        $this->assertQuadrant('top-right', $this->pixelOf($cropped, 50, 37));
    }

    public function testTheHookIsNotAskedWithoutCropData(): void
    {
        $image = $this->makeQuadrantImage('hook3.png');
        CropHookExtension::$result = $this->makeQuadrantImage('marker3.png');

        $this->assertSame($image, $image->CroppedImage());
        $this->assertNull(CropHookExtension::$received);
    }
}
