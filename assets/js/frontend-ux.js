/**
 * Frontend Swatch Controller, Two-Way Stock Matrix & Flatsome Flickity Gallery Bridge
 *
 * File Path: assets/js/frontend-ux.js
 *
 * @package WooVariationStudio
 */

jQuery(document).ready(function ($) {
	'use strict';

	var config = window.wvsFrontendConfig || {
		crossAttrMode: 'smart_switch',
		disabledEffect: 'cross',
		strictGalleryIsolation: true,
		showSelectedLabel: true,
		enableTooltip: true
	};

	var variationMatrix = [];
	var $matrixScript = $('#wvs-variation-availability-matrix');
	if ($matrixScript.length) {
		try {
			variationMatrix = JSON.parse($matrixScript.text()) || [];
		} catch (err) {
			variationMatrix = [];
		}
	}

	var lastRenderedGallerySignature = '';

	/**
	 * Update ": Selected Name" beside the WooCommerce attribute label.
	 */
	function updateSelectedAttributeLabels($form) {
		if (!config.showSelectedLabel) {
			return;
		}

		$form.find('.wvs-swatches-container').each(function () {
			var $container = $(this);
			var $activeBtn = $container.find('.wvs-swatch-item.selected');
			var selectedText = $activeBtn.length ? ($activeBtn.attr('data-label') || '') : '';

			var $row = $container.closest('tr');
			var $labelCell = $row.find('th.label label');
			if (!$labelCell.length) {
				$labelCell = $row.find('.label label');
			}

			if ($labelCell.length) {
				var $nameSpan = $labelCell.find('.wvs-selected-variation-name');
				if (!$nameSpan.length) {
					$nameSpan = $('<span class="wvs-selected-variation-name"></span>');
					$labelCell.append($nameSpan);
				}
				$nameSpan.text(selectedText ? ': ' + selectedText : '');
			}
		});
	}

	/**
	 * Collect currently selected attributes across all dropdowns in the form.
	 */
	function getCurrentSelections($form) {
		var selections = {};
		$form.find('.wvs-swatches-container').each(function () {
			var attrName = $(this).attr('data-attribute_name');
			var val = $(this).closest('td, .value').find('select').val() || '';
			selections[attrName] = val;
		});
		return selections;
	}

	/**
	 * Check if a candidate attribute combination has at least one in-stock variation.
	 */
	function isCombinationInStock(testSelections) {
		if (!variationMatrix.length) {
			return true;
		}

		for (var i = 0; i < variationMatrix.length; i++) {
			var item = variationMatrix[i];
			if (!item.is_in_stock) {
				continue;
			}

			var matchesAll = true;
			var attrs = item.attributes || {};

			for (var key in testSelections) {
				if (!Object.prototype.hasOwnProperty.call(testSelections, key)) {
					continue;
				}
				var wantedVal = testSelections[key];
				if (!wantedVal) {
					continue;
				}
				var varVal = attrs[key] || '';
				// Empty varVal on a variation means "Any" term in WooCommerce
				if (varVal !== '' && varVal !== wantedVal) {
					matchesAll = false;
					break;
				}
			}

			if (matchesAll) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Find the first in-stock variation that has a given attribute value (for Smart Switch mode).
	 */
	function findFirstInStockVariationFor(attrName, targetValue) {
		for (var i = 0; i < variationMatrix.length; i++) {
			var item = variationMatrix[i];
			if (!item.is_in_stock) {
				continue;
			}
			var attrs = item.attributes || {};
			if (attrs[attrName] === targetValue || attrs[attrName] === '') {
				return item;
			}
		}
		return null;
	}

	/**
	 * Evaluate Two-Way Cross-Attribute Availability (Size <-> Colour <-> Groupset <-> Wheelset).
	 */
	function refreshCrossAttributeAvailability($form) {
		var currentSelections = getCurrentSelections($form);

		$form.find('.wvs-swatches-container').each(function () {
			var $container = $(this);
			var attrName = $container.attr('data-attribute_name');

			$container.find('.wvs-swatch-item').each(function () {
				var $btn = $(this);
				var candidateValue = $btn.attr('data-value');

				var testCombo = $.extend({}, currentSelections);
				testCombo[attrName] = candidateValue;

				var available = isCombinationInStock(testCombo);
				if (available) {
					$btn.removeClass('is-oos-in-combo is-oos-locked');
				} else {
					$btn.addClass('is-oos-in-combo');
					if (config.crossAttrMode === 'strict_lock') {
						$btn.addClass('is-oos-locked');
					} else {
						$btn.removeClass('is-oos-locked');
					}
				}
			});
		});

		updateSelectedAttributeLabels($form);
	}

	/**
	 * Handle Swatch Button Click.
	 */
	$(document).on('click', '.wvs-swatch-item', function (e) {
		e.preventDefault();
		var $btn = $(this);
		var $container = $btn.closest('.wvs-swatches-container');
		var $form = $btn.closest('form.variations_form');
		var attrName = $container.attr('data-attribute_name');
		var value = $btn.attr('data-value');

		// If combination is out of stock:
		if ($btn.hasClass('is-oos-in-combo')) {
			if (config.crossAttrMode === 'strict_lock') {
				return;
			}

			// Smart Switch: automatically switch other attributes to the first in-stock variation for this choice
			var fallbackVar = findFirstInStockVariationFor(attrName, value);
			if (fallbackVar && fallbackVar.attributes) {
				$.each(fallbackVar.attributes, function (otherAttr, otherVal) {
					if (otherAttr !== attrName && otherVal) {
						var $otherContainer = $form.find('.wvs-swatches-container[data-attribute_name="' + otherAttr + '"]');
						var $otherSelect = $otherContainer.closest('td, .value').find('select');
						$otherSelect.val(otherVal);
						$otherContainer.find('.wvs-swatch-item').removeClass('selected is-active').attr('aria-pressed', 'false');
						$otherContainer.find('.wvs-swatch-item[data-value="' + otherVal + '"]').addClass('selected is-active').attr('aria-pressed', 'true');
					}
				});
			}
		}

		// Update active state on clicked row
		$container.find('.wvs-swatch-item').removeClass('selected is-active').attr('aria-pressed', 'false');
		$btn.addClass('selected is-active').attr('aria-pressed', 'true');

		// Sync native WooCommerce <select> and trigger variation calculation
		var $select = $container.closest('td, .value').find('select');
		$select.val(value).trigger('change');

		refreshCrossAttributeAvailability($form);
	});

	/**
	 * Sync swatches when user clicks WooCommerce "Clear / Reset variations".
	 */
	$(document).on('click', '.reset_variations', function () {
		var $form = $(this).closest('form.variations_form');
		setTimeout(function () {
			$form.find('.wvs-swatch-item').removeClass('selected is-active is-oos-in-combo is-oos-locked').attr('aria-pressed', 'false');
			updateSelectedAttributeLabels($form);
		}, 50);
	});

	/**
	 * Rebuild Flatsome Flickity Product Gallery strictly with the selected variation's photos.
	 */
	function updateFlatsomeGalleryWithVariation(variation) {
		if (!variation || !variation.wvs_gallery_images || !variation.wvs_gallery_images.length) {
			return;
		}

		var slides = variation.wvs_gallery_images;
		var signature = slides.map(function (s) { return s.id; }).join(',');

		// Skip rebuilding Flickity if the exact same gallery images are already displayed (e.g. clicking Size S2 -> S3)
		if (signature === lastRenderedGallerySignature) {
			return;
		}
		lastRenderedGallerySignature = signature;

		var $mainSlider = $('.product-gallery-slider');
		if (!$mainSlider.length) {
			return;
		}

		var $thumbSlider = $('.product-thumbnails');

		// Destroy existing Flickity instances cleanly before replacing DOM nodes
		if ($mainSlider.data('flickity')) {
			$mainSlider.flickity('destroy');
		}
		if ($thumbSlider.length && $thumbSlider.data('flickity')) {
			$thumbSlider.flickity('destroy');
		}

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

		// Re-initialize Flatsome Flickity slider & Lightbox behaviors
		if (window.Flatsome && typeof window.Flatsome.attach === 'function') {
			window.Flatsome.attach('slider', $mainSlider.parent());
			window.Flatsome.attach('quick-view', $mainSlider.parent());
		} else if ($.fn.flickity) {
			$mainSlider.flickity({
				cellAlign: 'left',
				wrapAround: true,
				autoPlay: false,
				prevNextButtons: true,
				adaptiveHeight: true,
				imagesLoaded: true,
				pageDots: false
			});
		}
	}

	/**
	 * Listen to WooCommerce `found_variation` event.
	 */
	$('form.variations_form')
		.on('found_variation', function (event, variation) {
			var $form = $(this);
			refreshCrossAttributeAvailability($form);
			updateFlatsomeGalleryWithVariation(variation);
		})
		.on('woocommerce_update_variation_values', function () {
			refreshCrossAttributeAvailability($(this));
		});

	/**
	 * Initial load: sync pre-selected default variation swatches & availability states.
	 */
	$('form.variations_form').each(function () {
		var $form = $(this);
		$form.find('.wvs-swatches-container').each(function () {
			var $container = $(this);
			var currentVal = $container.closest('td, .value').find('select').val();
			if (currentVal) {
				$container.find('.wvs-swatch-item').removeClass('selected is-active');
				$container.find('.wvs-swatch-item[data-value="' + currentVal + '"]').addClass('selected is-active');
			}
		});
		refreshCrossAttributeAvailability($form);
	});

	/**
	 * Delivery Switcher Block click toggle.
	 */
	$(document).on('click', '.wvs-delivery-option', function () {
		var $opt = $(this);
		$opt.closest('.wvs-delivery-switcher-block').find('.wvs-delivery-option').removeClass('is-selected');
		$opt.addClass('is-selected');
	});
});
