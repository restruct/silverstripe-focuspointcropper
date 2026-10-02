import {
    test,
    expect,
    ORIGINAL,
    PREVIEW,
    cropBox,
    cropBoxRect,
    cropContainer,
    cropDataField,
    expectAjaxOk,
    openImage,
    postedField,
    waitForSave,
    watchDocumentNavigations,
} from './support';

// The crop field in the asset admin's file edit form: ImageCropperFormFactoryExtension adds the
// CropData field and the cropper config, and client/dist/js/sscropper.js turns the FocusPointField
// preview into a Cropper.js crop box that shares the preview with the focus point picker.

test('the edit form of an image gets the crop field and the cropper on the focus point preview', async ({ page }) => {
    const assets: string[] = [];
    page.on('response', (r) => {
        if (/silverstripe-focuspointcropper\/client\/dist\//.test(r.url())) {
            assets.push(`${r.status()} ${new URL(r.url()).pathname}`);
        }
    });

    await openImage(page, 'Fpc crop');

    // The module's script and stylesheet are exposed and load (LeftAndMain extra requirements).
    expect(assets.some((a) => /^200 .*\/js\/sscropper\.js$/.test(a)), `sscropper.js loaded: ${assets}`).toBe(true);
    expect(assets.some((a) => /^200 .*\/styles\/sscropper\.css$/.test(a)), `sscropper.css loaded: ${assets}`).toBe(true);

    // The CropData field is in the form, empty for an image without a crop, and hidden off-screen
    // by the module's stylesheet (not display:none, which the admin's React form would not post).
    await expect(cropDataField(page)).toHaveCount(1);
    await expect(cropDataField(page)).toHaveValue('');
    const holderLeft = await page.locator('#Form_fileEditForm_CropData_Holder').evaluate((e) => e.getBoundingClientRect().right);
    expect(holderLeft, 'CropData holder is moved off-screen').toBeLessThan(0);

    // The sizes the script scales the crop with: the original and the preview it works on.
    const config = page.locator('input[name="CropperConfig"]');
    expect(JSON.parse((await config.getAttribute('data-cropsizing'))!)).toMatchObject({
        originalWidth: ORIGINAL.width,
        originalHeight: ORIGINAL.height,
        previewWidth: PREVIEW.width,
        previewHeight: PREVIEW.height,
    });
    // The module's default cropconfig, passed through to Cropper.js as-is.
    expect(JSON.parse((await config.getAttribute('data-cropconfig'))!)).toEqual({
        autoCropArea: 1,
        movable: false,
        rotatable: false,
        scalable: false,
        zoomable: false,
    });

    // Cropper.js runs on the preview at the preview's size, and without a stored crop the crop box
    // covers the whole image (autoCropArea 1).
    const container = (await cropContainer(page).boundingBox())!;
    expect(Math.round(container.width)).toBe(PREVIEW.width);
    expect(Math.round(container.height)).toBe(PREVIEW.height);
    const rect = await cropBoxRect(page);
    expect(Math.round(rect.x)).toBe(0);
    expect(Math.round(rect.y)).toBe(0);
    expect(Math.round(rect.width)).toBe(PREVIEW.width);
    expect(Math.round(rect.height)).toBe(PREVIEW.height);

    // The focus point picker's click layer is moved INTO the crop box's view, on top of the
    // preview, so clicks reach the focus point picker instead of Cropper.js.
    const overlay = cropBox(page).locator('.cropper-view-box .focuspoint-picker__overlay');
    await expect(overlay).toHaveCount(1);
    const o = (await overlay.boundingBox())!;
    expect(Math.round(o.x - container.x)).toBe(0);
    expect(Math.round(o.y - container.y)).toBe(0);
    expect(Math.round(o.width)).toBe(PREVIEW.width);
});

test('an image with stored CropData opens with the crop box on that region', async ({ page }) => {
    await openImage(page, 'Fpc preset');

    // The fixture's region is x 200-600, y 150-450 of the 800x600 original: x 100-300, y 75-225
    // on the 400x300 preview. The script converts the original-pixel values back to the preview.
    const stored = JSON.parse(await cropDataField(page).inputValue());
    expect(stored).toMatchObject({ originalX: 200, originalY: 150, originalWidth: 400, originalHeight: 300 });

    // Cropper.js shows the full-size crop box first and the script moves it in its ready
    // callback, after the preview image has loaded: poll until it settles.
    await expect
        .poll(async () => {
            const r = await cropBoxRect(page);
            return [r.x, r.y, r.width, r.height].map(Math.round);
        })
        .toEqual([100, 75, 200, 150]);
});

test('clicking inside the crop box sets the focus point, and saving stores it', async ({ page }) => {
    const id = await openImage(page, 'Fpc crop');
    const x = page.locator('#Form_fileEditForm_FocusPointX');
    const y = page.locator('#Form_fileEditForm_FocusPointY');
    const before = Number(await x.inputValue());

    // Click a spot on the preview that differs from the stored focus point (an earlier repeat of
    // this spec may already have stored the first one).
    const spot = Math.abs(before - 0.5) < 0.01 ? { fx: 0.25, fy: 0.75 } : { fx: 0.75, fy: 0.25 };
    // FocusPoint's own coordinates: -1..1 from the left and from the top (DBFocusPoint: offset * 2 - 1).
    const expected = { x: spot.fx * 2 - 1, y: spot.fy * 2 - 1 };

    // The click lands on the crop box, which covers the whole preview; the module moved the focus
    // picker's click layer into it, so the focus point picker must still receive the click.
    const c = (await cropContainer(page).boundingBox())!;
    await page.mouse.click(c.x + c.width * spot.fx, c.y + c.height * spot.fy);
    await expect.poll(async () => Number(await x.inputValue())).toBeCloseTo(expected.x, 1);
    await expect.poll(async () => Number(await y.inputValue())).toBeCloseTo(expected.y, 1);
    // The click does not move or resize the crop box.
    const rect = await cropBoxRect(page);
    expect(Math.round(rect.width)).toBe(PREVIEW.width);
    expect(Math.round(rect.height)).toBe(PREVIEW.height);

    const navigations = watchDocumentNavigations(page);
    const saved = waitForSave(page, id);
    await page.locator('#Form_fileEditForm_action_save').click();
    const request = await saved;
    await expectAjaxOk(request);
    expect(Number(postedField(request, 'FocusPointX'))).toBeCloseTo(expected.x, 1);
    expect(Number(postedField(request, 'FocusPointY'))).toBeCloseTo(expected.y, 1);
    expect(navigations(), 'document navigations after Save').toEqual([]);

    // A fresh load shows the stored values.
    await openImage(page, 'Fpc crop');
    expect(Number(await x.inputValue())).toBeCloseTo(expected.x, 1);
    expect(Number(await y.inputValue())).toBeCloseTo(expected.y, 1);
});

// Regression test for #5: the cropend handler used to set the CropData input with jQuery, and the
// admin's React form reset it to its own (empty) state right after, so the crop never reached the
// POST. It now writes through the native value setter and dispatches an 'input' event.
// https://github.com/restruct/silverstripe-focuspointcropper/issues/5
test('dragging a crop handle writes CropData and saving stores it', async ({ page }) => {
    const id = await openImage(page, 'Fpc drag');

    // Drag the top-left handle 100px right and 50px down on the 400x300 preview: the crop becomes
    // x 100-400, y 50-300 on the preview, which is x 200-800, y 100-600 of the 800x600 original.
    const handle = cropBox(page).locator('.cropper-point.point-nw');
    const start = (await cropBoxRect(page));
    const h = (await handle.boundingBox())!;
    const from = { x: h.x + h.width / 2, y: h.y + h.height / 2 };
    await page.mouse.move(from.x, from.y);
    await page.mouse.down();
    await page.mouse.move(from.x + 50, from.y + 25, { steps: 5 });
    await page.mouse.move(from.x + 100 - start.x, from.y + 50 - start.y, { steps: 5 });
    await page.mouse.up();

    const rect = await cropBoxRect(page);
    expect(Math.round(rect.x)).toBeCloseTo(100, -1);
    expect(Math.round(rect.y)).toBeCloseTo(50, -1);

    // The field holds the crop in original pixels (twice the preview here).
    await expect.poll(async () => cropDataField(page).inputValue()).not.toBe('');
    const data = JSON.parse(await cropDataField(page).inputValue());
    expect(data.originalX).toBeCloseTo(rect.x * 2, -1);
    expect(data.originalY).toBeCloseTo(rect.y * 2, -1);
    expect(data.originalWidth).toBeCloseTo(rect.width * 2, -1);
    expect(data.originalHeight).toBeCloseTo(rect.height * 2, -1);

    const saved = waitForSave(page, id);
    await page.locator('#Form_fileEditForm_action_save').click();
    const request = await saved;
    await expectAjaxOk(request);
    expect(JSON.parse(postedField(request, 'CropData') || 'null'), 'CropData in the save POST').toMatchObject({
        originalX: data.originalX,
        originalY: data.originalY,
    });

    // Reopened, the cropper starts on the saved region.
    await openImage(page, 'Fpc drag');
    await expect
        .poll(async () => {
            const r = await cropBoxRect(page);
            return [r.x, r.y].map(Math.round);
        })
        .toEqual([Math.round(rect.x), Math.round(rect.y)]);
});
