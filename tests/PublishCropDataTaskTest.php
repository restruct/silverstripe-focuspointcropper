<?php

namespace Restruct\ImageCropper\Tests;

use PublishCropDataTask;
use SilverStripe\Assets\Image;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;

/**
 * PublishCropDataTask: republishes images whose draft has CropData and whose live version has none.
 */
class PublishCropDataTaskTest extends SapphireTest
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

    private function liveCropData(Image $image): ?string
    {
        $live = Versioned::get_by_stage(Image::class, Versioned::LIVE)->byID($image->ID);

        return $live ? $live->CropData : null;
    }

    /**
     * A published image whose CropData was then set on draft only.
     */
    private function publishedWithDraftCropData(string $name): Image
    {
        $image = $this->makeQuadrantImage($name);
        $image->CropData = $this->cropData(0, 0, 100, 75);
        $image->write();

        return $image;
    }

    private function runTask(PublishCropDataTask $task): array
    {
        $lines = [];
        $result = $task->publishMissingCropData(function (string $line) use (&$lines) {
            $lines[] = $line;
        });
        $result['lines'] = $lines;

        return $result;
    }

    /**
     * The class has to load on this major at all: on Silverstripe 6 the 2.x version redeclared
     * BuildTask's typed $title and static $description, a fatal on class load.
     */
    public function testTaskLoadsAndDescribesItself(): void
    {
        $task = PublishCropDataTask::create();

        $this->assertSame('Hydrate Live images missing crop data', $task->getTitle());
        $this->assertStringContainsString('missing CropData', $task->getDescription());
    }

    public function testPublishesDraftCropDataOfPublishedImages(): void
    {
        $image = $this->publishedWithDraftCropData('a.png');
        $this->assertNull($this->liveCropData($image), 'precondition: live has no CropData');

        $result = $this->runTask(PublishCropDataTask::create());

        $this->assertSame(1, $result['found']);
        $this->assertSame(1, $result['published']);
        $this->assertFalse($result['more']);
        $this->assertSame($this->cropData(0, 0, 100, 75), $this->liveCropData($image));
    }

    public function testLeavesImagesWithoutDraftCropDataAlone(): void
    {
        $image = $this->makeQuadrantImage('plain.png');
        $liveVersion = Versioned::get_by_stage(Image::class, Versioned::LIVE)->byID($image->ID)->Version;

        $result = $this->runTask(PublishCropDataTask::create());

        $this->assertSame(0, $result['found']);
        $this->assertSame(
            $liveVersion,
            Versioned::get_by_stage(Image::class, Versioned::LIVE)->byID($image->ID)->Version
        );
    }

    public function testLeavesImagesWhoseLiveVersionAlreadyHasCropDataAlone(): void
    {
        $image = $this->makeQuadrantImage('done.png', ['CropData' => $this->cropData(0, 0, 100, 75)]);
        // A newer draft crop that the editor has not published: not this task's business
        $image->CropData = $this->cropData(100, 0, 100, 75);
        $image->write();

        $result = $this->runTask(PublishCropDataTask::create());

        $this->assertSame(0, $result['found']);
        $this->assertSame($this->cropData(0, 0, 100, 75), $this->liveCropData($image));
    }

    /**
     * Pins the live-stage query: an image that was never published has no live row, so it is
     * never selected. The isPublished() guard in the loop is a second line of defence; checking
     * 'published' alone could not tell the two apart (that guard alone kept it at 0).
     */
    public function testNeverPublishedImagesAreNotSelectedByTheLiveStageQuery(): void
    {
        $draft = $this->makeQuadrantImage('draft.png', ['CropData' => $this->cropData(0, 0, 100, 75)], false);

        $result = $this->runTask(PublishCropDataTask::create());

        $this->assertSame(0, $result['found']);
        $this->assertSame(0, $result['published']);
        $this->assertFalse($draft->isPublished());
    }

    public function testStopsAfterOneChunkAndSaysSo(): void
    {
        $this->publishedWithDraftCropData('c1.png');
        $this->publishedWithDraftCropData('c2.png');
        $task = PublishCropDataTask::create();
        $task->chunksize = 1;

        $result = $this->runTask($task);

        $this->assertSame(2, $result['found']);
        $this->assertSame(1, $result['published']);
        $this->assertTrue($result['more']);
        $this->assertStringContainsString('run again', implode("\n", $result['lines']));

        // A second run picks up the rest
        $second = $this->runTask($task);
        $this->assertSame(1, $second['found']);
        $this->assertSame(1, $second['published']);
        $this->assertFalse($second['more']);
    }
}
