<?php

namespace Restruct\ImageCropper\Tests;

use Restruct\SilverStripe\ImageCropper\ImageCropperFormFactoryExtension;
use SilverStripe\AssetAdmin\Forms\FileFormFactory;
use SilverStripe\AssetAdmin\Forms\ImageFormFactory;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Image;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\TextField;

/**
 * ImageCropperFormFactoryExtension: the fields the cropper adds to the asset-admin edit form.
 *
 * The form is built by asset-admin's own factory, so these fail if the extension stops being
 * applied or its updateFormFields() hook stops being called.
 */
class ImageCropperFormFactoryExtensionTest extends SapphireTest
{
    use CropperTestHelpers;

    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->activateTestAssetStore();
        $this->logInWithPermission('ADMIN');
    }

    protected function tearDown(): void
    {
        $this->resetTestAssetStore();
        parent::tearDown();
    }

    private function formFieldsFor(File $record): FieldList
    {
        $factory = $record instanceof Image ? ImageFormFactory::singleton() : FileFormFactory::singleton();

        return $factory->getForm(null, 'fileEditForm', ['Record' => $record])->Fields();
    }

    /**
     * The data-* attributes of the hidden CropperConfig input, decoded.
     */
    private function cropperAttributes(FieldList $fields): array
    {
        $literal = $fields->fieldByName('CropperConfigField');
        $this->assertInstanceOf(LiteralField::class, $literal, 'the cropper config field must be present');

        $html = $literal->getContent();
        $this->assertMatchesRegularExpression('/name="CropperConfig"/', $html);
        $this->assertSame(1, preg_match('/data-cropconfig="([^"]*)"/', $html, $config));
        $this->assertSame(1, preg_match('/data-cropsizing="([^"]*)"/', $html, $sizing));

        return [
            'cropconfig' => json_decode(html_entity_decode($config[1]), true),
            'cropsizing' => json_decode(html_entity_decode($sizing[1]), true),
        ];
    }

    public function testExtensionIsAppliedToTheFileFormFactory(): void
    {
        $this->assertTrue(FileFormFactory::has_extension(ImageCropperFormFactoryExtension::class));
    }

    public function testCropDataFieldIsAddedAfterTitleAndFocusPoint(): void
    {
        $image = $this->makeQuadrantImage('form.png', ['CropData' => $this->cropData(1, 2, 3, 4)]);

        $fields = $this->formFieldsFor($image);
        $cropData = $fields->dataFieldByName('CropData');

        $this->assertInstanceOf(TextField::class, $cropData);
        // dataValue(): getValue() is SS6-only, Value() is gone on SS6
        $this->assertSame($this->cropData(1, 2, 3, 4), $cropData->dataValue());

        // insertAfter('Title') places it in whichever FieldList holds Title, behind it. focuspoint
        // also inserts its field after Title, from ImageFormFactory (a later extension point), so
        // the measured order is Title, FocusPoint, CropData, Name: assert that.
        $title = $fields->dataFieldByName('Title');
        $siblings = $title->getContainerFieldList();
        $names = array_map(fn ($field) => $field->getName(), $siblings->toArray());
        $this->assertSame(
            ['Title', 'FocusPoint', 'CropData', 'Name'],
            array_slice($names, array_search('Title', $names), 4),
            'CropData must sit behind Title and the FocusPoint field, before Name'
        );
    }

    public function testCropperIsFedTheImageAndPreviewSizes(): void
    {
        // Larger than FocusPointField's preview box (this module sets max_width 400,
        // max_height 300), so original and preview sizes differ and cannot be confused.
        $image = $this->makeQuadrantImage('sizes.png', [], true, 1000, 500);

        $sizing = $this->cropperAttributes($this->formFieldsFor($image))['cropsizing'];

        $this->assertSame(1000, $sizing['originalWidth']);
        $this->assertSame(500, $sizing['originalHeight']);
        // FitMax(400, 300) of 1000x500
        $this->assertSame(400, $sizing['previewWidth']);
        $this->assertSame(200, $sizing['previewHeight']);
    }

    public function testSmallImagesAreNotUpscaledForThePreview(): void
    {
        $image = $this->makeQuadrantImage('small.png');

        $sizing = $this->cropperAttributes($this->formFieldsFor($image))['cropsizing'];

        $this->assertSame(200, $sizing['previewWidth']);
        $this->assertSame(150, $sizing['previewHeight']);
    }

    public function testCropperIsFedTheDefaultCropconfig(): void
    {
        $image = $this->makeQuadrantImage('config.png');

        $config = $this->cropperAttributes($this->formFieldsFor($image))['cropconfig'];

        $this->assertSame(Image::config()->get('cropconfig'), $config);
    }

    public function testCropconfigSetOnImageReachesTheCropper(): void
    {
        // The documented way to configure the cropper
        Config::modify()->merge(Image::class, 'cropconfig', ['aspectRatio' => 1.5]);
        $image = $this->makeQuadrantImage('ratio.png');

        $config = $this->cropperAttributes($this->formFieldsFor($image))['cropconfig'];

        $this->assertEquals(1.5, $config['aspectRatio']);
        $this->assertSame(1, $config['autoCropArea'], 'merged, not replaced');
    }

    public function testNoCropperFieldsForAFileWithoutCropData(): void
    {
        $file = File::create();
        $file->setFromString('plain text', 'croppertest/readme.txt');
        $file->write();

        $fields = $this->formFieldsFor($file);

        $this->assertNull($fields->dataFieldByName('CropData'));
        $this->assertNull($fields->fieldByName('CropperConfigField'));
    }
}
