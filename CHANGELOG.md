# Changelog

## 3.0.2 (unreleased)

### Fixed

- A focus point outside the crop region is clamped to the edge of the cropped frame (#4). It was
  moved into the cropped frame without clamping, so it came out beyond the -1..1 range (eg Y 1.37).
  focuspoint clamps the offset when it renders, so images looked right, but the out-of-range value
  was stored on the variant and equivalent inputs produced different variant keys.

## 3.0.1 (2026-10-02)

### Fixed

- Moving or resizing the crop box in the asset admin was never saved (#5). The script set the
  `CropData` field with jQuery, which the admin's React form does not see, so React put its own
  (empty) value back and the save sent `CropData=`. It now sets the value the way React picks it
  up (the input's native value setter plus an `input` event). Crops stored before still load.

### Tests

- The browser spec that drags the crop box and saves is active (it was `fixme` because of #5),
  and it works on its own seeded image, so the crop it stores does not leak into the other specs.

## 3.0.0 (2026-09-25)

One line for Silverstripe 5 (PHP 8.1+) and 6 (PHP 8.3+), replacing `2.0.x` (Silverstripe 4 and 5).
Silverstripe 4 is no longer supported; stay on `2.0.x` there. See [UPGRADING.md](UPGRADING.md).

This also covers #2 (port the 2.0.8 changes to the Silverstripe 6 line): this line is built on top
of 2.0.8, so those changes are included rather than cherry-picked, and there is one branch for both
majors instead of a separate Silverstripe 6 branch.

### Silverstripe 6 support

On Silverstripe 6 the 2.x code fataled the whole application on the next flush or `dev/build`:

- `ImageCropperExtension` extended `DataExtension`, which Silverstripe 6 removed. It now extends
  `Core\Extension`, on both majors.
- `PublishCropDataTask` redeclared `BuildTask`'s `$title` and `$description`, which Silverstripe 6
  made typed and static. Its entry point now comes from a per-major trait (`run()` on 5,
  `execute()` on 6; `sake tasks:PublishCropDataTask` on 6).

And these failed quietly:

- `/dev/crop-compare` was registered under `DevelopmentAdmin.registered_controllers`, which
  Silverstripe 6 no longer reads. It is registered under `controllers` there now, and the page no
  longer uses the `ArrayData`/`ArrayList` class names Silverstripe 6 moved.
- The crop passes `Image_Backend::crop()`'s `$position` argument, which the Silverstripe 6
  interface requires (core's own backend defaults it), and casts the CropData coordinates to int.

### Security

- **`/dev/crop-compare` checks access itself** (dev mode, or `ADMIN` / `ALL_DEV_ADMIN`). It relied
  on `DevelopmentAdmin`, which does not check access to a registered controller, so in live mode a
  user with any dev permission (e.g. `BUILDTASK_CAN_RUN`) could open it, render any image by ID,
  and install or remove its test images in the asset store.

### Fixed

- The focus point was moved into the cropped frame using focuspoint's cached image size
  (`FocusPointWidth`/`FocusPointHeight`), which is only filled once a record already exists. On an
  image written once and still in draft, `CroppedFocus*` and the other Cropped methods placed the
  focus point on the crop's top-left corner. The image's real size is used now.
- `PublishCropDataTask` no longer ends the PHP process (`die()`) after a batch of 100; it stops,
  says so, and exits normally. Run it again for the next batch, as before.

### Changed

- Requires `jonom/focuspoint` `^5 || ^6` (was `^4 || ^5`) and `restruct/silverstripe-simpler`
  `~0.2 || ^1`; declares `silverstripe/framework` `^5 || ^6` and PHP `^8.1` explicitly.
- A `LICENSE` file is added (BSD-3-Clause, unchanged from earlier releases), naming the
  copyright holders as the history shows.
- `composer.json` has a `funding` entry.
- `composer.json` suggests `restruct/silverstripe-svg-images` (optional; used when installed).
- README: the `cropconfig` example named a class that does not exist
  (`...\FocusPointCropField`), so that configuration was silently ignored. It is configured on
  `SilverStripe\Assets\Image`.

### Removed

- `client/legacy/` (the pre-4.x cropper assets: cropper v2 and jQuery 2.2.3, which has known XSS
  advisories). Nothing referenced it, but `expose: client` published it into every project's
  `_resources`.
- `TODO_SVG_ISSUES.md`: its open item (SVG crop support) is handled by
  `restruct/silverstripe-svg-images` 3.x.
- `src/.upgrade.yml`: its mappings pointed at a namespace that does not exist.

### Tests and CI

- First test suite (36 tests), run on Silverstripe 5 and 6 in GitHub Actions, together with a
  consumer-shape database test and a `dev/build`. It checks what each crop actually shows, pixel
  by pixel, not only its size.
- CI fails a run that reports fewer than the expected 36 tests, and on Silverstripe 6 (PHPUnit 11)
  one that runs no tests at all.

## 2.0.8 and earlier

See the [GitHub releases](https://github.com/restruct/silverstripe-focuspointcropper/tags).
