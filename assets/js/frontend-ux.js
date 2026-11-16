/**
 * Frontend Swatch Controller & Flatsome Flickity Gallery Bridge
 * 
 * @package WooVariationStudio
 */

jQuery(document).ready(function($) {
    'use strict';

    // Handle Swatch Click
    $(document).on('click', '.wvs-swatch-item.enabled', function(e) {
        e.preventDefault();
        var $item = $(this);
        var $container = $item.closest('.wvs-swatch-container');
        var attributeName = $container.data('attribute_name');
        var value = $item.data('value');

        // Toggle selected classes
        $container.find('.wvs-swatch-item').removeClass('selected');
        $item.addClass('selected');

        // Update hidden native select and trigger change
        var $select = $container.find('select');
        $select.val(value).trigger('change');
    });

    // Sync state on variation found
    $('.variations_form').on('found_variation', function(event, variation) {
        if (variation && variation.wvs_custom_gallery && variation.wvs_custom_gallery.length > 0) {
            updateFlatsomeGallery(variation.wvs_custom_gallery);
        }
    });

    function updateFlatsomeGallery(gallery) {
        var $slider = $('.product-gallery-slider');
        if ($slider.length === 0) {
            return;
        }

        // If Flickity is initialized, destroy and rebuild with new gallery slides
        if ($slider.hasClass('flickity-enabled')) {
            $slider.flickity('destroy');
        }

        var slidesHtml = '';
        var thumbsHtml = '';

        $.each(gallery, function(index, img) {
            slidesHtml += '<div class="slide"><a href="' + img.full + '" class="image-lightbox"><img src="' + img.full + '" /></a></div>';
            thumbsHtml += '<div class="slide is-nav-selected"><img src="' + img.thumb + '" /></div>';
        });

        $slider.html(slidesHtml);
        var $thumbs = $('.product-thumbnails');
        if ($thumbs.length > 0) {
            $thumbs.html(thumbsHtml);
        }

        // Re-initialize Flatsome Flickity
        if (typeof window.Flatsome === 'object' && typeof window.Flatsome.behavior === 'function') {
            window.Flatsome.behavior('commons', $(document));
        }
    }
});
