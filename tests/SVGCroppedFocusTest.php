<?php

namespace Restruct\ImageCropper\Tests;

use SilverStripe\Assets\Image;
use SilverStripe\Dev\SapphireTest;

/**
 * The focus-aware Cropped* methods on an SVG (#11), with restruct/silverstripe-svg-images.
 *
 * Oracle: the raster path, on a PNG of the same 200x150 size with the same CropData and focus
 * point. Only SVG markup, sizes and variant names are inspected; nothing here rasterises an SVG.
 *
 * Skips without svg-images: the main CI legs run without it on purpose (see the CI matrix), the
 * svg-images legs run these.
 */
class SVGCroppedFocusTest extends SapphireTest
{
    use CropperTestHelpers;

    protected $usesDatabase = true;

    /**
     * Crop region: the top-right quadrant, 100x75 at (100, 0).
     */
    private const CROP = [100, 0, 100, 75];

    /**
     * Focus at pixel (180, 37.5) of 200x150: right of the crop region's centre, so a crop around
     * the focus point and a crop from the centre keep different parts of the region. In the
     * cropped frame that pixel is (80, 37.5) of 100x75, i.e. focus (0.6, 0).
     */
    private const FOCUS_X = 0.8;
    private const FOCUS_Y = -0.5;

    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists('Restruct\\Silverstripe\\SVG\\SVGImage')) {
            $this->markTestSkipped('restruct/silverstripe-svg-images is not installed');
        }
        $this->activateTestAssetStore();
    }

    protected function tearDown(): void
    {
        $this->resetTestAssetStore();
        parent::tearDown();
    }

    private function fields(): array
    {
        return [
            'CropData' => $this->cropData(...self::CROP),
            'FocusPointX' => self::FOCUS_X,
            'FocusPointY' => self::FOCUS_Y,
        ];
    }

    /**
     * An SVGImage in the same four quadrants as makeQuadrantImage(), 200x150, written and
     * published (the same two writes the PNG gets).
     */
    private function makeQuadrantSVG(string $name = 'quadrants.svg'): Image
    {
        $svg = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="150" viewBox="0 0 200 150">'
            . '<rect x="0" y="0" width="100" height="75" fill="#e74c3c"/>'
            . '<rect x="100" y="0" width="100" height="75" fill="#2ecc71"/>'
            . '<rect x="0" y="75" width="100" height="75" fill="#3498db"/>'
            . '<rect x="100" y="75" width="100" height="75" fill="#f1c40f"/>'
            . '</svg>';

        $class = 'Restruct\\Silverstripe\\SVG\\SVGImage';
        /** @var Image $image */
        $image = $class::create();
        $image->setFromString($svg, 'croppertest/' . $name);
        foreach ($this->fields() as $field => $value) {
            $image->$field = $value;
        }
        $image->write();
        $image->publishSingle();

        return $image;
    }

    public function testTheCroppedSvgCarriesTheFocusPointMovedIntoTheCroppedFrame(): void
    {
        $svg = $this->makeQuadrantSVG();
        $png = $this->makeQuadrantImage('quadrants.png', $this->fields());

        $svgCropped = $svg->CroppedImage();
        $pngCropped = $png->CroppedImage();

        $this->assertSame('100x75', $this->sizeOf($svgCropped), 'precondition: the region is cropped');
        $this->assertStringContainsString('<svg', $svgCropped->getString(), 'precondition: still a vector');
        // Same focus point as the raster path computes: (0.6, 0) in the cropped frame
        $this->assertEqualsWithDelta(0.6, $pngCropped->FocusPoint->getX(), 0.001, 'premise: raster oracle');
        $this->assertNotNull($svgCropped->FocusPoint);
        $this->assertEqualsWithDelta($pngCropped->FocusPoint->getX(), $svgCropped->FocusPoint->getX(), 0.001);
        $this->assertEqualsWithDelta($pngCropped->FocusPoint->getY(), $svgCropped->FocusPoint->getY(), 0.001);
        // ...measured against the cropped frame, not the original
        $this->assertSame(100, $svgCropped->FocusPoint->getWidth());
        $this->assertSame(75, $svgCropped->FocusPoint->getHeight());
    }

    public function testCroppedFocusCropWidthCropsTheRegionAroundTheFocusPoint(): void
    {
        $svg = $this->makeQuadrantSVG();
        $png = $this->makeQuadrantImage('quadrants.png', $this->fields());

        $svgResult = $svg->CroppedFocusCropWidth(50);

        // /dev/crop-compare showed 50x75 on the PNG and the uncropped 100x75 region on the SVG
        $this->assertSame('50x75', $this->sizeOf($png->CroppedFocusCropWidth(50)), 'premise: raster oracle');
        $this->assertSame('50x75', $this->sizeOf($svgResult));
        $this->assertStringContainsString('focuscrop', $svgResult->getVariant(), 'a focus crop, not a plain one');
        $this->assertNotSame(
            $svg->CroppedCropWidth(50)->getString(),
            $svgResult->getString(),
            'keeps the part around the focus point, not the centre'
        );
    }

    public function testCroppedFocusCropHeightCropsTheRegion(): void
    {
        $svg = $this->makeQuadrantSVG();
        $png = $this->makeQuadrantImage('quadrants.png', $this->fields());

        $svgResult = $svg->CroppedFocusCropHeight(50);

        $this->assertSame('100x50', $this->sizeOf($png->CroppedFocusCropHeight(50)), 'premise: raster oracle');
        $this->assertSame('100x50', $this->sizeOf($svgResult));
        $this->assertStringContainsString('focuscrop', $svgResult->getVariant(), 'a focus crop, not a plain one');
    }

    public function testCroppedFocusFillFillsAroundTheFocusPoint(): void
    {
        $svg = $this->makeQuadrantSVG();
        $png = $this->makeQuadrantImage('quadrants.png', $this->fields());

        $svgResult = $svg->CroppedFocusFill(60, 60);

        $this->assertSame('60x60', $this->sizeOf($png->CroppedFocusFill(60, 60)), 'premise: raster oracle');
        $this->assertSame('60x60', $this->sizeOf($svgResult));
        $this->assertStringContainsString('focusfill', $svgResult->getVariant(), 'a focus fill, not a centre Fill');
        $this->assertNotSame(
            $svg->CroppedFill(60, 60)->getString(),
            $svgResult->getString(),
            'keeps the part around the focus point, not the centre'
        );
    }

    public function testCroppedFocusFillMaxFillsAroundTheFocusPoint(): void
    {
        $svg = $this->makeQuadrantSVG();

        $svgResult = $svg->CroppedFocusFillMax(60, 60);

        $this->assertSame('60x60', $this->sizeOf($svgResult));
        $this->assertStringContainsString('focusfill', $svgResult->getVariant(), 'a focus fill, not a centre FillMax');
    }
}
