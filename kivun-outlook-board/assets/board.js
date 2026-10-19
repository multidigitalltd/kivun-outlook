/**
 * Kivun Outlook Board – scaling, clock, live class statuses, refresh and paging.
 * Written in ES5 so it also runs on older smart-TV / signage browsers.
 */
(function () {
	'use strict';

	var STAGE_W = 1920;
	var STAGE_H = 1080;
	var COMPACT_BELOW = 760; // Embedded boards narrower than this switch to a phone layout.
	var RELOAD_AFTER_MS = 6 * 60 * 60 * 1000; // Full reload of the screen page every 6 hours.

	function Board(el) {
		this.el = el;
		this.mode = el.getAttribute('data-mode');
		this.endpoint = el.getAttribute('data-endpoint');
		this.refresh = parseInt(el.getAttribute('data-refresh'), 10) || 60;
		this.perPage = parseInt(el.getAttribute('data-rows-per-page'), 10) || 0;
		this.pageSeconds = parseInt(el.getAttribute('data-page-seconds'), 10) || 15;
		this.hash = el.getAttribute('data-hash');
		this.tz = el.getAttribute('data-tz');
		this.scaler = el.querySelector('.kb-scaler');
		this.stage = el.querySelector('.kb-stage');
		this.page = 0;
		this.pageTimer = null;

		var self = this;
		this.fit();
		window.addEventListener('resize', function () { self.fit(); });
		this.setupPages();

		this.tick();
		setInterval(function () { self.tick(); }, 10000);
		if (this.endpoint) {
			setInterval(function () { self.poll(); }, this.refresh * 1000);
		}
		if (this.mode === 'screen') {
			this.setupScreen();
		}
	}

	/** Scales the 1920×1080 stage to the available space. */
	Board.prototype.fit = function () {
		var el = this.el;
		var scale, width, height;

		if (this.mode === 'screen') {
			width = window.innerWidth;
			height = window.innerHeight;
			scale = Math.min(width / STAGE_W, height / STAGE_H);
			var x = (width - STAGE_W * scale) / 2;
			var y = (height - STAGE_H * scale) / 2;
			this.transform('translate(' + x + 'px,' + y + 'px) scale(' + scale + ')');
			return;
		}

		width = el.clientWidth;
		var compact = width > 0 && width < COMPACT_BELOW;
		toggleClass(el, 'kb-compact', compact);
		if (compact) {
			this.transform('none');
			el.style.height = '';
			this.setupPages();
			return;
		}
		scale = width / STAGE_W;
		this.transform('scale(' + scale + ')');
		el.style.height = Math.round(STAGE_H * scale) + 'px';
		this.setupPages();
	};

	Board.prototype.transform = function (value) {
		this.scaler.style.webkitTransform = value;
		this.scaler.style.transform = value;
	};

	/** Splits the room cards into pages that rotate when there are more rooms than fit. */
	Board.prototype.setupPages = function () {
		var cards = this.stage.querySelectorAll('.kb-card');
		var grid = this.stage.querySelector('.kb-grid');
		var dots = this.stage.querySelector('.kb-pages');
		var self = this;
		var compact = hasClass(this.el, 'kb-compact');
		var pages = (!compact && this.perPage > 0) ? Math.ceil(cards.length / this.perPage) : 1;

		if (this.pageTimer) {
			clearInterval(this.pageTimer);
			this.pageTimer = null;
		}
		if (this.page >= pages) {
			this.page = 0;
		}
		if (grid) {
			// A shorter last page keeps the same card width.
			grid.style.setProperty('--kb-cols', String(Math.max(1, pages > 1 ? this.perPage : cards.length)));
		}

		if (dots) {
			dots.innerHTML = '';
			for (var i = 0; pages > 1 && i < pages; i++) {
				dots.appendChild(document.createElement('span'));
			}
		}

		function show() {
			for (var c = 0; c < cards.length; c++) {
				var visible = pages <= 1 || Math.floor(c / self.perPage) === self.page;
				toggleClass(cards[c], 'kb-hidden', !visible);
			}
			if (dots) {
				for (var d = 0; d < dots.children.length; d++) {
					toggleClass(dots.children[d], 'kb-active', d === self.page);
				}
			}
		}

		show();
		if (pages > 1) {
			this.pageTimer = setInterval(function () {
				self.page = (self.page + 1) % pages;
				show();
			}, this.pageSeconds * 1000);
		}
	};

	/** HH:MM in the site's time zone, so a TV with a wrong zone setting still shows Israel time. */
	Board.prototype.clockText = function (now) {
		if (this.tz && /^[A-Za-z_]+\/[A-Za-z_\/+-]+$/.test(this.tz) && window.Intl) {
			try {
				var text = new Intl.DateTimeFormat('en-GB', { timeZone: this.tz, hour: '2-digit', minute: '2-digit', hour12: false }).format(now);
				if (/^\d{2}:\d{2}$/.test(text)) {
					return text;
				}
			} catch (e) {
				// Old browser without time zone support: fall back to the device clock.
			}
		}
		return pad(now.getHours()) + ':' + pad(now.getMinutes());
	};

	/** Updates the clock and every slot's status (now / soon / ended) from the current time. */
	Board.prototype.tick = function () {
		var now = new Date();
		var nowSec = Math.floor(now.getTime() / 1000);
		var clock = this.stage.querySelector('.kb-clock-time');
		if (clock) {
			clock.textContent = this.clockText(now);
		}

		var heb = this.stage.querySelector('.kb-hebdate');
		if (heb && !heb.textContent && window.Intl) {
			try {
				var parts = (heb.getAttribute('data-date') || '').split('-');
				var day = new Date(+parts[0], +parts[1] - 1, +parts[2], 12);
				heb.textContent = new Intl.DateTimeFormat('he-IL-u-ca-hebrew', { day: 'numeric', month: 'long', year: 'numeric' }).format(day);
			} catch (e) {
				// Browser without Hebrew calendar support: leave it out.
			}
		}

		var slots = this.stage.querySelectorAll('.kb-slot');
		for (var i = 0; i < slots.length; i++) {
			var events = slots[i].querySelectorAll('.kb-event');
			var state = slotState(events, nowSec);
			var states = ['empty', 'live', 'soon', 'upcoming', 'ended'];
			for (var k = 0; k < states.length; k++) {
				toggleClass(slots[i], 'kb-slot--' + states[k], states[k] === state);
			}
			var badge = slots[i].querySelector('.kb-badge');
			if (badge) {
				badge.textContent = BADGES[state] || '';
			}
			for (var e = 0; e < events.length; e++) {
				var end = +events[e].getAttribute('data-end');
				var start = +events[e].getAttribute('data-start');
				toggleClass(events[e], 'kb-event--ended', end > start && nowSec >= end);
			}
		}
	};

	/** Fetches the latest board and swaps it in when it changed (also handles the midnight roll-over). */
	Board.prototype.poll = function () {
		var self = this;
		var url = this.endpoint + (this.endpoint.indexOf('?') === -1 ? '?' : '&') + '_=' + Date.now();
		var xhr = new XMLHttpRequest();
		xhr.open('GET', url, true);
		xhr.setRequestHeader('Accept', 'application/json');
		xhr.onreadystatechange = function () {
			if (xhr.readyState !== 4 || xhr.status !== 200) {
				return; // Keep showing the last good board on errors.
			}
			try {
				var data = JSON.parse(xhr.responseText);
				if (data && data.html && data.hash !== self.hash) {
					self.hash = data.hash;
					self.stage.innerHTML = data.html;
					self.setupPages();
					self.tick();
				}
			} catch (e) {
				// Ignore malformed responses.
			}
		};
		xhr.send();
	};

	/** Screen page: click for full screen, hide the idle cursor, periodic full reload. */
	Board.prototype.setupScreen = function () {
		var el = this.el;
		var idleTimer = null;

		function wake() {
			removeClass(el, 'kb-idle');
			clearTimeout(idleTimer);
			idleTimer = setTimeout(function () { addClass(el, 'kb-idle'); }, 3000);
		}
		document.addEventListener('mousemove', wake);
		wake();

		el.addEventListener('click', function () {
			var doc = document;
			var root = doc.documentElement;
			if (doc.fullscreenElement || doc.webkitFullscreenElement) {
				(doc.exitFullscreen || doc.webkitExitFullscreen).call(doc);
			} else if (root.requestFullscreen || root.webkitRequestFullscreen) {
				(root.requestFullscreen || root.webkitRequestFullscreen).call(root);
			}
		});

		setTimeout(function () {
			// Reload only when the server is reachable, so a network outage never leaves a blank screen.
			var xhr = new XMLHttpRequest();
			xhr.open('GET', window.location.href, true);
			xhr.onload = function () {
				if (xhr.status === 200) {
					window.location.reload();
				}
			};
			xhr.send();
		}, RELOAD_AFTER_MS);
	};

	var SOON_SECONDS = 60 * 60;
	var BADGES = { live: 'עכשיו', soon: 'בקרוב', ended: 'הסתיים' };

	/** Mirrors Kivun_Board::slot_state() in PHP. */
	function slotState(events, now) {
		if (!events.length) {
			return 'empty';
		}
		var next = null;
		for (var i = 0; i < events.length; i++) {
			var start = +events[i].getAttribute('data-start');
			var end = +events[i].getAttribute('data-end');
			if (now >= start && now < end) {
				return 'live';
			}
			if (now < start && (next === null || start < next)) {
				next = start;
			}
		}
		if (next === null) {
			return 'ended';
		}
		return next - now <= SOON_SECONDS ? 'soon' : 'upcoming';
	}

	function pad(n) {
		return (n < 10 ? '0' : '') + n;
	}

	function hasClass(el, name) {
		return (' ' + el.className + ' ').indexOf(' ' + name + ' ') !== -1;
	}
	function addClass(el, name) {
		if (!hasClass(el, name)) {
			el.className = (el.className + ' ' + name).replace(/^\s+/, '');
		}
	}
	function removeClass(el, name) {
		el.className = (' ' + el.className + ' ').replace(' ' + name + ' ', ' ').replace(/^\s+|\s+$/g, '');
	}
	function toggleClass(el, name, on) {
		if (on) {
			addClass(el, name);
		} else {
			removeClass(el, name);
		}
	}

	function start() {
		var boards = document.querySelectorAll('.kb-board');
		for (var i = 0; i < boards.length; i++) {
			if (!boards[i].kivunBoard) {
				boards[i].kivunBoard = new Board(boards[i]);
			}
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
})();
