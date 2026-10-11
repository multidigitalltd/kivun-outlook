/**
 * Kivun Outlook Board – scaling, live refresh and row paging.
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
		this.scaler = el.querySelector('.kb-scaler');
		this.stage = el.querySelector('.kb-stage');
		this.page = 0;
		this.pageTimer = null;

		var self = this;
		this.fit();
		window.addEventListener('resize', function () { self.fit(); });
		this.setupPages();

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

	/** Splits the rows into pages that rotate when there are more rooms than fit. */
	Board.prototype.setupPages = function () {
		var rows = this.stage.querySelectorAll('.kb-table tbody tr');
		var dots = this.stage.querySelector('.kb-pages');
		var self = this;
		var compact = hasClass(this.el, 'kb-compact');
		var pages = (!compact && this.perPage > 0) ? Math.ceil(rows.length / this.perPage) : 1;

		if (this.pageTimer) {
			clearInterval(this.pageTimer);
			this.pageTimer = null;
		}
		if (this.page >= pages) {
			this.page = 0;
		}

		if (dots) {
			dots.innerHTML = '';
			toggleClass(dots, 'kb-has-pages', pages > 1);
			for (var i = 0; pages > 1 && i < pages; i++) {
				dots.appendChild(document.createElement('span'));
			}
		}

		function show() {
			for (var r = 0; r < rows.length; r++) {
				var visible = pages <= 1 || Math.floor(r / self.perPage) === self.page;
				toggleClass(rows[r], 'kb-hidden', !visible);
			}
			if (dots) {
				for (var d = 0; d < dots.children.length; d++) {
					toggleClass(dots.children[d], 'kb-active', d === self.page);
				}
			}
			self.equalizeRows(rows, compact, pages > 1 ? self.perPage : 0);
		}

		show();
		if (pages > 1) {
			this.pageTimer = setInterval(function () {
				self.page = (self.page + 1) % pages;
				show();
			}, this.pageSeconds * 1000);
		}
	};

	/** Gives the visible rows equal heights that fill the panel (stage mode only). */
	Board.prototype.equalizeRows = function (rows, compact, slots) {
		var wrap = this.stage.querySelector('.kb-table-wrap');
		var head = this.stage.querySelector('.kb-table thead');
		var visible = [];
		var r;
		for (r = 0; r < rows.length; r++) {
			rows[r].style.height = '';
			if (!hasClass(rows[r], 'kb-hidden')) {
				visible.push(rows[r]);
			}
		}
		if (compact || !wrap || !head || !visible.length) {
			return;
		}
		// With paging, size for a full page so a shorter last page keeps the same row height.
		var height = Math.floor((wrap.clientHeight - head.offsetHeight) / (slots || visible.length));
		for (r = 0; r < visible.length; r++) {
			visible[r].style.height = height + 'px';
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
