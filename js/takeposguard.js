/* Copyright (C) 2026 Fred Omega Junior; GPL-3.0-or-later */
(function (win) {
	'use strict';
	var config = win.TakeposguardConfig;
	if (!config || !config.enabled || !win.URL || !win.URLSearchParams) { return; }
	var page = new win.URL(win.location.href);
	if ([config.basePath + 'index.php', config.basePath + 'pay.php'].indexOf(page.pathname) < 0) { return; }
	var owner = win;
	try {
		if (win.parent !== win && win.parent.location.origin === page.origin && win.parent.TakeposguardConfig
			&& win.parent.TakeposguardConfig.scope === config.scope) { owner = win.parent; }
	} catch (ignored) { /* No cross-origin frame access. */ }
	var names = ['Validate', 'DirectPayment', 'ValidateStripeTerminal', 'ValidateSumup'];
	var selector = '[onclick*="Validate("],[onclick*="DirectPayment("],[onclick*="ValidateStripeTerminal("],[onclick*="ValidateSumup("]';
	var uuidPattern = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
	var guard = owner.Takeposguard;
	if (!guard) {
		var storageKey = 'takeposguard:' + config.basePath + ':' + config.scope;
		var state = null;
		var settledUntil = 0;
		try {
			var saved = JSON.parse(owner.sessionStorage.getItem(storageKey));
			if (saved && typeof saved.token === 'string' && saved.token.length === 36 && uuidPattern.test(saved.token) && typeof saved.key === 'string') {
				state = { key: saved.key, token: saved.token, phase: 'uncertain', provider: saved.provider || false, active: false };
			}
		} catch (ignored) { /* In-memory coordination still works if storage is unavailable. */ }
		var views = [];
		var buttons = [];
		function save() {
			try {
				if (state) { owner.sessionStorage.setItem(storageKey, JSON.stringify({ key: state.key, token: state.token, provider: state.provider })); }
				else { owner.sessionStorage.removeItem(storageKey); }
			} catch (ignored) {}
		}
		function token() {
			var crypto = owner.crypto;
			if (!crypto || !crypto.getRandomValues) { throw new Error('crypto'); }
			if (crypto.randomUUID) { return crypto.randomUUID(); }
			var bytes = new Uint8Array(16);
			crypto.getRandomValues(bytes);
			bytes[6] = (bytes[6] & 15) | 64;
			bytes[8] = (bytes[8] & 63) | 128;
			var hex = Array.prototype.map.call(bytes, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
			return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16) + '-' + hex.slice(16, 20) + '-' + hex.slice(20);
		}
		function paint() {
			var busy = !!state;
			buttons.forEach(function (entry) {
				if (!busy) {
					entry.node.disabled = entry.disabled;
					if (entry.aria === null) { entry.node.removeAttribute('aria-disabled'); }
					else { entry.node.setAttribute('aria-disabled', entry.aria); }
				} else { entry.node.disabled = true; entry.node.setAttribute('aria-disabled', 'true'); }
			});
			if (!busy) { buttons = []; }
			views = views.filter(function (view) { return !view.closed; });
			views.forEach(function (view) {
				var doc = view.document;
				if (!doc.body) { return; }
				if (busy) {
					Array.prototype.forEach.call(doc.querySelectorAll(selector), function (node) {
						if (!buttons.some(function (entry) { return entry.node === node; })) {
							buttons.push({ node: node, disabled: !!node.disabled, aria: node.getAttribute('aria-disabled') });
						}
						node.disabled = true; node.setAttribute('aria-disabled', 'true');
					});
				}
				var panel = doc.getElementById('takeposguard-status');
				if (!panel) {
					panel = doc.createElement('div'); panel.id = 'takeposguard-status';
					panel.className = 'warning clearboth'; panel.setAttribute('role', 'status');
					panel.setAttribute('aria-live', 'polite'); doc.body.appendChild(panel);
				}
				panel.hidden = !busy;
				var message = busy && state.phase === 'pending' ? config.messages.pending : config.messages.uncertain;
				var phase = busy ? state.phase + (state.checking ? ':checking' : '') : 'idle';
				if (panel.getAttribute('data-phase') !== phase) {
					panel.textContent = message;
					panel.setAttribute('data-phase', phase);
					if (busy && state.phase === 'uncertain' && config.statusUrl) {
						var check = doc.createElement('button'); check.type = 'button'; check.className = 'button';
						check.textContent = config.messages.check; check.disabled = !!state.checking;
						check.addEventListener('click', checkResult); panel.appendChild(check);
					}
					if (busy && state.phase === 'uncertain' && !state.provider && state.request && !state.active) {
						var retry = doc.createElement('button'); retry.type = 'button'; retry.className = 'button';
						retry.textContent = config.messages.retry;
						retry.addEventListener('click', function () {
							if (!state || state.active || state.checking || !state.request) { return; }
							// Repeat only the exact native request with the original attempt token.
							owner.jQuery.ajax(Object.assign({}, state.request)).done(function (html) {
								owner.jQuery('#poslines').html(html);
							});
						});
						panel.appendChild(retry);
					}
				}
			});
		}
		function uncertain() {
			if (state) { state.phase = 'uncertain'; save(); paint(); }
		}
		function checkResult() {
			if (!state || state.active || state.checking || !config.statusUrl) { return; }
			var current = state; current.checking = true; paint();
			owner.jQuery.ajax({ url: config.statusUrl, type: 'POST', dataType: 'json',
				data: { action: 'recover', token: config.csrfToken, takeposguard_token: current.token }
			}).done(function (result) {
				if (state !== current || !result || result.operation_token !== current.token) { return; }
				if (result.status === 'SUCCESS' || (result.status === 'FAILED' && !current.provider)) {
					settledUntil = Date.now() + 600; state = null; save(); paint();
				}
			}).always(function () {
				if (state === current) { current.checking = false; uncertain(); }
			});
		}
		function begin(key, provider, suppliedToken) {
			if (state || Date.now() < settledUntil) { return false; }
			try { state = { key: key, token: suppliedToken || token(), provider: provider, phase: 'pending', active: false }; }
			catch (error) { owner.alert(config.messages.crypto); return false; }
			save(); paint();
			var current = state;
			owner.setTimeout(function () { if (state === current && !state.active) { uncertain(); } }, config.waitSeconds * 1000);
			return true;
		}
		function match(options, view) {
			var url;
			try { url = new view.URL(options.url, view.location.href); } catch (error) { return null; }
			if (url.origin !== page.origin || url.pathname !== config.basePath + 'invoice.php') { return null; }
			var data = new view.URLSearchParams(typeof options.data === 'string' ? options.data : options.data || {});
			var post = String(options.type || options.method || 'GET').toUpperCase() === 'POST';
			// jQuery appends GET data after the URL query; PHP keeps the last value.
			function value(name) { return data.has(name) ? data.get(name) : url.searchParams.get(name); }
			if (value('action') !== 'valid') { return null; }
			var id = value('invoiceid');
			var supplied = value('takeposguard_token');
			return { url: url, data: data, post: post, placeKey: 'place:' + (value('place') || '0'),
				suppliedToken: supplied && supplied.length === 36 && uuidPattern.test(supplied) ? supplied.toLowerCase() : null,
				key: id && /^[1-9][0-9]*$/.test(id) ? 'invoice:' + id : 'place:' + (value('place') || '0') };
		}
		function installAjax(view) {
			var jq = view.jQuery;
			if (!jq || !jq.ajaxPrefilter || jq.__takeposguardInstalled) { return; }
			jq.__takeposguardInstalled = true;
			jq.ajaxPrefilter(function (options, original, xhr) {
				var providerUrl;
				try { providerUrl = new view.URL(options.url, view.location.href); } catch (ignored) {}
				if (providerUrl && providerUrl.origin === page.origin && providerUrl.pathname === config.basePath + 'smpcb.php'
					&& providerUrl.searchParams.has('status')) {
					var waiting = state;
					xhr.done(function (result) {
						// Native status is session-wide, not tied to an attempt or invoice.
						if (result === 'FAILED' && state === waiting && state && state.provider === 'sumup'
							&& !state.active && !state.request) { uncertain(); }
					});
				}
				var request = match(options, view);
				if (!request) { return; }
				if (!state && !begin(request.key, false, request.suppliedToken)) { xhr.abort('takeposguard'); return; }
				// pay.php resolves a provisional invoice in PHP even when its URL has no invoiceid.
				if (!state.active && !state.request && state.key === request.placeKey && request.key.indexOf('invoice:') === 0) {
					state.key = request.key;
				}
				if (state.key !== request.key || state.active || (request.suppliedToken && request.suppliedToken !== state.token)) {
					xhr.abort('takeposguard'); return;
				}
				request.url.searchParams.delete('takeposguard_token');
				request.data.delete('takeposguard_token');
				request.url.searchParams.set('takeposguard_token', state.token);
				options.url = request.url.href;
				if (options.data) { options.data = request.data.toString(); }
				state.phase = 'pending'; state.active = true;
				state.request = { url: options.url, type: options.type || options.method || 'GET', data: options.data,
					dataType: options.dataType, contentType: options.contentType, headers: options.headers };
				var current = state;
				save(); paint();
				xhr.always(function () {
					if (state !== current) { return; }
					state.active = false;
					// Only token-correlated server evidence authorizes clearing this attempt.
					var outcome = xhr.getResponseHeader('X-Takeposguard-Status');
					var responseToken = xhr.getResponseHeader('X-Takeposguard-Token');
					if (responseToken === state.token && (outcome === 'SUCCESS' || (outcome === 'FAILED' && !state.provider))) {
						settledUntil = Date.now() + 600; // Ignore the second click even if a local response was very fast.
						state = null; save(); paint();
					} else { uncertain(); }
				});
			});
		}
		function cancelProvider(kind) {
			// Confirmed failure before native invoice processing; never undo an uncertain charge.
			if (state && state.provider === kind && !state.active && !state.request) {
				state = null; save(); paint();
			}
		}
		guard = {
			begin: begin, uncertain: uncertain, installAjax: installAjax, paint: paint, checkResult: checkResult,
			cancelProvider: cancelProvider,
			addView: function (view) { if (views.indexOf(view) < 0) { views.push(view); } installAjax(view); paint(); },
			getState: function () { return state; }
		};
		owner.Takeposguard = guard;
	}
	function screenKey() {
		var id = page.searchParams.get('invoiceid');
		return id && /^[1-9][0-9]*$/.test(id) ? 'invoice:' + id
			: 'place:' + (page.searchParams.get('place') || win.place || '0');
	}
	function wrap() {
		guard.installAjax(owner); guard.installAjax(win);
		if (win.terminal && typeof win.terminal.collectPaymentMethod === 'function'
			&& !win.terminal.collectPaymentMethod.__takeposguardWrapped) {
			var collect = win.terminal.collectPaymentMethod;
			var wrappedCollect = function () {
				var attempt = guard.getState();
				var result = collect.apply(this, arguments);
				result.then(function (value) {
					if (guard.getState() === attempt && value && value.error) { guard.cancelProvider('stripe'); }
				}, function () { if (guard.getState() === attempt) { guard.uncertain(); } });
				return result;
			};
			wrappedCollect.__takeposguardWrapped = true;
			win.terminal.collectPaymentMethod = wrappedCollect;
		}
		names.forEach(function (name) {
			var native = win[name];
			if (typeof native !== 'function' || native.__takeposguardWrapped) { return; }
			var wrapped = function () {
				var provider = name === 'ValidateStripeTerminal' ? 'stripe' : (name === 'ValidateSumup' ? 'sumup' : false);
				if (!guard.begin(screenKey(), provider)) { return false; }
				try { return native.apply(this, arguments); }
				catch (error) { guard.uncertain(); throw error; }
			};
			wrapped.__takeposguardWrapped = true;
			win[name] = wrapped;
		});
	}
	guard.addView(owner); guard.addView(win);
	win.document.addEventListener('click', function (event) {
		wrap();
		var node = event.target.closest && event.target.closest(selector);
		if (node && (guard.getState() || event.detail > 1)) { event.preventDefault(); event.stopImmediatePropagation(); }
	}, true);
	function ready() {
		wrap(); guard.paint();
		if (win.MutationObserver && win.document.body) {
			new win.MutationObserver(function () { if (guard.getState()) { guard.paint(); } })
				.observe(win.document.body, { childList: true, subtree: true });
		}
	}
	if (win.document.readyState === 'loading') { win.document.addEventListener('DOMContentLoaded', ready); }
	else { ready(); }
})(window);
