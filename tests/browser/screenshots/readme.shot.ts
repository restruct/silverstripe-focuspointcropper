import { test, expect, type Page } from '@playwright/test';
import * as path from 'path';

// Generates the README screenshots in docs/images/. Not a test suite: run it with
// playwright.screenshots.config.ts (see README.md next to this file). The content comes from the
// browser fixtures: FpcShowcase seeds a "Showcase" folder with one drawn landscape that has a stored
// crop region and focus point, and FpcShowcaseController renders it at /crop-showcase.

const IMAGES = path.join(__dirname, '..', '..', '..', 'docs', 'images');

/** Wait until every <img> on the page has loaded. */
async function imagesLoaded(page: Page): Promise<void> {
    await expect
        .poll(() => page.evaluate(() => Array.from(document.images).every((i) => i.complete && i.naturalWidth > 0)), {
            message: 'all images loaded',
        })
        .toBe(true);
}

/**
 * Hide what only the test host has: the browser specs' "fpc-browser" folder in Files.
 * Presentation only; nothing about the module's own UI is changed.
 */
async function hideFixtureChrome(page: Page): Promise<void> {
    await page.addStyleTag({ content: '.gallery-item--folder:has(.gallery-item__title[title="fpc-browser"]) { display: none !important; }' });
}

test('CMS: the crop box and focus point on an image in Files', async ({ page }) => {
    // Taller than the default 800, so the whole edit form down to the crop field fits unscrolled.
    await page.setViewportSize({ width: 1280, height: 960 });
    await page.goto('/admin/assets');
    await hideFixtureChrome(page);
    await page.locator('.gallery-item--folder', { hasText: 'Showcase' }).click();
    const tile = page.locator('.gallery-item', { hasText: 'Lighthouse' });
    await tile.locator('.gallery-item__thumbnail').click();
    await expect(page).toHaveURL(/\/admin\/assets\/show\/\d+\/edit\/\d+/);

    // The cropper opens on the stored region (FpcShowcase::CROP, a third of it on the 400x267
    // preview), after the preview has loaded: wait until the crop box has moved there.
    const container = page.locator('#Form_fileEditForm_FocusPoint_Holder .cropper-container');
    const box = container.locator('.cropper-crop-box');
    await expect(box).toBeVisible();
    await expect
        .poll(async () => {
            const c = (await container.boundingBox())!;
            const b = (await box.boundingBox())!;
            return Math.round(b.x - c.x);
        })
        .toBe(113);
    await imagesLoaded(page);
    // No hover overlay on the tile just clicked, no focus highlight in a field.
    await page.mouse.move(5, 955);
    await page.evaluate(() => (document.activeElement as HTMLElement | null)?.blur());
    await page.waitForTimeout(500);

    await page.screenshot({ path: path.join(IMAGES, 'cms-cropper.png') });
});

test('Front end: Fill() next to CroppedFocusFill()', async ({ page }) => {
    await page.goto('/crop-showcase');
    await imagesLoaded(page);
    await page.locator('#showcase').screenshot({ path: path.join(IMAGES, 'frontend-crop.png') });
});

test('/dev/crop-compare: every Cropped* method next to the plain one', async ({ page }) => {
    // Install the bundled test images (a no-op when they are there already).
    await page.goto('/dev/crop-compare?install=1');
    await page.goto('/dev/crop-compare');
    await expect(page.locator('td.result-cell img').first()).toBeVisible();
    await imagesLoaded(page);
    await page.waitForTimeout(300);
    // The top of the page: both originals with their crop data, and the first rows of the table.
    await page.screenshot({ path: path.join(IMAGES, 'crop-compare.png'), clip: { x: 0, y: 0, width: 1280, height: 1060 }, fullPage: true });
});
