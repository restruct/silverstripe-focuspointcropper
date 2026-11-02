<?php

namespace Restruct\FpcBrowser;

use SilverStripe\Assets\Image;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPResponse;

/**
 * BROWSER-TEST FIXTURE ONLY - /crop-showcase (route in _config/fpc-showcase.yml), the front end of
 * the README screenshot. Renders its own HTML (the scratch host has no theme): the seeded
 * FpcShowcase image with its stored crop region and focus point drawn over it, and core's Fill()
 * next to this module's CroppedFocusFill() at two sizes, each labelled with the call and the size
 * of the file it returned.
 */
class FpcShowcaseController extends Controller
{
    public function index()
    {
        $image = Image::get()->filter('FileFilename', FpcShowcase::FILENAME)->first();
        if (!$image) {
            return HTTPResponse::create('Showcase image not seeded (run dev/build)', 404);
        }

        # The original at 40%, with the stored crop region and focus point drawn on top.
        $scale = 0.4;
        $crop = json_decode((string) $image->CropData);
        $fx = ($image->FocusPointX + 1) / 2 * $image->getWidth() * $scale;
        $fy = ($image->FocusPointY + 1) / 2 * $image->getHeight() * $scale;
        $original = sprintf(
            '<figure class="original"><span class="frame" style="width:%dpx;height:%dpx">'
            . '<img src="%s" width="%d" height="%d" alt="The uploaded image">'
            . '<span class="crop" style="left:%dpx;top:%dpx;width:%dpx;height:%dpx"></span>'
            . '<span class="focus" style="left:%dpx;top:%dpx"></span></span>'
            . '<figcaption><b>The upload, %d x %d px</b>The editor\'s crop region (dashed) and focus point (+), set in the CMS</figcaption></figure>',
            $image->getWidth() * $scale,
            $image->getHeight() * $scale,
            htmlspecialchars((string) $image->getURL()),
            $image->getWidth() * $scale,
            $image->getHeight() * $scale,
            $crop->originalX * $scale,
            $crop->originalY * $scale,
            $crop->originalWidth * $scale,
            $crop->originalHeight * $scale,
            $fx,
            $fy,
            $image->getWidth(),
            $image->getHeight()
        );

        # Core's Fill() against CroppedFocusFill(), same size: one row per size.
        $rows = '';
        foreach ([[200, 200], [400, 200]] as [$w, $h]) {
            $rows .= '<div class="pair">'
                . $this->card(".Fill({$w}, {$h})", 'core', $image->Fill($w, $h))
                . $this->card(".CroppedFocusFill({$w}, {$h})", 'this module', $image->CroppedFocusFill($w, $h))
                . '</div>';
        }

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>Crop showcase</title>
<style>
  body { margin: 0; background: #f1f5f9; color: #0f172a; font: 15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
  main { padding: 28px 32px 32px; display: inline-block; }
  h1 { font-size: 24px; margin: 0 0 4px; }
  p.lead { margin: 0 0 20px; color: #475569; max-width: 1100px; }
  .layout { display: flex; gap: 24px; align-items: flex-start; }
  .pairs { display: flex; flex-direction: column; gap: 16px; }
  .pair { display: flex; gap: 16px; align-items: flex-start; }
  figure { margin: 0; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px; }
  figure img { display: block; }
  .frame { position: relative; display: block; overflow: hidden; }
  .crop { position: absolute; border: 2px dashed #fff; box-shadow: 0 0 0 9999px rgba(15, 23, 42, .55); box-sizing: border-box; }
  .focus { position: absolute; width: 26px; height: 26px; margin: -13px 0 0 -13px; }
  .focus::before, .focus::after { content: ""; position: absolute; background: #fff; box-shadow: 0 0 0 1px rgba(15, 23, 42, .6); }
  .focus::before { left: 12px; top: 0; width: 2px; height: 26px; }
  .focus::after { top: 12px; left: 0; height: 2px; width: 26px; }
  figcaption { margin-top: 8px; font-size: 13px; color: #475569; }
  .original { width: 480px; }
  .variant { width: max-content; }
  .variant figcaption { white-space: nowrap; }
  figcaption b, figcaption code { display: block; color: #0f172a; }
  figcaption code { font: 600 13px/1.4 ui-monospace, SFMono-Regular, Menlo, monospace; }
</style></head>
<body><main id="showcase">
<h1>One upload, cropped the way the editor chose</h1>
<p class="lead">Core's <code>Fill()</code> crops around the centre of the whole image. The <code>Cropped*</code> methods start from the region the editor kept, and the focus-aware ones keep the focus point in view.</p>
<div class="layout">{$original}<div class="pairs">{$rows}</div></div>
</main></body></html>
HTML;

        $response = HTTPResponse::create($html);
        $response->addHeader('Content-Type', 'text/html; charset=utf-8');
        return $response;
    }

    /** One labelled variant: the call, what it does, and the size of the file it returned. */
    private function card(string $call, string $note, $file): string
    {
        return sprintf(
            '<figure class="variant"><img src="%s" width="%d" height="%d" alt="%s"><figcaption><code>%s</code>%s, %d x %d px</figcaption></figure>',
            htmlspecialchars((string) $file->getURL()),
            $file->getWidth(),
            $file->getHeight(),
            htmlspecialchars($call),
            htmlspecialchars($call),
            htmlspecialchars($note),
            $file->getWidth(),
            $file->getHeight()
        );
    }
}
