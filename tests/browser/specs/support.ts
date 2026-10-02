import { test as base, expect, type Locator, type Page, type Request } from '@playwright/test';

// Shared fixtures and helpers for the focuspointcropper specs.
//
// The images are seeded on every dev/build by tests/browser/fixtures/FpcBSeed.php (copied into the
// scratch host by the runner): folder "fpc-browser" with three 800x600 PNGs, "Fpc crop" without a
// crop, "Fpc preset" with a stored crop region, and "Fpc drag" without a crop, used only by the
// spec that drags the crop box and saves (so the stored crop does not leak into the other specs).
// The module sets the FocusPointField preview to at most 400x300, so preview pixels are half the
// original pixels.

/**
 * test, extended with an automatic console guard: every spec fails if the page logs a console
 * error or throws an uncaught exception at any point, page load included. "Failed to load
 * resource" (any 4xx/5xx asset or request) arrives as a console error too, so a missing module
 * script or stylesheet is caught here as well. Warnings (the admin's own Apollo deprecation
 * notices) and the cropper's console.log do not count.
 */
export const test = base.extend<{ consoleGuard: void }>({
    consoleGuard: [
        async ({ page }, use, testInfo) => {
            const errors: string[] = [];
            page.on('console', (msg) => {
                if (msg.type() === 'error') {
                    errors.push(`console.error: ${msg.text()} (${msg.location().url})`);
                }
            });
            page.on('pageerror', (err) => errors.push(`uncaught: ${err.message}`));

            await use();

            if (errors.length) {
                await testInfo.attach('console-errors', { body: errors.join('\n'), contentType: 'text/plain' });
            }
            expect(errors, 'no console errors or uncaught exceptions').toEqual([]);
        },
        { auto: true },
    ],
});

export { expect };

/** Size of the seeded originals and of the FocusPointField preview the cropper works on. */
export const ORIGINAL = { width: 800, height: 600 };
export const PREVIEW = { width: 400, height: 300 };

/**
 * Open a seeded image's edit form the way an editor does: the asset admin, the fixture folder,
 * then the image's tile. Waits until the cropper has initialised on the preview, and returns the
 * image's ID (from the editor URL, /admin/assets/show/<folder>/edit/<id>).
 */
export async function openImage(page: Page, title: string): Promise<number> {
    await page.goto('/admin/assets');
    await page.locator('.gallery-item--folder', { hasText: 'fpc-browser' }).click();
    const tile = page.locator('.gallery-item', { hasText: title });
    await expect(tile).toBeVisible();
    await tile.locator('.gallery-item__thumbnail').click();
    await expect(page).toHaveURL(/\/admin\/assets\/show\/\d+\/edit\/\d+/);
    await expect(cropBox(page)).toBeVisible();
    // The editor form panel scrolls; bring the preview into the middle of the viewport so the
    // pointer can reach every part of it (the editor's action bar covers the bottom edge).
    await cropContainer(page).evaluate((e) => e.scrollIntoView({ block: 'center' }));
    return Number(page.url().match(/\/edit\/(\d+)/)![1]);
}

/** The Cropper.js container the module builds on top of the FocusPointField preview. */
export function cropContainer(page: Page): Locator {
    return page.locator('#Form_fileEditForm_FocusPoint_Holder .cropper-container');
}

/** The crop box (the selected region) inside the cropper. */
export function cropBox(page: Page): Locator {
    return cropContainer(page).locator('.cropper-crop-box');
}

/** The crop box position and size, relative to the preview, in preview pixels. */
export async function cropBoxRect(page: Page): Promise<{ x: number; y: number; width: number; height: number }> {
    const c = (await cropContainer(page).boundingBox())!;
    const b = (await cropBox(page).boundingBox())!;
    return { x: b.x - c.x, y: b.y - c.y, width: b.width, height: b.height };
}

/** The CropData form field (a text field the module hides off-screen with CSS). */
export function cropDataField(page: Page): Locator {
    return page.locator('#Form_fileEditForm_CropData');
}

/**
 * Wait for the asset admin's save of this file: a POST to /admin/assets/fileEditForm/<id>.
 */
export function waitForSave(page: Page, id: number): Promise<Request> {
    const url = new RegExp(`/admin/assets/fileEditForm/${id}(\\?|$)`);
    return page.waitForRequest((r) => r.method() === 'POST' && url.test(r.url()));
}

/** Assert a save was an AJAX request answered with 200. */
export async function expectAjaxOk(request: Request): Promise<void> {
    expect(['xhr', 'fetch'], 'the save is an AJAX request').toContain(request.resourceType());
    const response = await request.response();
    expect(response?.status(), 'save response status').toBe(200);
}

/** A field value of a form POST body (application/x-www-form-urlencoded). */
export function postedField(request: Request, name: string): string | null {
    return new URLSearchParams(request.postData() ?? '').get(name);
}

/**
 * Record every DOCUMENT request of the main frame from now on: a save must go through the
 * admin's own AJAX request, never by replacing the page.
 */
export function watchDocumentNavigations(page: Page): () => string[] {
    const seen: string[] = [];
    page.on('request', (r) => {
        if (r.isNavigationRequest() && r.frame() === page.mainFrame()) {
            seen.push(`${r.method()} ${r.url()}`);
        }
    });
    return () => [...seen];
}
