# README screenshots

`readme.shot.ts` writes the PNGs in `docs/images/` that the module README shows. It is not a test
suite: it runs with its own config, `playwright.screenshots.config.ts`, which the browser tests and
CI never load, so a normal test run never rewrites the images. Never edit the PNGs by hand;
regenerate them.

What it needs:

- a Silverstripe 6 host with this module and `restruct/silverstripe-svg-images` installed (the
  crop-compare screenshot shows its SVG column), and the browser fixtures from
  `tests/browser/fixtures/` copied into its `app/`, then the database built. `FpcShowcase` seeds a
  `Showcase` folder in Files with one landscape drawn with GD, with a stored crop region and focus
  point; `FpcShowcaseController` renders it at `/crop-showcase` (route in `fixtures/_config/`);
- `BROWSER_TARGET_URLS` pointing at that host (only the `ss6` target is used, else the first).

The shared runner in the maintainers' workspace does all of that:

```bash
BROWSER_ONLY=ss6 SS6_EXTRA_REQUIRE="restruct/silverstripe-svg-images:^3.1" \
  tools/browser/run.sh silverstripe-focuspointcropper --config=playwright.screenshots.config.ts
```

Against a host you started yourself, from `tests/browser/`:

```bash
npm ci
BROWSER_TARGET_URLS="ss6=http://127.0.0.1:8878" npx playwright test --config=playwright.screenshots.config.ts
```

The crop-compare screenshot installs the bundled test images (`/dev/crop-compare?install=1`) if
they are not there yet.
