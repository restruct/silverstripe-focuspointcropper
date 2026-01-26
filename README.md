# FocusPointCropper: Smarter Image Cropping for Silverstripe

This module adds a visual crop interface to Silverstripe's AssetAdmin, building on top of [jonom/focuspoint](https://github.com/jonom/silverstripe-focuspoint). It allows editors to define an initial crop region that serves as the basis for FocusPoint's intelligent cropping.

![Crop interface in AssetAdmin](docs/cropper.png)

*Set crop & zoom level (drag/scroll) and FocusPoint (click) directly in the CMS.*

<small>Image by [Nikolay Nachev](https://pixabay.com/users/na4ev-1481419/?utm_source=link-attribution&utm_medium=referral&utm_campaign=image&utm_content=988046) (Pixabay)</small>

## Version Compatibility

| Branch | Module Version | Silverstripe | FocusPoint | PHP |
|--------|----------------|--------------|------------|-----|
| `main` | `2.x` | ^6.0 | ^6.0 | ^8.3 |
| `1` | `1.x` | ^4.0 \|\| ^5.0 | ^4.0 \|\| ^5.0 | ^7.4 \|\| ^8.0 |

**Note:** `composer.json` is the source of truth for exact version constraints. This module replaces the older `micschk/silverstripe-focuspointcropper` package.

## Basic Usage

When you edit an image in the CMS (AssetAdmin), an extra "Focus Point + Crop" field appears:

1. **Scroll** on the image to zoom in/out
2. **Drag** the image to pan and position the crop area
3. **Click** on the subject of the image to set the focus point
4. **Save** the image to apply the crop data

The crop data is stored on the image and used as the basis for all subsequent manipulations.

## How It Works

### CropData Storage

When you define a crop region in the CMS, the module stores `CropData` as JSON on the Image record. This contains:
- Crop region coordinates (x, y, width, height)
- Zoom level
- Canvas dimensions

### Image Manipulation Methods

The module adds several manipulation methods to `Image`:

**Cropped Methods** - Apply CropData before standard manipulation:
- `CroppedImage($width, $height)` - Apply crop, then resize to fit
- `CroppedFill($width, $height)` - Apply crop, then fill exact dimensions
- `CroppedFocusFill($width, $height)` - Apply crop, then focus-aware fill

**Focus-Aware Cropped Methods** - Combine CropData with FocusPoint:
- `CroppedFocusFill($width, $height)` - Uses both CropData and FocusPoint for optimal results
- `CroppedFocusCropWidth($width)` - Crop to width, respecting both crop region and focus point
- `CroppedFocusCropHeight($height)` - Crop to height, respecting both crop region and focus point

### SVG Support

When used together with [restruct/silverstripe-svg-images](https://github.com/restruct/silverstripe-svg-images), all crop methods work seamlessly with SVG files. The svg-images module automatically detects when this module is installed and enables crop support for SVGs.

## Template Usage

```html
<!-- Standard manipulation (uses CropData if set) -->
<img src="$Image.CroppedFill(400, 300).URL" alt="$Image.Title">

<!-- Focus-aware cropping (uses both CropData and FocusPoint) -->
<img src="$Image.CroppedFocusFill(400, 300).URL" alt="$Image.Title">

<!-- Fallback for images without CropData -->
<img src="$Image.Fill(400, 300).URL" alt="$Image.Title">
```

## Development Tools

### Crop Functionality Test Page

A visual comparison tool is available at `/dev/crop-compare` to test crop functionality with both SVG and PNG images.

![Crop Compare Test Tool](docs/crop-compare-test.png)

The tool:
- Tests all Cropped* methods (CroppedImage, CroppedFill, CroppedFocusFill, etc.)
- Compares regular manipulations with Cropped* versions
- Shows badges indicating which methods use CropData and/or FocusPoint
- Includes bundled test images with pre-configured CropData and FocusPoint
- Works with custom image IDs for testing your own images

**Test Image Features:**
- **Dashed lines** mark the crop boundaries
- **White crosshair** marks the FocusPoint location
- **Orange triangle** is near the FocusPoint - should stay visible in FocusFill crops
- **Red circle** is left of center - may be cropped in narrow FocusFill

## Configuration

Configuration options can be set using Silverstripe's Config API:

```yaml
# Cropper field configuration
Restruct\SilverStripe\ImageCropper\FocusPointCropField:
  cropconfig:
    aspectRatio: 1.777  # 16:9 ratio
    autoCropArea: 0.8   # Initial crop covers 80% of image
```

For all available cropper options, see [Cropper.js documentation](https://github.com/fengyuanchen/cropper/blob/v2.3.0/README.md#options).

## Related Modules

- [jonom/focuspoint](https://github.com/jonom/silverstripe-focuspoint) - Required. Provides the FocusPoint field and basic focus-aware cropping
- [restruct/silverstripe-svg-images](https://github.com/restruct/silverstripe-svg-images) - Optional. Enables full SVG support including crop methods
- [restruct/silverstripe-simpler](https://github.com/restruct/silverstripe-simpler) - Required. Provides form field utilities

## License

BSD-3-Clause
