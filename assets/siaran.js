/* Siaran Langsung Masjid — countdowns + click-to-load recordings */
(function () {
	'use strict';

	function pad(n) { return n < 10 ? '0' + n : '' + n; }

	function tick() {
		var now = Date.now() / 1000;
		document.querySelectorAll('[data-ps-countdown]').forEach(function (el) {
			var t = parseInt(el.getAttribute('data-ps-countdown'), 10);
			if (!t) return;
			var d = Math.floor(t - now);
			if (d <= 0) { el.innerHTML = '<span class="ps-dot"></span> Bermula'; return; }
			var days = Math.floor(d / 86400), h = Math.floor((d % 86400) / 3600), m = Math.floor((d % 3600) / 60), s = d % 60;
			el.innerHTML = (days ? days + ' hari ' : '') + pad(h) + ':' + pad(m) + ':' + pad(s) + '<small>lagi</small>';
		});
	}

	function initRecordings() {
		document.addEventListener('click', function (e) {
			var btn = e.target.closest('[data-ps-embed]');
			if (!btn) return;
			var src = btn.getAttribute('data-ps-embed');
			if (!/^https:\/\/(www\.)?(youtube-nocookie\.com|youtube\.com|facebook\.com)\//.test(src)) return;
			src += (src.indexOf('?') === -1 ? '?' : '&') + (src.indexOf('facebook.com') !== -1 ? 'autoplay=true' : 'autoplay=1');
			var f = document.createElement('iframe');
			f.src = src;
			f.title = btn.getAttribute('data-ps-title') || 'Video';
			f.allow = 'autoplay; encrypted-media; picture-in-picture; web-share; fullscreen';
			f.allowFullscreen = true;
			f.referrerPolicy = 'strict-origin-when-cross-origin';
			var wrap = document.createElement('div');
			wrap.className = 'ratio ratio-16x9 ps-rec-playing';
			wrap.appendChild(f);
			btn.replaceWith(wrap);
		});
	}

	function init() {
		if (document.querySelector('[data-ps-countdown]')) { tick(); setInterval(tick, 1000); }
		initRecordings();
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
