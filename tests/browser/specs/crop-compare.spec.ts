import { test, expect, openImage } from './support';

// /dev/crop-compare, the module's development page that renders every Cropped* manipulation next
// to the plain one. Its registration with DevelopmentAdmin differs per major (registered_controllers
// on 5, controllers on 6; on 6 it used to 404 silently), and it is the one place a browser sees the
// Cropped* methods' output.

test('the crop-compare tool is listed on /dev and opens', async ({ page }) => {
    await page.goto('/dev');
    const link = page.locator('a[href$="/dev/crop-compare"], a[href="dev/crop-compare"]');
    await expect(page.locator('body')).toContainText('Test focuspoint crop functionality');
    await expect(link).toHaveCount(1);

    const response = await page.goto('/dev/crop-compare');
    expect(response?.status()).toBe(200);
    await expect(page.locator('h1')).toHaveText('Crop Functionality Test');
});

test('Cropped* methods apply the stored crop region', async ({ page }) => {
    // The "Fpc preset" image stores the region x 200-600, y 150-450 of its 800x600 original. The
    // page takes any two image IDs; the same image serves both columns here (no svg-images module).
    const id = await openImage(page, 'Fpc preset');
    const response = await page.goto(`/dev/crop-compare?svg=${id}&png=${id}`);
    expect(response?.status()).toBe(200);

    // No manipulation failed, and every variant was generated and loads.
    await expect(page.locator('td', { hasText: /^Error:/ })).toHaveCount(0);
    const images = page.locator('td.result-cell img');
    expect(await images.count(), 'result images rendered').toBeGreaterThan(10);
    const broken = await images.evaluateAll((imgs) =>
        (imgs as HTMLImageElement[]).filter((i) => !i.complete || i.naturalWidth === 0).map((i) => i.alt),
    );
    expect(broken, 'result images that did not load').toEqual([]);

    // CroppedImage() is the stored region at its original resolution: 400x300 from the 800x600.
    const cropped = page.locator('td.result-cell img[alt="CroppedImage()"]').first();
    expect(await cropped.evaluate((i: HTMLImageElement) => [i.naturalWidth, i.naturalHeight])).toEqual([400, 300]);

    // ...and it is the right region: compare pixels with the fixture's shapes (FpcBSeed::png()):
    // a red circle centred at (240, 300) with radius 100, a green rectangle x 440-680, y 120-360,
    // blue elsewhere. Region pixel (50, 150) is original (250, 300): red. Region pixel (270, 20) is
    // original (470, 170): green; with x and y swapped it would be (420, 220), which is blue.
    // Region pixel (5, 5) is original (205, 155): blue.
    const pixels = await cropped.evaluate((img: HTMLImageElement) => {
        const canvas = document.createElement('canvas');
        canvas.width = img.naturalWidth;
        canvas.height = img.naturalHeight;
        const ctx = canvas.getContext('2d')!;
        ctx.drawImage(img, 0, 0);
        const at = (x: number, y: number) => Array.from(ctx.getImageData(x, y, 1, 1).data.slice(0, 3));
        return { red: at(50, 150), green: at(270, 20), blue: at(5, 5) };
    });
    expect(pixels.red, 'region pixel (50, 150)').toEqual([231, 76, 60]);
    expect(pixels.green, 'region pixel (270, 20)').toEqual([46, 204, 113]);
    expect(pixels.blue, 'region pixel (5, 5)').toEqual([52, 152, 219]);

    // Every row marked "cropped" is a variant of the cropped region, every other row is not.
    const rows = await page.locator('tbody tr').evaluateAll((trs) =>
        trs.map((tr) => ({
            cropped: tr.classList.contains('cropped-row'),
            label: (tr.querySelector('td') as HTMLElement).innerText.split('\n')[0].trim(),
            src: (tr.querySelector('td.result-cell img') as HTMLImageElement | null)?.src ?? '',
        })),
    );
    for (const row of rows) {
        expect(row.src.includes('__cropped'), `${row.label}: ${row.src}`).toBe(row.cropped);
    }
});

// Regression: without restruct/silverstripe-svg-images the install wrote an .svg that the asset
// store refuses ("Extension 'svg' is not allowed"), so the bundled comparison never ran. The SVG
// sample is now skipped there and the page shows the PNG column only.
// https://github.com/restruct/silverstripe-focuspointcropper/issues/6
test('installing the bundled test images works without svg-images', async ({ page }) => {
    await page.goto('/dev/crop-compare?install=1');
    await expect(page.locator('.alert-danger')).toHaveCount(0);
    await expect(page.locator('td.result-cell img').first()).toBeVisible();
    // Only the PNG column, with the note saying why the SVG one is missing
    await expect(page.locator('.svg-unsupported-note')).toBeVisible();
    await expect(page.locator('.card-header', { hasText: 'SVG Image' })).toHaveCount(0);

    // Leave the host as found: the next run starts from the setup page again
    await page.goto('/dev/crop-compare?remove=1');
    await expect(page.locator('a', { hasText: 'Install Test Images' })).toBeVisible();
});
