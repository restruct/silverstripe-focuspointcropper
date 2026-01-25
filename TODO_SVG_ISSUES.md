# FocusPointCropper Issues Tracker

## 1. FIXED: UI Dimension Mismatch

**Problem:** SVG/image preview in AssetAdmin Focus Point field showed wrong aspect ratio (e.g., 200x150 SVG displayed as 200x100)

**Root Cause:** CropperJS has default minimum container dimensions:
- `minContainerWidth: 200` (default)
- `minContainerHeight: 100` (default)

These overrode FocusPointField's intended preview dimensions (which are halved from the PHP-generated preview image).

**Fix Applied:** Added to `client/src/js/sscropper.js` baseConfig:
```javascript
minContainerWidth: 0,
minContainerHeight: 0,
```

**Verified working:**
- SVG 200x150: container now 100x75 (correct 4:3 ratio)
- PNG 328x328: container now 164x164 (correct square)

---

## 2. FIXED: Crop data saving and applying

**Status:** VERIFIED WORKING via `/dev/crop-compare` test page

**Verified:**
- CropData entered in AssetAdmin saves correctly to database
- CropData persists across page loads
- Cropped* methods read and apply the saved CropData correctly
- PNG `CroppedImage()` returns cropped dimensions (100x75 from 200x150 original)
- All Cropped* methods (CroppedFit, CroppedFill, CroppedScaleWidth, etc.) work correctly

**Bugs fixed during testing:**
1. `CropCompareController.php`: Changed `method_exists()` to `$image->hasMethod()` - extension methods use magic `__call()` and aren't detected by `method_exists()`
2. `ImageCropperExtension.php`: Changed `applyCropManipulation()` to return `$this->owner` instead of `null` when image manipulation fails (e.g., for SVGs) - enables graceful fallback and prevents null pointer errors in chained method calls

**Test page:** `/dev/crop-compare`

---

## 3. VERIFIED: Rendering/conversion method is correct (GitHub Issue #1)

**Issue:** https://github.com/restruct/silverstripe-focuspointcropper/issues/1

**Verified against:**
- LazyFocusFit: https://github.com/evanshunt/LazyFocusFit (different approach - frontend/template helper, not comparable)
- SS4 image manipulation changelog: https://docs.silverstripe.org/en/4/changelogs/4.0.0/#image-manipulations

**Current implementation correctly uses SS4/5 API:**
- ✅ `$this->owner->variantName()` for cache key generation
- ✅ `$this->owner->manipulateImage()` with callback for the manipulation
- ✅ `Image_Backend->crop($top, $left, $width, $height)` with correct argument order
- ✅ Special handling for DBFile vs Image FocusPoint data

**Analysis:**
- LazyFocusFit is a frontend/template helper for responsive images - it wraps existing FocusPoint methods, doesn't do custom backend manipulation
- ImageCropperExtension is complementary - adds manual crop selection at backend level that then works with FocusPoint
- Extension correctly uses DataExtension (needed for CropData db field) applied to Image class
- Implementation follows all recommended SS4+ patterns

**Status:** Can be closed - implementation is correct.

---

## 4. PENDING: Implement actual SVG cropping

**Status:** Needs implementation in a-svg-images module

**Current behavior:** SVG images fall back to returning the original image (graceful degradation). The test page at `/dev/crop-compare` shows:
- SVG `CroppedImage()` returns original 200x150 (no crop applied)
- SVG `CroppedFocusFill()` returns "No result" (FocusFill also doesn't work on SVGs)

**What's needed:**
- SVG cropping cannot use GD/ImageMagick like raster images
- Need to modify SVG viewBox or create new SVG with clipped content
- Should integrate with CropData from fpcrop extension

---

## Files involved

- `a-fpcrop/src/ImageCropperExtension.php` - extends Image with CropData field and Cropped* methods
- `a-fpcrop/src/ImageCropperFormFactoryExtension.php` - adds cropper UI to AssetAdmin
- `a-fpcrop/client/src/js/sscropper.js` - cropperjs integration
- `a-fpcrop/src/Controllers/CropCompareController.php` - test controller
- `vendor/jonom/focuspoint/src/Forms/FocusPointField.php` - FocusPoint preview field

## Test files

- Test page: `/dev/crop-compare` (auto-installs test images)
- Test images created in `assets/crop-compare-test/`:
  - `crop-test.svg` - 200x150px SVG with shapes
  - `crop-test.png` - 200x150px PNG with same design
- Both have CropData set to crop center 100x75 region: `{"x":50,"y":37,"width":100,"height":75,...}`
