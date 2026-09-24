<?php

namespace Restruct\ImageCropper\Tests;

use Restruct\SilverStripe\ImageCropper\ImageCropperExtension;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\Storage\DBFile;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataObjectSchema;

/**
 * ImageCropperExtension: the CropData field on Image and the Cropped* manipulations.
 *
 * Written against the public contract (the field, the template methods and what the resulting
 * image shows), not the private applyCropManipulation(), so it keeps its meaning across majors.
 */
class ImageCropperExtensionTest extends SapphireTest
{
    use CropperTestHelpers;

    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->activateTestAssetStore();
    }

    protected function tearDown(): void
    {
        $this->resetTestAssetStore();
        parent::tearDown();
    }

    public function testExtensionIsAppliedToImage(): void
    {
        $this->assertTrue(Image::has_extension(ImageCropperExtension::class));
    }

    public function testCropDataIsAVarcharFieldOnImage(): void
    {
        $spec = DataObject::getSchema()->fieldSpec(Image::class, 'CropData', DataObjectSchema::DB_ONLY);

        $this->assertNotNull($spec, 'Image must have a CropData database field');
        $this->assertStringStartsWith('Varchar(255)', $spec);
    }

    public function testCropDataIsStoredAndReadBack(): void
    {
        $image = $this->makeQuadrantImage('stored.png', ['CropData' => $this->cropData(10, 20, 30, 40)]);

        $reloaded = Image::get()->byID($image->ID);

        $this->assertSame($this->cropData(10, 20, 30, 40), $reloaded->CropData);
    }

    public function testCropconfigDefaultsArePickedUpOnImage(): void
    {
        $config = Image::config()->get('cropconfig');

        $this->assertIsArray($config);
        $this->assertSame(1, $config['autoCropArea']);
        $this->assertFalse($config['zoomable']);
    }

    public function testCroppedImageWithoutCropDataReturnsTheImageItself(): void
    {
        $image = $this->makeQuadrantImage();

        $this->assertSame($image, $image->CroppedImage());
    }

    public function testCroppedImageWithFullSizeCropDataReturnsTheImageItself(): void
    {
        $image = $this->makeQuadrantImage('full.png', ['CropData' => $this->cropData(0, 0, 200, 150)]);

        $this->assertSame($image, $image->CroppedImage());
    }

    public function testCroppedImageWithIncompleteCropDataReturnsTheImageItself(): void
    {
        // Only the preview-scaled x/y/width/height, none of the original* coordinates
        $image = $this->makeQuadrantImage('partial.png', [
            'CropData' => json_encode(['x' => 10, 'y' => 10, 'width' => 50, 'height' => 50]),
        ]);

        $this->assertSame($image, $image->CroppedImage());
    }

    public function testCroppedImageCutsTheStoredRegion(): void
    {
        // The top-right quadrant. With x and y swapped this would read from the bottom-left.
        $image = $this->makeQuadrantImage('region.png', ['CropData' => $this->cropData(100, 0, 100, 75)]);

        $cropped = $image->CroppedImage();

        $this->assertInstanceOf(DBFile::class, $cropped);
        $this->assertSame('100x75', $this->sizeOf($cropped));
        $this->assertQuadrant('top-right', $this->pixelOf($cropped, 10, 10));
        $this->assertQuadrant('top-right', $this->pixelOf($cropped, 90, 65));
    }

    public function testCroppedFillResizesTheCroppedRegion(): void
    {
        $image = $this->makeQuadrantImage('fill.png', ['CropData' => $this->cropData(0, 75, 100, 75)]);

        $filled = $image->CroppedFill(40, 40);

        $this->assertSame('40x40', $this->sizeOf($filled));
        $this->assertQuadrant('bottom-left', $this->pixelOf($filled, 20, 20));
    }

    public function testCroppedScaleWidthKeepsTheCroppedAspectRatio(): void
    {
        // A 100x50 region, a different aspect ratio from the 200x150 original, so an uncropped
        // ScaleWidth() cannot produce the same size by accident
        $image = $this->makeQuadrantImage('scale.png', ['CropData' => $this->cropData(100, 75, 100, 50)]);

        $scaled = $image->CroppedScaleWidth(50);

        $this->assertSame('50x25', $this->sizeOf($scaled));
        $this->assertQuadrant('bottom-right', $this->pixelOf($scaled, 5, 5));
    }

    public function testCroppedFocusFillProducesTheRequestedSizeFromTheRegion(): void
    {
        $image = $this->makeQuadrantImage('focusfill.png', ['CropData' => $this->cropData(100, 0, 100, 75)]);

        $filled = $image->CroppedFocusFill(30, 60);

        $this->assertSame('30x60', $this->sizeOf($filled));
        $this->assertQuadrant('top-right', $this->pixelOf($filled, 15, 30));
    }

    public function testCroppedFillWithoutCropDataBehavesLikeFill(): void
    {
        $image = $this->makeQuadrantImage();

        $this->assertSame(
            $this->sizeOf($image->Fill(60, 30)),
            $this->sizeOf($image->CroppedFill(60, 30))
        );
    }

    /**
     * Regression: the focus point was moved into the cropped frame using FocusPointWidth/Height,
     * which focuspoint only caches once the record already exists, i.e. from its second write
     * (publishing counts). On an image written once and never published (created in code, left in
     * draft) both were 0, so the focus point collapsed onto the crop's top-left corner.
     */
    public function testFocusPointIsMovedIntoTheCroppedFrameOnAnImageWrittenOnce(): void
    {
        // Focus at pixel (150, 37.5) of 200x150: the centre of the top-right quadrant.
        $image = $this->makeQuadrantImage('focus.png', [
            'CropData' => $this->cropData(100, 0, 100, 75),
            'FocusPointX' => 0.5,
            'FocusPointY' => -0.5,
        ], false);
        $this->assertEmpty($image->FocusPointWidth, 'precondition: the size cache is still empty');

        $cropped = $image->CroppedImage();

        // In the 100x75 crop that pixel is dead centre.
        $this->assertEqualsWithDelta(0.0, $cropped->FocusPoint->getX(), 0.001);
        $this->assertEqualsWithDelta(0.0, $cropped->FocusPoint->getY(), 0.001);
    }

    public function testFocusPointIsMovedIntoTheCroppedFrameOffCentre(): void
    {
        // Focus at pixel (50, 112.5): centre of the bottom-left quadrant.
        $image = $this->makeQuadrantImage('focus2.png', [
            'CropData' => $this->cropData(0, 75, 200, 75),
            'FocusPointX' => -0.5,
            'FocusPointY' => 0.5,
        ]);
        // A second write fills the size cache, the state of an image saved in the CMS.
        $image->write();

        $cropped = $image->CroppedImage();

        // 50 of 200 wide -> -0.5; 37.5 of 75 high -> 0
        $this->assertEqualsWithDelta(-0.5, $cropped->FocusPoint->getX(), 0.001);
        $this->assertEqualsWithDelta(0.0, $cropped->FocusPoint->getY(), 0.001);
    }

    public function testLegacyAliasesStillResolve(): void
    {
        $image = $this->makeQuadrantImage('alias.png', ['CropData' => $this->cropData(100, 0, 100, 75)]);

        $this->assertSame('20x20', $this->sizeOf($image->CroppedFocusedImage(20, 20)));
        $this->assertSame('20x20', $this->sizeOf($image->CroppedImageOnly(20, 20)));
    }
}
