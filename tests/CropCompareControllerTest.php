<?php

namespace Restruct\ImageCropper\Tests;

use Restruct\SilverStripe\ImageCropper\Controllers\CropCompareController;
use SilverStripe\Assets\File;
use SilverStripe\Control\Director;
use SilverStripe\Control\Middleware\ConfirmationMiddleware;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Kernel;
use SilverStripe\Dev\DevelopmentAdmin;
use SilverStripe\Dev\FunctionalTest;

/**
 * /dev/crop-compare: the development page that renders Cropped* manipulations side by side.
 *
 * Reached through DevelopmentAdmin, whose registration key differs per major (SS5
 * `registered_controllers`, SS6 `controllers`); these requests go through the real routing, so
 * they fail if the registration for the running major is wrong.
 */
class CropCompareControllerTest extends FunctionalTest
{
    use CropperTestHelpers;

    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->activateTestAssetStore();
    }

    protected function tearDown(): void
    {
        $this->resetTestAssetStore();
        parent::tearDown();
    }

    public function testRegisteredUnderTheKeyThisMajorReads(): void
    {
        // PolyOutput is the same version switch the YAML uses
        $isSS6 = class_exists('SilverStripe\\PolyExecution\\PolyOutput');
        $readKey = $isSS6 ? 'controllers' : 'registered_controllers';
        $classKey = $isSS6 ? 'class' : 'controller';

        $registered = DevelopmentAdmin::config()->get($readKey);

        $this->assertArrayHasKey('crop-compare', $registered);
        $this->assertSame(CropCompareController::class, $registered['crop-compare'][$classKey]);
    }

    public function testSetupPageRendersForAnAdmin(): void
    {
        $this->logInWithPermission('ADMIN');

        $response = $this->get('dev/crop-compare');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Crop Functionality Test', $response->getBody());
    }

    public function testComparisonRendersForGivenImages(): void
    {
        // Exercises the comparison path: ArrayList/ArrayData construction and every manipulation
        $this->logInWithPermission('ADMIN');
        $cropped = $this->makeQuadrantImage('cmp1.png', ['CropData' => $this->cropData(50, 37, 100, 75)]);
        $other = $this->makeQuadrantImage('cmp2.png', ['CropData' => $this->cropData(50, 37, 100, 75)]);

        $response = $this->get('dev/crop-compare?svg=' . $cropped->ID . '&png=' . $other->ID);

        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getBody();
        $this->assertStringContainsString('CroppedFill(100, 100)', $body);
        // CroppedImage() of the 100x75 CropData region
        $this->assertStringContainsString('100x75', $body);
        $this->assertStringNotContainsString('Error:', $body);
    }

    /**
     * Regression (#6): without restruct/silverstripe-svg-images the install wrote croptest.svg,
     * which the asset store refuses ("Extension 'svg' is not allowed"), so no test image was
     * installed and the bundled comparison never ran. Now the SVG sample is skipped there.
     */
    public function testInstallWorksWithoutSvgSupport(): void
    {
        // The main CI legs do not install svg-images (the svg-images legs and a local host with
        // require-dev do); on a host that does, this test says nothing about #6, so it skips
        // rather than passing for the wrong reason.
        if (class_exists('Restruct\\Silverstripe\\SVG\\SVGImage')) {
            $this->markTestSkipped('restruct/silverstripe-svg-images is installed');
        }
        $this->logInWithPermission('ADMIN');

        $response = $this->get('dev/crop-compare?install=1');

        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getBody();
        $this->assertStringNotContainsString('Install failed', $body);
        // The comparison page for the bundled PNG: CroppedImage() of its 100x75 CropData region
        $this->assertStringContainsString('CroppedFill(100, 100)', $body);
        $this->assertStringContainsString('100x75', $body);
        $this->assertStringContainsString('SVG tests need', $body);
        $this->assertStringNotContainsString('Error:', $body);
        $this->assertSame(1, File::get()->filter('Name', 'croptest.png')->count());
        $this->assertSame(0, File::get()->filter('Name', 'croptest.svg')->count());

        // Installing again finds the install complete and adds no second PNG
        $this->get('dev/crop-compare?install=1');
        $this->assertSame(1, File::get()->filter('Name:StartsWith', 'croptest')->count());

        // ...and removing takes it out again
        $this->get('dev/crop-compare?remove=1');
        $this->assertSame(0, File::get()->filter('Name:StartsWith', 'croptest')->count());
    }

    /**
     * With svg-images installed (#8): a PNG-only install, made while the site disallowed 'svg',
     * is completed with the SVG once 'svg' is allowed again, without a second PNG.
     *
     * Guards two things the suite did not see before #8: the File::getAllowedExtensions() check
     * in svgSamplesSupported() (without it the first install writes croptest.svg anyway, on a site
     * that disallows the extension; measured 2026-10-12, the store does not refuse it on this
     * path), and the $installPNG guard in installTestImages() (without it the second install
     * writes croptest-v2.png next to the first PNG).
     */
    public function testAPngOnlyInstallIsCompletedWithTheSvgOnceSvgIsAllowed(): void
    {
        $this->skipUnlessSvgImagesInstalled();
        $this->logInWithPermission('ADMIN');
        # A successful install answers with a redirect to the page, a failed one renders the setup
        # page with the error (200); not following the redirect keeps the two apart.
        $this->autoFollowRedirection = false;

        # svg-images allows 'svg'; a site may still switch it off, which is the PNG-only case
        File::config()->merge('allowed_extensions', ['svg' => false]);
        $this->assertNotContains('svg', File::getAllowedExtensions(), 'precondition: svg disallowed');

        $response = $this->get('dev/crop-compare?install=1');
        $this->assertSame(302, $response->getStatusCode(), 'install must succeed: ' . $response->getBody());
        $this->assertSame(['croptest.png'], $this->installedTestFileNames());

        File::config()->merge('allowed_extensions', ['svg' => true]);
        $this->assertContains('svg', File::getAllowedExtensions(), 'precondition: svg allowed again');

        $response = $this->get('dev/crop-compare?install=1');
        $this->assertSame(302, $response->getStatusCode(), 'install must succeed: ' . $response->getBody());
        # Exactly the two samples: no croptest-v2.png from installing the PNG a second time
        $this->assertSame(['croptest.png', 'croptest.svg'], $this->installedTestFileNames());
        $this->assertInstanceOf(
            'Restruct\\Silverstripe\\SVG\\SVGImage',
            File::get()->filter('Name', 'croptest.svg')->first()
        );
    }

    /**
     * With svg-images installed (#8): when the SVG sample is there and the PNG is not, installing
     * adds the PNG only. Guards the $installSVG guard in installTestImages(), the mirror image of
     * the $installPNG one: without it this writes croptest-v2.svg.
     */
    public function testInstallAddsOnlyTheMissingPngWhenTheSvgIsThere(): void
    {
        $this->skipUnlessSvgImagesInstalled();
        $this->logInWithPermission('ADMIN');
        $this->autoFollowRedirection = false;

        $this->get('dev/crop-compare?install=1');
        $this->assertSame(['croptest.png', 'croptest.svg'], $this->installedTestFileNames());

        # Take the PNG away, as a user deleting it in the CMS would
        File::get()->filter('Name', 'croptest.png')->first()->doArchive();
        $this->assertSame(['croptest.svg'], $this->installedTestFileNames());

        $response = $this->get('dev/crop-compare?install=1');
        $this->assertSame(302, $response->getStatusCode(), 'install must succeed: ' . $response->getBody());
        $this->assertSame(['croptest.png', 'croptest.svg'], $this->installedTestFileNames());
    }

    /**
     * With svg-images installed (#8): a fresh install writes both samples and the page renders an
     * SVG column next to the PNG one, without the "SVG tests need svg-images" note. The SVG is
     * the controller's own generated 200x150 sample; svg-images manipulates it as XML (it never
     * reaches the raster backend), so this runs on GD alone.
     */
    public function testInstallWithSvgImagesRendersBothColumns(): void
    {
        $this->skipUnlessSvgImagesInstalled();
        $this->logInWithPermission('ADMIN');

        # Followed redirect: this is the comparison page for the bundled samples
        $response = $this->get('dev/crop-compare?install=1');

        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getBody();
        $this->assertStringNotContainsString('Install failed', $body);
        $this->assertStringNotContainsString('Error:', $body);
        $this->assertStringNotContainsString('svg-unsupported-note', $body);
        $this->assertSame(['croptest.png', 'croptest.svg'], $this->installedTestFileNames());
        # An SVG variant is linked from the page, so the SVG column rendered results
        $this->assertMatchesRegularExpression('#croptest__[^"\']*\.svg#', $body);

        # Removing takes both samples out again
        $this->get('dev/crop-compare?remove=1');
        $this->assertSame([], $this->installedTestFileNames());
    }

    /**
     * The svg-images tests above say nothing on a host without it (the #6 test covers that one),
     * so they skip there rather than fail.
     */
    private function skipUnlessSvgImagesInstalled(): void
    {
        if (!class_exists('Restruct\\Silverstripe\\SVG\\SVGImage')) {
            $this->markTestSkipped('needs restruct/silverstripe-svg-images (require-dev)');
        }
    }

    /**
     * Names of the installed sample files, sorted, so a duplicate shows up as e.g. croptest-v2.png.
     */
    private function installedTestFileNames(): array
    {
        $names = File::get()->filter('Name:StartsWith', 'croptest')->column('Name');
        sort($names);
        return $names;
    }

    /**
     * Regression: the controller relied on DevelopmentAdmin to authorise it ("Security handled by
     * DevelopmentAdmin middleware"). DevelopmentAdmin only refuses a user who can see NO dev link
     * at all, so in live mode anyone holding e.g. BUILDTASK_CAN_RUN (who can see dev/tasks) was
     * handed through - and ?install=1 writes test images to the asset store.
     */
    public function testNonAdminIsRefusedInLiveMode(): void
    {
        $kernel = Injector::inst()->get(Kernel::class);
        $previous = $kernel->getEnvironment();
        $kernel->setEnvironment('live');

        // SS6 answers this user with a redirect to the dev-URL confirmation page first; SS5 hands
        // the request straight through. The confirmation step is a confirm-this-URL safeguard a
        // user can click through, not an access check, so it is taken out of the Director's
        // middleware for this request (restored below) and the request reaches the controller on
        // both majors - otherwise deleting the guard would stay green on SS6. Redirects are not
        // followed: a refusal may be a login redirect.
        $this->autoFollowRedirection = false;
        $director = Director::singleton();
        $middlewares = $director->getMiddlewares();
        $director->setMiddlewares(array_filter(
            $middlewares,
            fn ($middleware) => !$middleware instanceof ConfirmationMiddleware
        ));

        try {
            $this->logInWithPermission('BUILDTASK_CAN_RUN');
            $response = $this->get('dev/crop-compare?install=1');

            // Guards the bypass itself: if core puts another confirmation step in front of the
            // controller, this test must say so rather than silently stop reaching the guard.
            $this->assertStringNotContainsString(
                'dev/confirm',
                (string)$response->getHeader('Location'),
                'the request must reach the controller, not stop at the dev-URL confirmation'
            );

            // No status-code assertion: what a refusal looks like (403, login redirect) is
            // core's business. What matters is that the page and its side effects never run.
            $this->assertStringNotContainsString('Crop Functionality Test', (string)$response->getBody());
            $this->assertSame(
                0,
                File::get()->filter('Name', ['croptest.svg', 'croptest.png'])->count(),
                'a refused request must not install test images'
            );
        } finally {
            $director->setMiddlewares($middlewares);
            $kernel->setEnvironment($previous);
        }
    }

    public function testCanInitRequiresAdminOutsideDevMode(): void
    {
        $kernel = Injector::inst()->get(Kernel::class);
        $previous = $kernel->getEnvironment();
        try {
            $kernel->setEnvironment('live');

            $this->logInWithPermission('BUILDTASK_CAN_RUN');
            $this->assertFalse(CropCompareController::create()->canInit(), 'non-admin, live');

            $this->logInWithPermission('ADMIN');
            $this->assertTrue(CropCompareController::create()->canInit(), 'admin, live');

            $this->logOut();
            $kernel->setEnvironment('dev');
            $this->assertTrue(CropCompareController::create()->canInit(), 'anyone, dev mode');
        } finally {
            $kernel->setEnvironment($previous);
        }
    }
}
