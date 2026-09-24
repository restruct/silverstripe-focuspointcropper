# Upgrading

## From 2.0.x to 3.0

3.0 supports Silverstripe 5 and 6 from one line. For a Silverstripe 5 project nothing in the API
changes; the steps below are about constraints.

1. **Silverstripe 4 projects: stay on 2.0.x.** 3.0 needs Silverstripe 5 or 6 and PHP 8.1+.
2. **Widen your constraint.** `~2.0` or `^2.0` will not pick up 3.0:
   ```bash
   composer require restruct/silverstripe-focuspointcropper:^3
   ```
3. **Dependencies move with the major.** On Silverstripe 5 you get `jonom/focuspoint` `^5` and
   `restruct/silverstripe-simpler` `0.x`; on Silverstripe 6, `jonom/focuspoint` `^6` and
   `restruct/silverstripe-simpler` `^1`. `jonom/focuspoint` `^4` (the Silverstripe 4 line) is no
   longer allowed.
4. **Flush and build** (`sake dev/build flush=1` on 5, `sake db:build --flush` on 6). The schema
   is unchanged: `Image.CropData`, `Varchar(255)`. Stored crop data keeps working as is.

### If you configured the cropper

The 2.x README showed `cropconfig` under `Restruct\SilverStripe\ImageCropper\FocusPointCropField`.
That class does not exist, so that configuration never had any effect. Configure it on `Image`:

```yaml
SilverStripe\Assets\Image:
  cropconfig:
    aspectRatio: 1.777
```

### If you used `/dev/crop-compare` in a live environment

It now needs `ADMIN` or `ALL_DEV_ADMIN` outside dev mode (it is open to anyone in dev mode, as
before).

### If you run `PublishCropDataTask`

- Silverstripe 6: `vendor/bin/sake tasks:PublishCropDataTask` (Silverstripe 5 is unchanged:
  `dev/tasks/PublishCropDataTask`).
- After a batch of 100 it now reports "please run again" and exits normally, instead of ending the
  PHP process mid-request.

### If you subclass or extend this module's classes

- `ImageCropperExtension` extends `SilverStripe\Core\Extension` instead of
  `SilverStripe\ORM\DataExtension`. A subclass that reaches `DataExtension` methods through
  `parent::` (other than `onBeforeWrite()`, which this class still defines) must drop those calls.
- `PublishCropDataTask::run()` / `execute()` come from the `PublishCropDataTaskEntryPoint` trait;
  the work is in the public `publishMissingCropData(callable $writeln): array`.
