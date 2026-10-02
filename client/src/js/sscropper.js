//import jQuery from 'jQuery';
//import React from 'react';
//import Injector from 'lib/Injector';
// import registerComponents from './registerComponents';
import Cropper from "cropperjs"

const $ = jQuery;

const sscropper = {

    cropper: null, // cropperjs object/instance
    cropImgEl: null,
    cropSizing: null,
    cropConfig: null,

    // This is a bit of a hack, we're placing the focuspoint overlay inside the cropperjs preview layer and giving it the same offset
    // since both JS plugins are using an overlay, we sacrifice the drag behaviour of cropper in order to be able to detect clicks in focusfield
    $previewImgEl: null,
    $focusPickerOverlayEl: null,
    syncFocusPickerOverlay: function(){
        this.$focusPickerOverlayEl.css({
            width: this.$previewImgEl.css('width'),
            height: this.$previewImgEl.css('height'),
            transform: this.$previewImgEl.css('transform'),
        });
    },

    configField: function(){
        // return this.jQueryel.parents('.fieldgroup').find('input[name="CropData"]')
        return $(this.cropImgEl).parents('fieldset').find('input[name="CropperConfig"]');
    },

    dataField: function(){
        // return this.jQueryel.parents('.fieldgroup').find('input[name="CropData"]')
        // return $(this.cropImgEl).parents('fieldset').find('input[name="CropData"]');
        return document.getElementById("Form_fileEditForm_CropData");
    },

    baseConfig: {
        // aspectRatio: 16 / 9,
        // autoCrop: false,
        autoCropArea: 1,
        movable: false,
        rotatable: false,
        scalable: false,
        zoomable: false,
        // modal: false,
        dragMode: 'none',
        guides: false,
        highlight: false,
        background: false,
        // cropBoxMovable: false,
        // Override CropperJS defaults to match FocusPointField's preview dimensions
        minContainerWidth: 0,
        minContainerHeight: 0,
    },

    init: function(image) {
        if(!image) {
            return;
        }

        let self = this; // cropper

        self.cropImgEl = image;
        self.cropSizing = JSON.parse(this.configField().attr('data-cropsizing'));
        self.cropConfig = JSON.parse(this.configField().attr('data-cropconfig'));

        let mergedConfig = {...self.baseConfig, ...self.cropConfig };
        mergedConfig.cropDataToOriginalScale = 1;
        mergedConfig.originalToCropDataScale = 1;

        // Ready callback (called after cropperjs has been instantiated)
        mergedConfig.ready = function() {

            // Somehow the scaling info in cropData is missing (it should be included as per the documentation), we'll just calculate it ourselves;
            let imgData = this.cropper.getImageData(); // {naturalWidth: 800, naturalHeight: 482, aspectRatio: 1.6597510373443984, width: 400, height: 241, …}
            mergedConfig.cropDataToOriginalScale = (imgData.width / imgData.naturalWidth) * (self.cropSizing.originalWidth / self.cropSizing.previewWidth);
            mergedConfig.originalToCropDataScale = 1 / mergedConfig.cropDataToOriginalScale;

            // load existing data (if any)
            try {
                let existing_crop = JSON.parse($( self.dataField() ).val());
                // Update x/y & width/height based on originalX/originalY & originalWidth/originalHeight,
                // to account for changed image size (eg updated FocusPointField.max_width/max_height)
                // existing_crop: {"x":120,"y":51,"width":242,"height":249,"originalX":665,"originalY":283,"originalWidth":1341,"originalHeight":1380}
                // self.cropSizing: {originalWidth: 1380, originalHeight: 832, previewWidth: 400, previewHeight: 241, cmsPreviewWidth: 930}
                existing_crop.x = existing_crop.originalX * mergedConfig.originalToCropDataScale;
                existing_crop.y = existing_crop.originalY * mergedConfig.originalToCropDataScale;
                existing_crop.width = existing_crop.originalWidth * mergedConfig.originalToCropDataScale;
                existing_crop.height = existing_crop.originalHeight * mergedConfig.originalToCropDataScale;

                this.cropper.setData(existing_crop);
            } catch (e) {
                console.log('CROPPER: Could not set existing crop...');
            }

            // Move the focuspoint layers within cropper to have them co-exist (this = image, self = cropper/self)
            self.$previewImgEl = $(self.cropImgEl).siblings('.cropper-container').find('.cropper-view-box img');
            self.$focusPickerOverlayEl = $(self.cropImgEl).siblings('.focuspoint-picker__overlay');
            self.$focusPickerOverlayEl.insertAfter( self.$previewImgEl );
            // Sync positions
            self.syncFocusPickerOverlay();
        };

        // Cropend callback (called when crop area has changed)
        mergedConfig.cropend = function () {
            // {"x":78,"y":0,"width":267,"height":267,"rotate":0,"scaleX":1,"scaleY":1}
            // let cropData = this.cropper.getData();
            let cropData = this.cropper.getData(true); // get rounded data

            // {originalWidth: 216, originalHeight: 144, previewWidth: 400, previewHeight: 267}
            // make sizes relative to original image (we're using a preview image):
            cropData.originalX = Math.round(cropData.x * mergedConfig.cropDataToOriginalScale);
            cropData.originalY = Math.round(cropData.y * mergedConfig.cropDataToOriginalScale);
            cropData.originalWidth = Math.round(cropData.width * mergedConfig.cropDataToOriginalScale);
            cropData.originalHeight = Math.round(cropData.height * mergedConfig.cropDataToOriginalScale);

            // Apparently we need a focus AND change for react to pick up our naive way of setting a formfield value...
            let dataField = self.dataField();
            let $dataField = $(dataField);
            // $dataField.trigger('focus');
            self.dataField().focus({preventScroll:true});
            // $dataField.val( JSON.stringify(cropData) );
            // $dataField.trigger('change');
            // The above never reached the saved record (#5): CropData is a React-controlled input
            // of the admin's FormBuilder form. jQuery's .val() writes the DOM value, but React's
            // value tracker is not told, and .trigger('change') only runs jQuery handlers (no native
            // event), so React's onChange never fires. On the next re-render (the focus() above
            // triggers one) React writes its own, still empty, state back and the POST sends CropData=.
            // Writing through the prototype's native value setter bypasses React's instance-level
            // setter override, so its tracker sees a real change; the bubbling native 'input' event
            // then reaches React's root listener and runs onChange, which stores the value in state.
            let nativeValueSetter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set;
            nativeValueSetter.call(dataField, JSON.stringify(cropData));
            dataField.dispatchEvent(new Event('input', { bubbles: true }));
        };

        // @TODO: Crop (eg drag) event, once per dragged pixel -> sync focuspoint overlay to stay at same position
        mergedConfig.cropmove = function (event) {
            self.syncFocusPickerOverlay();

            // let fpPicker = $('.focuspoint-picker__gradient');
            // fpPicker.css('left', parseFloat(fpPicker.css('left')) - event.detail.originalEvent.movementX);
            // fpPicker.css('top', parseFloat(fpPicker.css('top')) - event.detail.originalEvent.movementY);
            // console.log(fpPicker.css('top'));
            // console.log(event.detail.originalEvent.movementY);
            // console.log(parseFloat(fpPicker.css('top')) - event.detail.originalEvent.movementY);
        };

        //
        // Instantiate the whole thing...
        //
        self.cropper = new Cropper(image, mergedConfig);
    },

}

// SimplerSilverstripe: DOMNodesInserted & DOMNodesRemoved
document.addEventListener('DOMNodesInserted', (event) => {
    // INIT CROPPERJS
    // console.log('INIT croppper');
    const image = document.querySelector('#Form_fileEditForm_FocusPoint_Holder .focuspoint-picker__image');
    if(image){
        sscropper.init(image);
    }

});

