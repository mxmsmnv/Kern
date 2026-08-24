(function() {
	'use strict';

	document.addEventListener('click', function(event) {
		var trigger = event.target.closest('[data-kern-open-details]');
		if (!trigger) return;
		var details = document.getElementById(trigger.getAttribute('data-kern-open-details'));
		if (!details || details.tagName !== 'DETAILS') return;
		details.open = true;
	});
})();
