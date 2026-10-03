/**
 * Module registry and lifecycle.
 *
 * A module is `{ name, scope, when, load, mount }`:
 *  - scope 'document': mounted once for the life of the document (header,
 *    drawer, theme, session store, navigation …). Its signal never aborts.
 *  - scope 'content': mounted for the current page content; re-mounted after
 *    every soft navigation with a fresh signal. The previous signal aborts
 *    first, so listeners, observers and timers registered with it are gone
 *    before the next page's module runs. No module ever has to track what it
 *    bound.
 *  - `when`: a selector (or a function) that must match in the mount root for
 *    the module to run. Modules whose code is only needed on some routes put
 *    their body behind `load: () => import('…')` so the chunk is fetched only
 *    when `when` matches.
 *
 * mount(ctx) receives `{ root, signal, config, engine, reason }` and may
 * return a cleanup function (called in addition to the signal abort).
 */
import { doc, emit, idle } from './dom.js';

const modules = [];
const contentState = { controller: null, cleanups: [], generation: 0 };
const documentState = { mounted: new Set(), cleanups: [] };
const noAbort = new AbortController().signal;

export const config = (() => {
	const cfg = window.DelicatEngine && typeof window.DelicatEngine === 'object' ? window.DelicatEngine : {};
	window.DelicatEngine = cfg;
	return cfg;
})();

/**
 * The PHP side prints the configuration as an inline block that the navigation
 * engine re-executes when its content changes between pages. That assigns a
 * fresh object to window.DelicatEngine; fold it into the object every module
 * holds so nothing keeps reading the previous page's data.
 */
export function syncConfig() {
	const fresh = window.DelicatEngine;
	if (!fresh || fresh === config || typeof fresh !== 'object') return config;
	for (const key of Object.keys(config)) delete config[key];
	Object.assign(config, fresh);
	window.DelicatEngine = config;
	return config;
}

export function define(module) {
	if (!module || typeof module.name !== 'string') throw new Error('engine: module needs a name');
	module.scope = module.scope === 'document' ? 'document' : 'content';
	modules.push(module);
	return module;
}

function matches(module, root) {
	const when = module.when;
	if (!when) return true;
	if (typeof when === 'function') { try { return !!when(root, config); } catch (_) { return false; } }
	try { return !!root.querySelector(when) || (root !== doc && root.matches && root.matches(when)); } catch (_) { return false; }
}

async function resolveMount(module) {
	if (typeof module.mount === 'function') return module.mount;
	if (typeof module.load === 'function') {
		const loaded = await module.load();
		const mount = loaded && (typeof loaded.default === 'function' ? loaded.default : loaded.mount);
		if (typeof mount === 'function') { module.mount = mount; return mount; }
	}
	return null;
}

async function runModule(module, ctx, store) {
	let mount;
	try {
		mount = await resolveMount(module);
	} catch (error) {
		report(module, error, 'load');
		return;
	}
	if (!mount || ctx.signal.aborted) return;
	try {
		const cleanup = await mount(ctx);
		if (typeof cleanup === 'function') {
			if (ctx.signal.aborted) cleanup();
			else store.push(cleanup);
		}
	} catch (error) {
		report(module, error, 'mount');
	}
}

function report(module, error, phase) {
	try {
		if (config.debug) console.error(`[delicat-engine] ${module.name} failed to ${phase}`, error);
		emit('delicat:engine:error', { module: module.name, phase, message: String(error && error.message || error) });
	} catch (_) {}
}

let engineApi = {};
export function attach(api) { engineApi = Object.assign(engineApi, api); return engineApi; }
export const engine = engineApi;

export async function mountDocument(reason = 'load') {
	const tasks = [];
	for (const module of modules) {
		if (module.scope !== 'document' || documentState.mounted.has(module)) continue;
		if (!matches(module, doc)) continue;
		documentState.mounted.add(module);
		tasks.push(runModule(module, { root: doc, signal: noAbort, config, engine: engineApi, reason }, documentState.cleanups));
	}
	await Promise.all(tasks);
}

/** Mount content-scoped modules for `root` (the current <main>), after releasing the previous page's. */
export async function mountContent(root, reason = 'load') {
	if (contentState.controller) {
		contentState.controller.abort();
		for (const cleanup of contentState.cleanups.splice(0)) { try { cleanup(); } catch (_) {} }
	}
	const controller = new AbortController();
	contentState.controller = controller;
	const generation = ++contentState.generation;
	const ctx = { root: root || doc, signal: controller.signal, config, engine: engineApi, reason };
	const tasks = [];
	for (const module of modules) {
		if (module.scope !== 'content') continue;
		if (!matches(module, ctx.root) && !(module.always && matches(module, doc))) continue;
		tasks.push(runModule(module, ctx, contentState.cleanups));
	}
	await Promise.all(tasks);
	if (generation !== contentState.generation) return;
	emit('delicat:engine:mounted', { root: ctx.root, reason });
}

/** Later document-scope modules (registered after boot) still get mounted. */
export function scheduleMountDocument() {
	idle(() => { mountDocument('late'); }, 800);
}

export const contentSignal = () => (contentState.controller ? contentState.controller.signal : noAbort);
