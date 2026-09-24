<?php

use Restruct\SilverStripe\ImageCropper\PublishCropDataTaskEntryPoint;
use SilverStripe\Assets\Image;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Dev\Debug;
use SilverStripe\ORM\ValidationException;
use SilverStripe\Versioned\Versioned;

/**
 * Republishes images whose draft has CropData but whose live version does not.
 *
 * Usage (Silverstripe 6): vendor/bin/sake tasks:PublishCropDataTask (or /dev/tasks/PublishCropDataTask)
 * Usage (Silverstripe 5): vendor/bin/sake dev/tasks/PublishCropDataTask (or /dev/tasks/PublishCropDataTask)
 *
 * The entry point (run() on SS5, execute() on SS6) and getDescription() come from
 * PublishCropDataTaskEntryPoint, because BuildTask's shape differs between the two majors in ways
 * one class body cannot satisfy - see that file for why it is done this way.
 */
class PublishCropDataTask
    extends BuildTask
{
    use PublishCropDataTaskEntryPoint;

    /**
     * Silverstripe 5 URL segment (dev/tasks/PublishCropDataTask). Inert config on Silverstripe 6,
     * where $commandName names the task instead.
     */
    private static $segment = 'PublishCropDataTask';

    /**
     * Silverstripe 6 command name (sake tasks:PublishCropDataTask). SS5's BuildTask has no such
     * property, so this is a new static there; on SS6 it is a compatible redeclaration of
     * PolyCommand::$commandName (same type, same staticness).
     */
    protected static string $commandName = 'PublishCropDataTask';

    // Was declared as properties. Silverstripe 6 types BuildTask::$title as `string` and makes
    // $description a static, so these untyped redeclarations were a fatal "must be compatible"
    // error there, on class load - and the class manifest loads every class on a flush.
    // The title is now assigned in the constructor, the description served by getDescription().
//    protected $title = 'Hydrate Live images missing crop data';
//
//    protected $description = 'Run this task to update live versions of images which are missing CropData';

    public $chunksize = 100;

    public function __construct()
    {
        parent::__construct();
        // Assigned rather than declared: untyped on SS5, `string` on SS6 (see above).
        $this->title = 'Hydrate Live images missing crop data';
    }

    /**
     * Version-neutral body of the task: republish every published image whose draft has CropData
     * and whose live version has none, at most $chunksize per run.
     *
     * @param callable $writeln function (string $line): void
     * @return array{found: int, published: int, more: bool}
     * @throws ValidationException
     */
    public function publishMissingCropData(callable $writeln): array
    {
        $result = ['found' => 0, 'published' => 0, 'more' => false];

        // Get all Live images missing CropData
        $imageIDs = Versioned::get_by_stage(Image::class, Versioned::DRAFT)
                        ->filter('CropData:not', null)->column('ID');
        if(count($imageIDs)){
            $images = Versioned::get_by_stage(Image::class, Versioned::LIVE)
                        ->filter([
                            'CropData' => null,
                            'ID' => $imageIDs,
                        ]);

            $result['found'] = $images->count();
//            print("Found {$images->count()} images to publish/hydrate<br><br>");
            $writeln("Found {$result['found']} images to publish/hydrate");

            if($images->count()){
                $count = 0;
                /** @var Image $image */
                foreach ($images as $image) {
                    $count++;
                    if($count > $this->chunksize){
                        // Was die(): that ends the whole PHP process, which on Silverstripe 6
                        // also skips sake's exit code and the task runner's closing output.
//                        die("Did {$this->chunksize} items... (please reload to process next chunk)");
                        $result['more'] = true;
                        $writeln("Did {$this->chunksize} items... (please run again to process next chunk)");
                        return $result;
                    }

                    // Skip images that aren't on the filesystem
                    if (!$image->exists()) {
                        continue;
                    }

                    // (Re)Publish
                    $draftImage = Versioned::get_by_stage(Image::class, Versioned::DRAFT)
                        ->byID($image->ID);

                    if ($draftImage->isPublished()) {
                        $draftImage->publishSingle();
                        $result['published']++;
                    }
                }
            }
        }

//        print('ALL DONE!!!');
        $writeln('ALL DONE!!!');

        return $result;
    }
}
