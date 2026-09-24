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
