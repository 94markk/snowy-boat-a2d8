(function ($) {
	'use strict';

	$(function () {
		var $form = $('form.variations_form');
		var $target = $('.dst2t-requirements[data-dst2t-variable="1"]');

		if (!$form.length || !$target.length) {
			return;
		}

		$form.on('found_variation', function (_event, variation) {
			if (variation && variation.dst2t_enabled) {
				$target.html(variation.dst2t_fields_html || '').attr('aria-hidden', 'false');
			} else {
				$target.empty().attr('aria-hidden', 'true');
			}
		});

		$form.on('reset_data hide_variation', function () {
			$target.empty().attr('aria-hidden', 'true');
		});
	});
})(jQuery);

