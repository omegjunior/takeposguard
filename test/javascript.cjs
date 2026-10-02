/* node test/javascript.cjs : isolated DOM/jQuery doubles, no payment providers. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(__dirname + '/../js/takeposguard.js', 'utf8');
let checks = 0;
function check(value, message) { assert.ok(value, message); checks++; }
function node(tag) {
	return {
		tagName: tag, attrs: {}, children: [], disabled: false, textContent: '', listeners: {},
		setAttribute(key, value) { this.attrs[key] = value; }, getAttribute(key) { return this.attrs[key] ?? null; },
		removeAttribute(key) { delete this.attrs[key]; }, appendChild(child) { this.children.push(child); },
		addEventListener(key, fn) { this.listeners[key] = fn; }
	};
}
function browser(path = 'index.php', parent = null, storage = null) {
	const values = storage || new Map();
	const button = node('button');
	const body = node('body');
	const doc = {
		body, readyState: 'loading', listeners: {},
		querySelectorAll() { return [button]; },
		getElementById(id) { return body.children.find(item => item.id === id) || null; },
		createElement: node, addEventListener(name, fn) { this.listeners[name] = fn; }
	};
	let sequence = 0;
	const window = {
		URL, URLSearchParams, document: doc, location: new URL('https://pos.test/erp/takepos/' + path),
		TakeposguardConfig: { enabled: true, basePath: '/erp/takepos/', scope: '1:1', waitSeconds: 120,
			messages: { pending: 'pending', uncertain: 'uncertain', crypto: 'crypto required', retry: 'retry' } },
		crypto: {
			randomUUID() { return '12345678-1234-4234-8234-' + (++sequence).toString(16).padStart(12, '0'); },
			getRandomValues(bytes) { bytes.fill(++sequence); return bytes; }
		},
		sessionStorage: { getItem: key => values.get(key) || null, setItem: (key, value) => values.set(key, value), removeItem: key => values.delete(key) },
		setTimeout(fn) { this.timeout = fn; }, alert(message) { this.alerted = message; },
		place: 0, closed: false
	};
	window.time = 1000;
	window.Date = { now: () => window.time };
	window.parent = parent || window;
	const prefilters = [];
	const sent = [];
	function jq() { return { html(value) { window.lastHtml = value; } }; }
	jq.ajaxPrefilter = fn => prefilters.push(fn);
	jq.ajax = original => {
		const options = { ...original };
		const callbacks = [], done = [];
		const xhr = {
			status: 0, headers: {}, aborted: false,
			always(fn) { callbacks.push(fn); return this; }, done(fn) { done.push(fn); return this; },
			abort() { this.aborted = true; this.finish(0); }, getResponseHeader(key) { return this.headers[key] || null; },
			finish(status = 200, response = '', headers = {}) {
				this.status = status; this.headers = headers;
				callbacks.forEach(fn => fn());
				if (status === 200) { done.forEach(fn => fn(response)); }
			}
		};
		prefilters.forEach(fn => fn(options, original, xhr));
		if (!xhr.aborted) { sent.push({ options, xhr }); }
		return xhr;
	};
	window.jQuery = jq;
	window.sent = sent;
	window.button = button;
	window.run = () => vm.runInNewContext(source, { window, Uint8Array, Object, Array, JSON, Error, Date: window.Date });
	window.ready = () => { doc.readyState = 'complete'; doc.listeners.DOMContentLoaded?.(); };
	return window;
}
const main = browser();
let nativeCalls = 0;
main.DirectPayment = () => { nativeCalls++; main.jQuery.ajax({ url: 'invoice.php?place=0&action=valid&token=CSRF&pay=LIQ' }); return true; };
main.run(); main.ready();
check(main.DirectPayment() === true && main.DirectPayment() === false && nativeCalls === 1, 'Double direct-payment call executes native function once');
check(main.button.disabled && main.button.getAttribute('aria-disabled') === 'true', 'Payment buttons immediately disabled');
const first = main.sent[0];
const uuid = new URL(first.options.url).searchParams.get('takeposguard_token');
check(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(uuid), 'Valid UUID v4');
check(new URL(first.options.url).searchParams.get('token') === 'CSRF', 'Native CSRF untouched');
check(main.jQuery.ajax({ url: 'invoice.php?place=0&action=valid' }).aborted && main.sent.length === 1, 'Concurrent direct AJAX request blocked');
first.xhr.finish(200, 'native fragment without commit evidence');
check(main.Takeposguard.getState().token === uuid && main.Takeposguard.getState().phase === 'uncertain', 'HTTP 200 alone does not rotate token');
check(main.DirectPayment() === false, 'No new native payment after uncertain response');
main.document.getElementById('takeposguard-status').children[0].listeners.click();
check(new URL(main.sent[1].options.url).searchParams.get('takeposguard_token') === uuid, 'Controlled retry retains exact token');
main.sent[1].xhr.finish(200, 'updated fragment', { 'X-Takeposguard-Token': uuid, 'X-Takeposguard-Status': 'SUCCESS' });
check(main.Takeposguard.getState() === null && !main.button.disabled && main.lastHtml === 'updated fragment', 'Matching confirmed outcome resets UI and updates retry fragment');
check(main.DirectPayment() === false, 'Very fast confirmed response still suppresses a second immediate click');
main.time += 1000;
main.DirectPayment();
const second = main.sent[2];
check(new URL(second.options.url).searchParams.get('takeposguard_token') !== uuid, 'Voluntary subsequent payment gets new token');
second.xhr.finish(500);
const saved = new Map(main.sessionStorage.getItem('takeposguard:/erp/takepos/:1:1') ? [['takeposguard:/erp/takepos/:1:1', main.sessionStorage.getItem('takeposguard:/erp/takepos/:1:1')]] : []);
const reloaded = browser('index.php', null, saved); reloaded.run(); reloaded.ready();
check(reloaded.Takeposguard.getState().token === main.Takeposguard.getState().token && reloaded.button.disabled, 'Reload preserves uncertain attempt');

const parent = browser(); parent.run(); parent.ready();
const child = browser('pay.php?invoiceid=42&place=0', parent);
child.Validate = payment => { parent.jQuery.ajax({ url: 'invoice.php?action=valid&invoiceid=42&pay=' + payment }); };
child.run(); child.ready(); child.Validate('CB'); child.Validate('CB');
check(parent.sent.length === 1 && parent.Takeposguard === child.parent.Takeposguard && parent.button.disabled && child.button.disabled, 'Modal uses parent prefilter and shared pending state');
check(new URL(parent.sent[0].options.url).searchParams.has('takeposguard_token'), 'Parent load request receives attempt token');
const rootState = parent.Takeposguard.getState();
parent.sent[0].xhr.finish(200, '', { 'X-Takeposguard-Status': 'SUCCESS', 'X-Takeposguard-Token': 'other' });
check(parent.Takeposguard.getState() === rootState, 'Mismatched result cannot clear pending attempt');

for (const url of ['invoice.php?action=addline', '/erp/compta/facture/invoice.php?action=valid', 'https://other.test/erp/takepos/invoice.php?action=valid', '/erp/takepos/smpcb.php?status']) {
	const before = parent.sent.length;
	parent.jQuery.ajax({ url });
	check(parent.sent.length === before + 1 && parent.sent.at(-1).options.url === url, 'Unrelated request untouched: ' + url);
}
const post = browser(); post.run(); post.ready();
post.jQuery.ajax({ url: 'invoice.php', type: 'POST', data: { action: 'valid', invoiceid: 7, token: 'native', takeposguard_token: 'spoofed' } });
check(new URLSearchParams(post.sent[0].options.data).get('token') === 'native' && !new URLSearchParams(post.sent[0].options.data).has('takeposguard_token'), 'POST removes competing guard token while preserving CSRF');
check(new URL(post.sent[0].options.url).searchParams.get('takeposguard_token') === post.Takeposguard.getState().token, 'POST action data precisely filtered');
const get = browser(); get.run(); get.ready();
get.jQuery.ajax({ url: 'invoice.php?action=addline', data: 'action=valid&place=0' });
check(!!get.Takeposguard.getState(), 'GET data action handled according to native request precedence');
const fallback = browser(); delete fallback.crypto.randomUUID; fallback.run(); fallback.ready();
fallback.jQuery.ajax({ url: 'invoice.php?action=valid' });
check(/-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-/.test(fallback.Takeposguard.getState().token), 'Cryptographic UUID fallback sets version and variant');
const noCrypto = browser(); noCrypto.crypto = null; noCrypto.run(); noCrypto.ready();
check(noCrypto.jQuery.ajax({ url: 'invoice.php?action=valid' }).aborted && noCrypto.alerted === 'crypto required', 'No insecure random fallback');
const disabled = browser(); disabled.TakeposguardConfig.enabled = false; const native = () => true; disabled.DirectPayment = native; disabled.run();
check(disabled.DirectPayment === native && !disabled.Takeposguard && !disabled.button.disabled, 'Disabled option leaves native JavaScript unchanged');
const unrelated = browser('../admin/index.php'); unrelated.run();
check(!unrelated.Takeposguard, 'Non-TakePOS page untouched');

const providerParent = browser(); providerParent.run(); providerParent.ready();
const provider = browser('pay.php?invoiceid=88', providerParent);
let charges = 0;
provider.ValidateStripeTerminal = () => { charges++; };
provider.ValidateSumup = () => { charges++; };
provider.run(); provider.ready(); provider.ValidateStripeTerminal(); provider.ValidateStripeTerminal(); provider.ValidateSumup();
check(charges === 1, 'Provider functions guarded before external launch');
providerParent.timeout();
check(providerParent.Takeposguard.getState().phase === 'uncertain' && provider.ValidateStripeTerminal() === false, 'Provider timeout does not authorize another charge');
providerParent.jQuery.ajax({ url: 'invoice.php?action=valid&invoiceid=88' });
check(providerParent.sent.length === 1, 'Late provider completion retains original attempt');
const providerToken = providerParent.Takeposguard.getState().token;
providerParent.sent[0].xhr.finish(200, '', { 'X-Takeposguard-Status': 'FAILED', 'X-Takeposguard-Token': providerToken });
check(providerParent.Takeposguard.getState().phase === 'uncertain' && provider.ValidateSumup() === false,
	'Native failure after provider charge never authorizes another external charge');
const sumup = browser('pay.php?invoiceid=89'); sumup.ValidateSumup = () => { charges++; }; sumup.run(); sumup.ready(); sumup.ValidateSumup();
sumup.jQuery.ajax({ url: '/erp/takepos/smpcb.php?status', type: 'POST', data: { token: 'csrf' } }).finish(200, 'FAILED');
check(sumup.Takeposguard.getState().phase === 'uncertain' && sumup.ValidateSumup() === false,
	'Session-wide SumUp FAILED cannot authorize a second external charge');
sumup.jQuery.ajax({ url: 'invoice.php?action=valid&invoiceid=89' });
sumup.jQuery.ajax({ url: '/erp/takepos/smpcb.php?status', type: 'POST' }).finish(200, 'FAILED');
check(sumup.Takeposguard.getState().active && sumup.jQuery.ajax({ url: 'invoice.php?action=valid&invoiceid=89' }).aborted,
	'Late provider status never clears an in-flight native request');
const provisionalParent = browser(); provisionalParent.run(); provisionalParent.ready();
const provisional = browser('pay.php?place=0&invoiceid=', provisionalParent);
provisional.Validate = () => provisionalParent.jQuery.ajax({ url: 'invoice.php?place=0&action=valid&invoiceid=123' });
provisional.run(); provisional.ready(); provisional.Validate();
check(provisionalParent.sent.length === 1 && provisionalParent.Takeposguard.getState().key === 'invoice:123', 'Provisional screen identity resolves to native invoice without changing attempt token');
const explicit = browser(); explicit.run(); explicit.ready();
const supplied = 'abcdef12-1234-4234-8234-123456789abc';
explicit.jQuery.ajax({ url: 'invoice.php?action=valid&takeposguard_token=' + supplied });
check(explicit.Takeposguard.getState().token === supplied, 'Integration UUID preserved for server replay protection');
const suppliedResponse = explicit.sent[0];
suppliedResponse.xhr.finish(200, '', { 'X-Takeposguard-Status': 'FAILED', 'X-Takeposguard-Token': supplied });
check(explicit.Takeposguard.getState() === null && !explicit.button.disabled, 'Confirmed failure of ordinary payment permits controlled new attempt');
explicit.time += 1000;
explicit.jQuery.ajax({ url: 'invoice.php?action=valid&takeposguard_token=' + supplied });
check(explicit.Takeposguard.getState().token === supplied, 'Replay with supplied UUID reaches server with same UUID');
(async function () {
	const stripe = browser('pay.php?invoiceid=90');
	stripe.terminal = { collectPaymentMethod: () => Promise.resolve({ error: { message: 'collection cancelled' } }) };
	stripe.ValidateStripeTerminal = () => stripe.terminal.collectPaymentMethod('test');
	stripe.run(); stripe.ready();
	await stripe.ValidateStripeTerminal();
	check(stripe.Takeposguard.getState() === null, 'Confirmed Stripe collection failure before processing permits new attempt');
	console.log(checks + ' JavaScript checks passed (DOM/jQuery doubles, no provider charges).');
})().catch(error => { console.error(error); process.exitCode = 1; });
