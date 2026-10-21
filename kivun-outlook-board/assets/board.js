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
		// Difference between the server clock and this device's clock, in ms.
		var serverNow = +el.getAttribute('data-now');
		this.skew = serverNow > 0 ? serverNow - Date.now() : 0;
		this.scaler = el.querySelector('.kb-scaler');
		this.stage = el.querySelector('.kb-stage');
		this.page = 0;
		this.pageTimer = null;

		var self = this;
		this.fit();
		window.addEventListener('resize', function () { self.fit(); });
		this.setupPages();

		this.tick();
		this.runClock();
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
				var text = new Intl.DateTimeFormat('en-GB', { timeZone: this.tz, hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false }).format(now);
				if (/^\d{2}:\d{2}:\d{2}$/.test(text)) {
					return text;
				}
			} catch (e) {
				// Old browser without time zone support: fall back to the device clock.
			}
		}
		return pad(now.getHours()) + ':' + pad(now.getMinutes()) + ':' + pad(now.getSeconds());
	};

	/** Current time according to the server (device clock + measured difference). */
	Board.prototype.now = function () {
		return new Date(Date.now() + this.skew);
	};

	/** Updates the clock display (HH:MM and running seconds). */
	Board.prototype.drawClock = function () {
		var text = this.clockText(this.now());
		var hm = this.stage.querySelector('.kb-clock-hm');
		var sec = this.stage.querySelector('.kb-clock-sec');
		if (hm && sec) {
			hm.textContent = text.slice(0, 5);
			sec.textContent = text.slice(5);
		}
	};

	/** Redraws the clock exactly on each new second. */
	Board.prototype.runClock = function () {
		var self = this;
		this.drawClock();
		var wait = 1000 - (Date.now() + this.skew) % 1000;
		setTimeout(function () { self.runClock(); }, wait + 15);
	};

	/** Updates the clock and every slot's status (now / soon / ended) from the current time. */
	Board.prototype.tick = function () {
		var nowSec = Math.floor(this.now().getTime() / 1000);
		this.drawClock();

		fillHebrewDates(this.stage);

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
		var sent = Date.now();
		var xhr = new XMLHttpRequest();
		xhr.open('GET', url, true);
		xhr.setRequestHeader('Accept', 'application/json');
		xhr.onreadystatechange = function () {
			if (xhr.readyState !== 4 || xhr.status !== 200) {
				return; // Keep showing the last good board on errors.
			}
			try {
				var data = JSON.parse(xhr.responseText);
				if (data && data.now) {
					// The server stamped its time halfway through the round trip.
					var received = Date.now();
					self.skew = +data.now - (sent + received) / 2;
				}
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

	/** [kivun_week]: refreshes the table and marks the class running now / the ones that ended. */
	function Week(el) {
		this.el = el;
		this.endpoint = el.getAttribute('data-endpoint');
		this.refresh = parseInt(el.getAttribute('data-refresh'), 10) || 60;
		this.hash = el.getAttribute('data-hash');
		var serverNow = +el.getAttribute('data-now');
		this.skew = serverNow > 0 ? serverNow - Date.now() : 0;
		this.body = el.querySelector('.kb-week-body');

		var self = this;
		this.tick();
		setInterval(function () { self.tick(); }, 10000);
		if (this.endpoint && this.body) {
			setInterval(function () { self.poll(); }, this.refresh * 1000);
		}
	}

	Week.prototype.tick = function () {
		var nowSec = Math.floor((Date.now() + this.skew) / 1000);
		fillHebrewDates(this.el);
		var events = this.el.querySelectorAll('.kb-week-event');
		for (var i = 0; i < events.length; i++) {
			var start = +events[i].getAttribute('data-start');
			var end = +events[i].getAttribute('data-end');
			var ended = end > start && nowSec >= end;
			toggleClass(events[i], 'kb-week-event--ended', ended);
			toggleClass(events[i], 'kb-week-event--live', !ended && nowSec >= start && nowSec < end);
		}
	};

	/** Same as Board.prototype.poll(), for the week table (also moves on a day at midnight). */
	Week.prototype.poll = function () {
		var self = this;
		var url = this.endpoint + (this.endpoint.indexOf('?') === -1 ? '?' : '&') + '_=' + Date.now();
		var sent = Date.now();
		var xhr = new XMLHttpRequest();
		xhr.open('GET', url, true);
		xhr.setRequestHeader('Accept', 'application/json');
		xhr.onreadystatechange = function () {
			if (xhr.readyState !== 4 || xhr.status !== 200) {
				return;
			}
			try {
				var data = JSON.parse(xhr.responseText);
				if (data && data.now) {
					self.skew = +data.now - (sent + Date.now()) / 2;
				}
				if (data && data.html && data.hash !== self.hash) {
					self.hash = data.hash;
					self.body.innerHTML = data.html;
					self.tick();
				}
			} catch (e) {
				// Ignore malformed responses.
			}
		};
		xhr.send();
	};

	/** Fills Hebrew dates the server left empty (PHP without intl). data-short leaves out the year. */
	function fillHebrewDates(root) {
		if (!window.Intl) {
			return;
		}
		var list = root.querySelectorAll('.kb-hebdate');
		for (var i = 0; i < list.length; i++) {
			var heb = list[i];
			if (heb.textContent) {
				continue;
			}
			try {
				var parts = (heb.getAttribute('data-date') || '').split('-');
				var day = new Date(+parts[0], +parts[1] - 1, +parts[2], 12);
				var fields = {};
				var tokens = new Intl.DateTimeFormat('he-IL-u-ca-hebrew-nu-latn', { day: 'numeric', month: 'long', year: 'numeric' }).formatToParts(day);
				for (var f = 0; f < tokens.length; f++) {
					fields[tokens[f].type] = tokens[f].value;
				}
				if (fields.day && fields.month && fields.year) {
					heb.textContent = gematria(+fields.day) + ' ב' + fields.month + (heb.getAttribute('data-short') ? '' : ' ' + gematria(+fields.year % 1000));
				}
			} catch (e) {
				// Browser without Hebrew calendar support: leave it out.
				return;
			}
		}
	}

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

	/** Mirrors Kivun_Board::gematria() in PHP: 8 → ח׳, 15 → ט״ו, 787 → תשפ״ז. */
	function gematria(n) {
		var values = [400, 300, 200, 100, 90, 80, 70, 60, 50, 40, 30, 20, 10, 9, 8, 7, 6, 5, 4, 3, 2, 1];
		var letters = 'תשרקצפעסנמלכיטחזוהדגבא';
		var out = '';
		while (n > 0) {
			if (n === 15 || n === 16) {
				out += n === 15 ? 'טו' : 'טז';
				break;
			}
			for (var i = 0; i < values.length; i++) {
				if (n >= values[i]) {
					out += letters.charAt(i);
					n -= values[i];
					break;
				}
			}
		}
		return out.length < 2 ? out + '׳' : out.slice(0, -1) + '״' + out.slice(-1);
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
		var weeks = document.querySelectorAll('.kb-week');
		for (var w = 0; w < weeks.length; w++) {
			if (!weeks[w].kivunWeek) {
				weeks[w].kivunWeek = new Week(weeks[w]);
			}
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
})();
