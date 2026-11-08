/* Loyalty Stamps — admin */
(function () {
	'use strict';
	document.addEventListener('DOMContentLoaded', function () {
		// Confirm danger actions that missed inline handlers.
		document.querySelectorAll('[data-ls-confirm]').forEach(function (el) {
			el.addEventListener('click', function (e) {
				if (!confirm(el.getAttribute('data-ls-confirm'))) e.preventDefault();
			});
		});
	});
})();
