<?php

namespace Restruct\SilverStripe\ImageCropper;

use JonoM\FocusPoint\Forms\FocusPointField;
use SilverStripe\AssetAdmin\Forms\FileFormFactory;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Image;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\TextField;
use SilverStripe\View\Requirements;

/**
 * “To add fields to the file edit form in asset-admin, you will need to add an extension to FileFormFactory and use the updateFormFields() hook.”
 *
 * @extends Extension<FileFormFactory>
 */
class ImageCropperFormFactoryExtension
    extends Extension
{
    private static $debug = false;

    public function updateFormFields($fields, $controller, $formName, $context)
    {
        // For reference:
        FileFormFactory::class;

        /** @var File $record */
        $record = isset($context['Record']) ? $context['Record'] : null;
        if ($record && $record->hasField('CropData')) {
            // Using HiddenField/display-none field, changes somehow will not be picked up (by react?) -> hiding old-skool (CSS)
            $fields->insertAfter('Title', $dataField = TextField::create('CropData', 'CropData', $record->CropData) );
            if(Director::isDev() && self::$debug) $dataField->addExtraClass('debug');

            // ($previewImage gets created with these sizes from FocusPointField)
            $previewImage = $record
                ->FitMax(FocusPointField::config()->get('max_width'), FocusPointField::config()->get('max_height'));

            // Additional safety check: skip if preview couldn't be generated
            if (!$previewImage || !$previewImage->getWidth() || !$previewImage->getHeight()) {
                return;
            }

            $sizes = array(
                // feed values relative to which the crop data will be scaled from JS
                'originalWidth' => $record->getWidth(),
                'originalHeight' => $record->getHeight(),
                'previewWidth' => $previewImage->getWidth(),
                'previewHeight' => $previewImage->getHeight(),
                // not actually used, but left here for reference:
                'cmsPreviewWidth' => Image::config()->get('asset_preview_width'),
            );

            // feed some additional cropper data to js -- setAttribute('data-xy', json) doesn't get rendered(!)
            // probably the react layer doesn't know how to handle custom attributes(?) because... well.. who knows
            $fields->push(LiteralField::create('CropperConfigField',
                HiddenField::create('CropperConfig', 'CropperConfig')
                    // feed config to js
                    ->setAttribute('data-cropconfig', json_encode($record->config()->get('cropconfig')) )
                    // feed values relative to which the crop data will be scaled from JS
                    ->setAttribute('data-cropsizing', json_encode($sizes))
                    ->forTemplate()
            ));
            if(Director::isDev() && self::$debug) {
                $data = [
                    'data-cropconfig' => $record->config()->get('cropconfig'),
                    'data-cropsizing' => $sizes,
                ];
                $fields->insertAfter('CropData', LiteralField::create('CropperDebugInfo', '<pre>'.json_encode($data, JSON_PRETTY_PRINT).'</pre>'));
            }
        }
    }
}
