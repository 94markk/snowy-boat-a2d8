/**
 * Network helpers: timeouts, admin-ajax and REST wrappers, JSON parsing that
 * tolerates stray output before the body (WooCommerce's own scripts do the same).
 */
import { config } from './runtime.js';
import { safeUrl } from './dom.js';

export function timeoutSignal(ms, parent) {
	const controller = new AbortController();
	const timer = window.setTimeout(() => controller.abort(new Error('timeout')), ms);
	const cleanup = () => window.clearTimeout(timer);
	if (parent) {
		if (parent.aborted) controller.abort(parent.reason);
		else parent.addEventListener('abort', () => controller.abort(parent.reason), { once: true });
	}
	controller.signal.addEventListener('abort', cleanup, { once: true });
	return { signal: controller.signal, cleanup, controller };
}

export async function request(url, init = {}, ms = 8000) {
	const target = safeUrl(url, init.sameOrigin !== false);
	if (!target) throw new Error('unsafe-url');
	const { signal, cleanup } = timeoutSignal(ms, init.signal);
	try {
		return await fetch(target, { credentials: 'same-origin', ...init, signal });
	} finally {
		cleanup();
	}
}

export function parseJson(raw) {
	try { return JSON.parse(raw); } catch (_) {}
	const text = String(raw || '');
	let start = text.indexOf('{"');
	const end = text.lastIndexOf('}');
	if (start !== -1 && end > start) {
		try { return JSON.parse(text.slice(start, end + 1)); } catch (_) {}
	}
	start = text.indexOf('[');
	if (start !== -1) {
		const close = text.lastIndexOf(']');
		if (close > start) { try { return JSON.parse(text.slice(start, close + 1)); } catch (_) {} }
	}
	return null;
}

export async function getJson(url, init = {}, ms = 8000) {
	const response = await request(url, { headers: { Accept: 'application/json', ...(init.headers || {}) }, cache: 'no-store', ...init }, ms);
	const raw = await response.text();
	const data = parseJson(raw);
	if (!response.ok) { const error = new Error('http-' + response.status); error.status = response.status; error.data = data; throw error; }
	if (data === null) throw new Error('invalid-json');
	return data;
}

export const ajaxUrl = () => String(config.ajaxUrl || (window.DelicaBuilderV9 && window.DelicaBuilderV9.ajaxUrl) || '/wp-admin/admin-ajax.php');

/** admin-ajax POST (urlencoded). Resolves with `data` when success === true, otherwise throws with .data. */
export async function ajax(action, fields = {}, options = {}) {
	const body = new URLSearchParams();
	body.set('action', action);
	for (const [key, value] of Object.entries(fields)) {
		if (Array.isArray(value)) value.forEach((item) => body.append(key + '[]', String(item)));
		else if (value !== undefined && value !== null) body.set(key, String(value));
	}
	const response = await request(options.url || ajaxUrl(), {
		method: 'POST',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
		body: body.toString(),
		cache: 'no-store',
		signal: options.signal,
	}, options.timeout || 8000);
	const raw = await response.text();
	const payload = parseJson(raw);
	if (!response.ok || !payload || payload.success !== true) {
		const error = new Error('ajax-failed');
		error.status = response.status;
		error.payload = payload;
		error.data = payload && payload.data;
		throw error;
	}
	return payload.data === undefined ? {} : payload.data;
}

export async function rest(url, { method = 'GET', body, nonce, signal, timeout = 6500, cache = 'no-store' } = {}) {
	const headers = { Accept: 'application/json' };
	if (nonce) headers['X-WP-Nonce'] = nonce;
	if (body !== undefined) headers['Content-Type'] = 'application/json';
	return getJson(url, { method, headers, body: body === undefined ? undefined : JSON.stringify(body), cache, redirect: 'follow', signal }, timeout);
}
