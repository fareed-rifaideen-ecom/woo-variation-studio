/**
 * Frontend Swatch Controller, Two-Way Stock Matrix & Flatsome Flickity Gallery Bridge
 *
 * File Path: assets/js/frontend-ux.js
 *
 * @package WooVariationStudio
 */

jQuery(document).ready(function ($) {
	'use strict';

	var WVS = {
		config: window.wvsFrontendConfig || {
			crossAttrMode: 'smart_switch',
			disabledEffect: 'cross',
			strictGalleryIsolation: true,
			showSelectedLabel: true,
			enableTooltip: true
		},

		lastRenderedGallerySignature: '',

		init: function() {
			this.bindSwatches();
			this.bindNativeWooCommerceMatrix();
			this.bindDeliverySwitcher();
			this.initializePreSelections();
		},

		bindSwatches: function() {
			var self = this;

			// Handle Custom Swatch Click
			$(document).on('click', '.wvs-swatch-item', function (e) {
				e.preventDefault();
				var $btn = $(this);
				var $container = $btn.closest('.wvs-swatches-container');
				var $form = $btn.closest('form.variations_form');
				var attrName = $container.data('attribute_name');
				var value = $btn.data('value');

				// Stop entirely if strict mode and combination is out of stock
				if ($btn.hasClass('is-oos-in-combo') && self.config.crossAttrMode === 'strict_lock') {
					return; 
				}

				// Update active UI classes
				$container.find('.wvs-swatch-item').removeClass('selected is-active').attr('aria-pressed', 'false');
				$btn.addClass('selected is-active').attr('aria-pressed', 'true');

				if (self.config.showSelectedLabel) {
					var label = $btn.data('label') || $btn.attr('aria-label') || '';
					$container.find('.wvs-selected-term-value').text(label);
				}

				// Trigger native WooCommerce dropdown to let WC perform matrix calculations
				var $select = $container.closest('td, .value').find('select');
				if ($select.length) {
					$select.val(value).trigger('change');
				}
			});

			// Flatsome Gallery Intercept when WooCommerce finds a variation
			$('form.variations_form').on('show_variation', function(event, variation) {
				if (variation && variation.wvs_gallery_images) {
					self.updateFlatsomeGalleryWithVariation(variation);
				}
			});

			// Handle "Clear / Reset variations" click
			$(document).on('click', '.reset_variations', function () {
				var $form = $(this).closest('form.variations_form');
				setTimeout(function () {
					$form.find('.wvs-swatch-item').removeClass('selected is-active is-oos-in-combo is-oos-locked').attr('aria-pressed', 'false');
					if (self.config.showSelectedLabel) {
						$form.find('.wvs-selected-term-value').text('');
					}
				}, 50);
			});
		},

		/**
		 * Instead of doing our own matrix math, we listen to WooCommerce's core logic.
		 * When WooCommerce disables `<option>` tags inside the hidden `<select>`, we instantly mirror it visually!
		 */
		bindNativeWooCommerceMatrix: function() {
			$('form.variations_form').on('woocommerce_update_variation_values', function() {
				var $form = $(this);
				
				$form.find('.wvs-swatches-container').each(function() {
					var $container = $(this);
					var attrName = $container.data('attribute_name');
					var $select = $container.closest('td, .value').find('select');
					
					if (!$select.length) {
						return;
					}

					$container.find('.wvs-swatch-item').each(function() {
						var $swatch = $(this);
						var val = $swatch.data('value');
						var $option = $select.find('option[value="' + val + '"]');
						
						// If WooCommerce disabled or removed the option, strike it through visually
						if ($option.length === 0 || $option.is(':disabled') || $option.hasClass('disabled')) {
							$swatch.addClass('is-oos-in-combo');
							if (WVS.config.crossAttrMode === 'strict_lock') {
								$swatch.addClass('is-oos-locked');
							}
						} else {
							$swatch.removeClass('is-oos-in-combo is-oos-locked');
						}
					});
				});
			});
		},

		initializePreSelections: function() {
			var self = this;
			$('form.variations_form').each(function () {
				var $form = $(this);
				$form.find('.wvs-swatches-container').each(function () {
					var $container = $(this);
					var currentVal = $container.closest('td, .value').find('select').val();
					if (currentVal) {
						var $activeBtn = $container.find('.wvs-swatch-item[data-value="' + currentVal + '"]');
						$container.find('.wvs-swatch-item').removeClass('selected is-active');
						$activeBtn.addClass('selected is-active');
						
						if (self.config.showSelectedLabel) {
							var label = $activeBtn.data('label') || $activeBtn.attr('aria-label') || '';
							$container.find('.wvs-selected-term-value').text(label);
						}
					}
				});
			});
		},

		updateFlatsomeGalleryWithVariation: function(variation) {
			if (!variation.wvs_gallery_images || variation.wvs_gallery_images.length === 0) {
				return;
			}

			var slides = variation.wvs_gallery_images;
			var signature = slides.map(function (s) { return s.id; }).join(',');

			if (signature === this.lastRenderedGallerySignature) {
				return;
			}
			this.lastRenderedGallerySignature = signature;

			var $mainSlider = $('.product-gallery-slider');
			if (!$mainSlider.length || !this.config.strictGalleryIsolation) {
				return;
			}

			var $thumbSlider = $('.product-thumbnails');

			if ($mainSlider.data('flickity')) { $mainSlider.flickity('destroy'); }
			if ($thumbSlider.length && $thumbSlider.data('flickity')) { $thumbSlider.flickity('destroy'); }

			var mainHtml = '';
			var thumbHtml = '';

			$.each(slides, function (idx, slide) {
				var firstClass = idx === 0 ? ' first is-selected' : '';
				mainHtml +=
					'<div class="woocommerce-product-gallery__image slide' + firstClass + '" data-thumb="' + slide.thumb_src + '">' +
						'<a href="' + slide.full_src + '">' +
							'<img width="' + slide.full_w + '" height="' + slide.full_h + '" src="' + slide.single_src + '" class="wp-post-image skip-lazy" alt="' + slide.alt + '" data-large_image="' + slide.full_src + '" data-large_image_width="' + slide.full_w + '" data-large_image_height="' + slide.full_h + '" />' +
						'</a>' +
					'</div>';

				var activeThumb = idx === 0 ? ' is-nav-selected first' : '';
				thumbHtml +=
					'<div class="col' + activeThumb + '">' +
						'<a><img src="' + slide.thumb_src + '" alt="' + slide.alt + '" width="150" height="150" class="attachment-woocommerce_thumbnail" /></a>' +
					'</div>';
			});

			$mainSlider.html(mainHtml);
			if ($thumbSlider.length) {
				$thumbSlider.html(thumbHtml);
			}

			if (window.Flatsome && typeof window.Flatsome.attach === 'function') {
				window.Flatsome.attach('slider', $mainSlider.parent());
				window.Flatsome.attach('quick-view', $mainSlider.parent());
			} else if ($.fn.flickity) {
				$mainSlider.flickity({ cellAlign: 'left', wrapAround: true, autoPlay: false, adaptiveHeight: true, imagesLoaded: true, pageDots: false });
			}
		},

		bindDeliverySwitcher: function() {
			$(document).on('click', '.wvs-delivery-option', function () {
				$(this).closest('.wvs-delivery-switcher-block').find('.wvs-delivery-option').removeClass('is-selected');
				$(this).addClass('is-selected');
			});
		}
	};

	WVS.init();
});
