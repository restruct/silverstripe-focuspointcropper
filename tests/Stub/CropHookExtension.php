<?php

namespace Restruct\ImageCropper\Tests\Stub;

use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;

/**
 * Stands in for restruct/silverstripe-svg-images' SVGCropperExtension: an owner that provides
 * applyCropData() gets to crop itself (SVGs cannot go through the raster backend).
 *
 * Not a DataObject and not abstract, so it is safe in a consumer's test manifest.
 */
class CropHookExtension extends Extension implements TestOnly
{
    /**
     * What applyCropData() returns; null means "cannot crop this", which must fall through to
     * the raster crop.
     */
    public static $result = null;

    /**
     * The CropData JSON the last call received.
     */
    public static $received = null;

    public function applyCropData(?string $cropDataJson)
    {
        static::$received = $cropDataJson;

        return static::$result;
    }
}
