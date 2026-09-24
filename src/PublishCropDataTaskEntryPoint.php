<?php

namespace Restruct\SilverStripe\ImageCropper;

use SilverStripe\Control\Director;
use SilverStripe\Core\Convert;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

/*
 * Version-specific entry point for PublishCropDataTask.
 *
 * WHY THIS FILE DECLARES THE SAME TRAIT TWICE
 *
 * BuildTask changed shape between Silverstripe 5 and 6 in ways one class body cannot satisfy:
 *  - SS5: `abstract public function run($request)`, untyped `protected $title` / `$description`.
 *  - SS6: `run(InputInterface, PolyOutput): int` is concrete and `execute()` is the abstract hook;
 *    `protected string $title` and `protected static string $description` are typed, so a child
 *    redeclaring them untyped (or typed, on SS5) is a fatal "must be compatible" error.
 *
 * Two task classes with a file-level `return` guard each does NOT work for a BuildTask: the class
 * manifest still lists the guarded class as a BuildTask descendant, and the task runner reflects
 * on every descendant, which throws for a class that was never declared. So the task class is
 * declared once, and pulls its version-specific METHODS from this trait. The trait is declared
 * conditionally, which PHP allows; the class manifest does not see it (its visitor does not
 * descend into `if` blocks), and does not need to: Composer's PSR-4 autoloader resolves it by
 * file name. Same pattern as restruct/silverstripe-svg-images' ClearSVGVariantsTask.
 *
 * PolyOutput exists only on Silverstripe 6 (framework 6 `src/PolyExecution/`), so its presence is
 * the version switch. The `use` imports above are inert on SS5: an import never autoloads.
 */
if (class_exists(PolyOutput::class)) {
    /**
     * Silverstripe 6: sake command `tasks:PublishCropDataTask`, or dev/tasks/PublishCropDataTask.
     */
    trait PublishCropDataTaskEntryPoint
    {
        /**
         * SS6 declares getDescription() static (PolyCommand::getDescription()). Overridden here
         * rather than via a `$description` property: on SS5 the parent property is not static.
         */
        public static function getDescription(): string
        {
            return _t(
                static::class . '.description',
                'Run this task to update live versions of images which are missing CropData'
            );
        }

        protected function execute(InputInterface $input, PolyOutput $output): int
        {
            $this->publishMissingCropData(function (string $line) use ($output): void {
                $output->writeln($line);
            });

            return Command::SUCCESS;
        }
    }
} else {
    /**
     * Silverstripe 5: dev/tasks/PublishCropDataTask (browser or `sake dev/tasks/...`).
     */
    trait PublishCropDataTaskEntryPoint
    {
        /**
         * SS5 declares getDescription() as an instance method (and deprecates reading the
         * `$description` property through it); see the SS6 variant for why this is a method.
         *
         * @return string
         */
        public function getDescription()
        {
            return 'Run this task to update live versions of images which are missing CropData';
        }

        /**
         * @param \SilverStripe\Control\HTTPRequest $request
         * @return void
         */
        public function run($request)
        {
            $cli = Director::is_cli();

            $this->publishMissingCropData(function (string $line) use ($cli): void {
                // Same line breaks as the 2.x task's print() calls; the lines are plain text,
                // so they are escaped for the browser.
                echo $cli ? $line . PHP_EOL : Convert::raw2xml($line) . "<br><br>\n";
            });
        }
    }
}
