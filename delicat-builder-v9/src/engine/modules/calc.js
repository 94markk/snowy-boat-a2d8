/**
 * Product-fields calculator (front end). A port of calc-front.js: the pricing
 * logic is unchanged; what changed is the lifecycle. Everything bound to the
 * document or body is namespaced (.dmcCalc) or signal-bound so a soft
 * navigation away from the product releases it, and the help modal registers
 * with the shared overlay registry.
 *
 * jQuery stays: WooCommerce's variation form talks through jQuery events
 * (found_variation, reset_data …) and this module listens to them.
 */
import { doc, jq, on, sleep, win } from '../core/dom.js';
import { registerOverlay, releaseOverlay } from '../core/overlay.js';

const FUNCS = { abs: 1, ceil: 1, floor: 1, round: 1, sqrt: 1, min: 2, max: 2, pow: 2 };
const OPS = { 'u-': { p: 5, a: 'r' }, '^': { p: 4, a: 'r' }, '*': { p: 3, a: 'l' }, '/': { p: 3, a: 'l' }, '%': { p: 3, a: 'l' }, '+': { p: 2, a: 'l' }, '-': { p: 2, a: 'l' } };
const DMC_HAS = (() => { try { return !!(win.CSS && win.CSS.supports && win.CSS.supports('selector(:has(*))')); } catch (_) { return false; } })();

function tokenize(expr, vars) {
	const t = []; let i = 0; const n = expr.length; let prev = null; let m;
	while (i < n) {
		const ch = expr[i];
		if (/\s/.test(ch)) { i++; continue; }
		if (/[0-9]/.test(ch) || (ch === '.' && /[0-9]/.test(expr[i + 1] || ''))) {
			m = expr.slice(i).match(/^\d*\.?\d+([eE][+-]?\d+)?/);
			if (!m) return null;
			t.push(['num', parseFloat(m[0])]); i += m[0].length; prev = 'num'; continue;
		}
		if (ch === '{') {
			m = expr.slice(i).match(/^\{([A-Za-z0-9_]+)\}/);
			if (!m) return null;
			t.push(['num', parseFloat(vars[m[1]]) || 0]); i += m[0].length; prev = 'num'; continue;
		}
		if (/[A-Za-z_]/.test(ch)) {
			m = expr.slice(i).match(/^[A-Za-z_][A-Za-z0-9_]*/);
			const word = m[0], lower = word.toLowerCase(); let j = i + word.length;
			while (j < n && /\s/.test(expr[j])) j++;
			if (expr[j] === '(') {
				if (!FUNCS[lower]) return null;
				t.push(['func', lower]); i += word.length; prev = 'func'; continue;
			}
			t.push(['num', parseFloat(vars[word]) || 0]); i += word.length; prev = 'num'; continue;
		}
		if (ch === '(') { t.push(['lp']); i++; prev = 'lp'; continue; }
		if (ch === ')') { t.push(['rp']); i++; prev = 'rp'; continue; }
		if (ch === ',') { t.push(['comma']); i++; prev = 'comma'; continue; }
		if ('+-*/%^'.indexOf(ch) > -1) {
			const unary = prev === null || prev === 'op' || prev === 'lp' || prev === 'comma';
			if (ch === '-' && unary) t.push(['op', 'u-']);
			else if (!(ch === '+' && unary)) t.push(['op', ch]);
			i++; prev = 'op'; continue;
		}
		return null;
	}
	return t;
}

function toRpn(tokens) {
	const out = [], st = [];
	for (const tk of tokens) {
		if (tk[0] === 'num') out.push(tk);
		else if (tk[0] === 'func') st.push(tk);
		else if (tk[0] === 'comma') { while (st.length && st[st.length - 1][0] !== 'lp') out.push(st.pop()); if (!st.length) return null; }
		else if (tk[0] === 'op') {
			const o1 = OPS[tk[1]];
			while (st.length && st[st.length - 1][0] === 'op') {
				const o2 = OPS[st[st.length - 1][1]];
				if ((o1.a === 'l' && o1.p <= o2.p) || (o1.a === 'r' && o1.p < o2.p)) out.push(st.pop()); else break;
			}
			st.push(tk);
		} else if (tk[0] === 'lp') st.push(tk);
		else if (tk[0] === 'rp') {
			while (st.length && st[st.length - 1][0] !== 'lp') out.push(st.pop());
			if (!st.length) return null;
			st.pop();
			if (st.length && st[st.length - 1][0] === 'func') out.push(st.pop());
		}
	}
	while (st.length) { const top = st.pop(); if (top[0] === 'lp' || top[0] === 'rp') return null; out.push(top); }
	return out;
}

function evalRpn(rpn) {
	const s = [];
	for (const t of rpn) {
		if (t[0] === 'num') { s.push(t[1]); continue; }
		if (t[0] === 'op') {
			if (t[1] === 'u-') { if (!s.length) return 0; s.push(-s.pop()); continue; }
			if (s.length < 2) return 0;
			const b = s.pop(), a = s.pop();
			if (t[1] === '+') s.push(a + b); else if (t[1] === '-') s.push(a - b);
			else if (t[1] === '*') s.push(a * b); else if (t[1] === '/') s.push(b === 0 ? 0 : a / b);
			else if (t[1] === '%') s.push(b === 0 ? 0 : a % b); else if (t[1] === '^') s.push(Math.pow(a, b));
			continue;
		}
		if (t[0] === 'func') {
			const nargs = FUNCS[t[1]];
			if (s.length < nargs) return 0;
			const args = s.splice(s.length - nargs, nargs);
			switch (t[1]) {
				case 'abs': s.push(Math.abs(args[0])); break;
				case 'ceil': s.push(Math.ceil(args[0])); break;
				case 'floor': s.push(Math.floor(args[0])); break;
				case 'round': s.push(Math.round(args[0])); break;
				case 'sqrt': s.push(args[0] >= 0 ? Math.sqrt(args[0]) : 0); break;
				case 'min': s.push(Math.min(args[0], args[1])); break;
				case 'max': s.push(Math.max(args[0], args[1])); break;
				case 'pow': s.push(Math.pow(args[0], args[1])); break;
			}
		}
	}
	return s.length === 1 ? s[0] : 0;
}

function evalExpr(expr, vars) {
	if (!expr) return null;
	const tk = tokenize(expr, vars); if (!tk) return null;
	const rpn = toRpn(tk); if (!rpn) return null;
	const r = evalRpn(rpn); return isFinite(r) ? r : null;
}

function formatPrice(amount, money) {
	const d = money && money.decimals != null ? Number(money.decimals) : 2;
	const sym = (money && money.symbol) || '';
	const pos = (money && money.position) || 'left';
	const num = amount.toFixed(d);
	switch (pos) {
		case 'right': return num + sym;
		case 'left_space': return sym + ' ' + num;
		case 'right_space': return num + ' ' + sym;
		default: return sym + num;
	}
}

function unitPrice(amount, base, ptype) {
	amount = parseFloat(amount) || 0;
	return ptype === 'percent' ? (base * amount / 100) : amount;
}

function cleanHtml(html) {
	const div = doc.createElement('div');
	div.innerHTML = html || '';
	return (div.textContent || div.innerText || '').replace(/\s+/g, ' ').trim();
}

export default async function mount({ root, signal, config }) {
	let $ = jq();
	for (let waited = 0; !$ && waited < 3000 && !signal.aborted; waited += 100) { await sleep(100); $ = jq(); }
	if (!$ || signal.aborted) return;
	const money = () => win.DMCCalc || config.calc || {};
	const stamp = [];

	function stampHasFallback() {
		if (DMC_HAS) return;
		$('.dmc-calc').each(function () {
			const calc = this, form = $(calc).closest('form.variations_form, form.cart')[0];
			const studio = !!calc.querySelector('.dmc-purchase-studio') || calc.classList.contains('dmc-purchase-studio');
			calc.classList.toggle('dmc-hasfb-studio', studio);
			calc.classList.toggle('dmc-hasfb-trust', !!calc.querySelector('.dmc-integrated-trust'));
			if (form) {
				form.classList.toggle('dmc-hasfb-studio', studio);
				form.classList.toggle('dmc-hasfb-totalcard', !!form.querySelector('.dmc-plan-total-card'));
			}
			$(calc).closest('.single_variation_wrap, .woocommerce-variation-add-to-cart, .spb-purchase').toggleClass('dmc-hasfb-studio', studio);
		});
	}
	$(doc).on('found_variation.dmcCalc reset_data.dmcCalc dmc:total_updated.dmcCalc', stampHasFallback);
	$('.dmc-calc.dmc-purchase-studio').add($('.dmc-purchase-studio').closest('.dmc-calc')).each(function () {
		const el = this, cs = win.getComputedStyle(el), form = $(el).closest('form.variations_form, form.cart')[0];
		if (!form) return;
		for (const key of ['--dmc-section-spacing', '--dmc-quantity-spacing']) { const value = cs.getPropertyValue(key); if (value) form.style.setProperty(key, value.trim()); }
	});

	function initCalc($calc) {
		if ($calc.attr('data-dmc-initialized') === '1') return;
		let cfg;
		try { cfg = JSON.parse($calc.attr('data-config')); } catch (_) { return; }
		if (!cfg || !Array.isArray(cfg.fields)) return;
		$calc.attr('data-dmc-initialized', '1');
		const defaultBase = parseFloat($calc.attr('data-base')) || 0;
		let base = defaultBase;
		const rate = parseFloat($calc.attr('data-rate')) || 1;
		let customRate = parseFloat($calc.attr('data-customrate'));
		if (isNaN(customRate)) customRate = 1;

		const fieldEls = (id) => $calc.find('[data-field="' + id + '"]');
		const optionPrice = (f, value) => { let p = 0; (f.options || []).forEach((o) => { if (String(o.value) === String(value)) p = o.price; }); return p; };
		function rawValue(f) {
			const $f = fieldEls(f.id);
			if (f.type === 'toggle') return $f.find('input:checked').length ? 'yes' : '';
			if (f.type === 'checkbox') { const arr = []; $f.find('input:checked').each(function () { arr.push(this.value); }); return arr; }
			if (f.type === 'radio') { const r = $f.find('input:checked'); return r.length ? r.val() : ''; }
			const v = $f.find('.dmc-input').val();
			return (v === undefined || v === null) ? '' : v;
		}
		function compare(a, op, b) {
			if (Array.isArray(a)) { const has = a.map(String).indexOf(String(b)) > -1; return (op === '!=' || op === 'not_contains') ? !has : has; }
			const na = parseFloat(a), nb = parseFloat(b);
			if (!isNaN(na) && !isNaN(nb) && a !== '' && b !== '') {
				switch (op) {
					case '>': return na > nb; case '<': return na < nb;
					case '>=': return na >= nb; case '<=': return na <= nb;
					case '!=': return na !== nb; default: return na === nb;
				}
			}
			const sa = String(a), sb = String(b);
			if (op === 'contains') return sb === '' ? true : sa.indexOf(sb) > -1;
			if (op === 'not_contains') return sb === '' ? false : sa.indexOf(sb) === -1;
			return op === '!=' ? sa !== sb : sa === sb;
		}
		function activeMap(values) {
			const active = {};
			cfg.fields.forEach((f) => { active[f.id] = true; });
			for (let p = 0; p < cfg.fields.length; p++) {
				let changed = false;
				cfg.fields.forEach((f) => {
					let ok = true;
					if (f.conditions && f.conditions.length) {
						const res = f.conditions.map((c) => { const refActive = active[c.field] !== false; const v = (refActive && values[c.field] !== undefined) ? values[c.field] : ''; return compare(v, c.op, c.value); });
						ok = (f.condition_logic === 'any') ? res.indexOf(true) > -1 : res.indexOf(false) === -1;
					}
					if (active[f.id] !== ok) { active[f.id] = ok; changed = true; }
				});
				if (!changed) break;
			}
			return active;
		}
		const clampNum = (f, val) => {
			let num = parseFloat(val); if (isNaN(num)) num = 0;
			if (f.min !== '' && f.min != null) num = Math.max(parseFloat(f.min), num);
			if (f.max !== '' && f.max != null) num = Math.min(parseFloat(f.max), num);
			return num;
		};
		function contribution(f, values, active) {
			if (!active[f.id]) return 0;
			const ptype = f.price_type === 'percent' ? 'percent' : 'fixed';
			const val = values[f.id];
			if (f.type === 'number' || f.type === 'range') return clampNum(f, val) * unitPrice(f.price, base, ptype);
			if (f.type === 'toggle') return val === 'yes' ? unitPrice(f.price, base, ptype) : 0;
			if (f.type === 'select' || f.type === 'radio') return val ? unitPrice(optionPrice(f, val), base, ptype) : 0;
			if (f.type === 'checkbox') { let sum = 0; (val || []).forEach((v) => { sum += unitPrice(optionPrice(f, v), base, ptype); }); return sum; }
			if (f.type === 'hidden') return unitPrice(f.price, base, ptype);
			return 0;
		}
		function formulaValue(f, values) {
			const v = values[f.id];
			if (f.type === 'number' || f.type === 'range') return clampNum(f, v);
			if (f.type === 'toggle') return v === 'yes' ? 1 : 0;
			if (f.type === 'select' || f.type === 'radio') return v ? (parseFloat(optionPrice(f, v)) || 0) : 0;
			if (f.type === 'checkbox') { let s = 0; (v || []).forEach((x) => { s += parseFloat(optionPrice(f, x)) || 0; }); return s; }
			if (f.type === 'hidden') { const d = parseFloat(f.default); return isNaN(d) ? (parseFloat(f.price) || 0) : d; }
			return 0;
		}
		function recalc() {
			const values = {};
			cfg.fields.forEach((f) => { values[f.id] = rawValue(f); });
			const active = activeMap(values);
			cfg.fields.forEach((f) => {
				const $field = fieldEls(f.id), isActive = !!active[f.id];
				$field.toggle(isActive).attr('aria-hidden', isActive ? 'false' : 'true');
				$field.find(':input').prop('disabled', !isActive);
			});
			$calc.find('.dmc-opt').each(function () { $(this).toggleClass('dmc-selected', $(this).find('input').is(':checked')); });
			const vars = { base, rate: customRate }; let sum = 0;
			cfg.fields.forEach((f) => { const isOn = !!active[f.id]; vars[f.id] = isOn ? formulaValue(f, values) : 0; sum += isOn ? contribution(f, values, active) : 0; });
			let totalBase;
			if (cfg.formula && cfg.formula.trim()) { totalBase = evalExpr(cfg.formula, vars); if (totalBase === null) totalBase = base + sum; }
			else totalBase = base + sum;
			if (!isFinite(totalBase) || totalBase < 0) totalBase = base + sum;
			if (!isFinite(totalBase) || totalBase < 0) totalBase = base;

			let qty = 1;
			const $productForm = $calc.closest('form.cart');
			const $qty = $productForm.find('input.qty').first();
			if ($qty.length) qty = parseFloat($qty.val());
			if (!isFinite(qty) || qty <= 0) qty = 1;
			const $total = $calc.find('.dmc-calc-total-value');
			const variationInput = $productForm.find('input.variation_id, input[name=variation_id]').first();
			const variationRequired = $productForm.hasClass('variations_form') || variationInput.length > 0;
			const variationReady = !variationRequired || (parseInt(variationInput.val() || 0, 10) > 0);
			const unit = Math.round(totalBase * rate * 100) / 100;
			const nextText = variationReady ? formatPrice(unit * qty, money()) : '—';
			$total.toggleClass('is-pending', !variationReady);
			if ($total.text() !== nextText) {
				$total.text(nextText).removeClass('dmc-total-pulse');
				if ($total[0]) void $total[0].offsetWidth;
				$total.addClass('dmc-total-pulse');
			}
			$calc.attr('data-current-quantity', qty);
			const totalDetail = {
				productId: parseInt($calc.attr('data-product-id') || $productForm.find('[name=add-to-cart]').val() || 0, 10) || 0,
				base: totalBase, rate, quantity: qty, total: variationReady ? (unit * qty) : 0, formatted: nextText, ready: variationReady,
			};
			const formNode = $productForm.get(0);
			if (formNode) {
				try { formNode.dispatchEvent(new CustomEvent('dmc:total_updated', { bubbles: true, detail: totalDetail })); } catch (_) {}
				try { formNode.dispatchEvent(new CustomEvent('dmc:quantity_synced', { bubbles: true, detail: { quantity: qty, owner: 'woocommerce', source: 'dmc-calculator' } })); } catch (_) {}
			}
		}
		let recalcFrame = 0;
		function scheduleRecalc() {
			if (recalcFrame) return;
			recalcFrame = win.requestAnimationFrame(() => { recalcFrame = 0; recalc(); });
		}
		$calc.on('input change', '.dmc-input', scheduleRecalc);
		const $cartForm = $calc.closest('form.cart');
		$cartForm.addClass('dmc-calculator-ready');
		$cartForm.attr('data-dmc-quantity-owner', 'woocommerce');
		$cartForm.on('input change', 'input.qty', scheduleRecalc);
		$cartForm.on('click', '.plus,.minus,[data-quantity-action],.quantity button', () => { win.setTimeout(scheduleRecalc, 0); });

		function variationPlanName(variation) {
			if (variation && variation.variation_name) return String(variation.variation_name).trim();
			const id = variation && parseInt(variation.variation_id, 10);
			const $root = $calc.closest('form.variations_form');
			const $card = id ? $root.find('.ddsw-card[data-variation-id="' + id + '"]').first() : $();
			const cardName = $card.find('.ddsw-title').first().text().trim();
			if (cardName) return cardName;
			if (variation && variation.attributes) {
				const names = [];
				Object.keys(variation.attributes).forEach((key) => { const val = String(variation.attributes[key] || '').replace(/[-_]+/g, ' ').trim(); if (val) names.push(val); });
				if (names.length) return names.join(' · ');
			}
			const desc = cleanHtml(variation && variation.variation_description);
			return desc || 'Plan sélectionné';
		}
		function enhancePurchaseStudio() {
			const $card = $calc.find('.dmc-purchase-studio').first();
			if (!$card.length) return;
			const $formRoot = $calc.closest('form.cart');
			$formRoot.find('.dmc-purchase-subtotal, .dmc-studio-subtotal, .dmc-studio-trust').remove();
			$formRoot.find('.ddsw-trust-strip, .ddsw-selection-hero').each(function () {
				if (this.hidden && this.classList.contains('dmc-trust-source-hidden')) return;
				this.hidden = true;
				this.setAttribute('aria-hidden', 'true');
				this.classList.add('dmc-trust-source-hidden');
				this.style.setProperty('display', 'none', 'important');
			});
		}
		function setCardState(state) {
			const $card = $calc.find('.dmc-plan-total-card').first();
			if (!$card.length) return;
			$card.removeClass('is-empty is-checking is-selected is-unavailable').addClass(state);
		}
		function updateWooSummary(variation) {
			const $summary = $calc.find('[data-dmc-plan-summary]').first();
			if (!$summary.length) return;
			const name = variationPlanName(variation);
			const inStock = !variation || variation.is_in_stock !== false;
			const availability = cleanHtml(variation && variation.availability_html);
			const status = availability || (inStock ? 'Disponible' : 'Rupture de stock');
			setCardState(inStock ? 'is-selected' : 'is-unavailable');
			$summary.prop('hidden', false).removeAttr('hidden');
			$summary.find('[data-dmc-plan-name]').text(name);
			$summary.find('[data-dmc-plan-status]').text(status).removeClass('is-awaiting-selection').toggleClass('is-in-stock', inStock).toggleClass('is-out-of-stock', !inStock);
			const $card = $summary.closest('.dmc-plan-total-card');
			$card.removeClass('dmc-summary-updated');
			if ($card.length) { void $card[0].offsetWidth; $card.addClass('dmc-summary-updated'); }
		}
		function previewSelectedPlan(detail) {
			const $summary = $calc.find('[data-dmc-plan-summary]').first();
			if (!$summary.length || !detail) return;
			const name = String(detail.name || 'Plan sélectionné').trim();
			setCardState('is-checking');
			$summary.find('[data-dmc-plan-name]').text(name || 'Plan sélectionné');
			$summary.find('[data-dmc-plan-status]').text('Vérification…').removeClass('is-in-stock is-out-of-stock').addClass('is-awaiting-selection');
			$calc.find('.dmc-calc-total-value').text('—').addClass('is-pending');
		}
		function selectedCardDetail() {
			const $root = $calc.closest('form.variations_form');
			if (!$root.length) return null;
			const $card = $root.find('.ddsw-card.is-selected,[aria-checked="true"].ddsw-card,[aria-pressed="true"].ddsw-card').first();
			if (!$card.length) return null;
			return { variationId: parseInt($card.attr('data-variation-id') || 0, 10) || 0, name: String($card.attr('data-variation-name') || $card.find('.ddsw-title').first().text() || '').trim(), displayPrice: parseFloat($card.attr('data-display-price') || 0) || 0 };
		}
		function syncSelectionFallback() {
			const $summary = $calc.find('[data-dmc-plan-summary]').first();
			if (!$summary.length) return;
			const detail = selectedCardDetail();
			if (!detail) return;
			const $variationInput = $calc.closest('form.cart').find('input.variation_id, input[name=variation_id]').first();
			const ready = parseInt($variationInput.val() || 0, 10) > 0;
			if (ready) {
				setCardState('is-selected');
				$summary.find('[data-dmc-plan-name]').text(detail.name || 'Plan sélectionné');
				$summary.find('[data-dmc-plan-status]').text('Disponible').removeClass('is-awaiting-selection is-out-of-stock').addClass('is-in-stock');
			} else previewSelectedPlan(detail);
		}
		const $form = $calc.closest('form.variations_form');
		if ($form.length) {
			$form.on('dsb:plan_selected.dmcCalc', (e, detail) => { previewSelectedPlan(detail || (e.originalEvent && e.originalEvent.detail) || {}); });
			$form.on('click.dmcCalc', '.ddsw-card', function () {
				const card = this;
				win.setTimeout(() => { previewSelectedPlan({ variationId: parseInt(card.getAttribute('data-variation-id') || 0, 10) || 0, name: String(card.getAttribute('data-variation-name') || '').trim(), displayPrice: parseFloat(card.getAttribute('data-display-price') || 0) || 0 }); }, 0);
			});
			$form.on('found_variation.dmcCalc show_variation.dmcCalc', (e, variation) => {
				if (variation && variation.display_price != null) base = (parseFloat(variation.display_price) || 0) / (rate || 1);
				updateWooSummary(variation || {});
				recalc();
			});
			$form.on('reset_data.dmcCalc hide_variation.dmcCalc', () => {
				base = defaultBase;
				const $summary = $calc.find('[data-dmc-plan-summary]').first();
				setCardState('is-empty');
				$summary.find('[data-dmc-plan-name]').text('Choisissez un plan');
				$summary.find('[data-dmc-plan-status]').text('Sélection requise').removeClass('is-in-stock is-out-of-stock').addClass('is-awaiting-selection');
				win.setTimeout(syncSelectionFallback, 0);
				recalc();
			});
			$form.on('change.dmcCalc input.dmcCalc', 'select[name^="attribute_"], input[name="variation_id"], input.variation_id', () => { win.setTimeout(syncSelectionFallback, 0); });
		}
		enhancePurchaseStudio();
		recalc();
	}

	function bootCalculators() {
		stampHasFallback();
		$('.dmc-calc').each(function () { initCalc($(this)); });
	}
	bootCalculators();
	on(doc, 'dsb:content-updated', bootCalculators, { signal });
	$(doc.body).on('updated_wc_div.dmcCalc wc_fragments_loaded.dmcCalc', bootCalculators);

	/* Help modal */
	function closeHelp($modal) {
		if (!$modal || !$modal.length) return;
		$modal.removeClass('is-open').attr('aria-hidden', 'true');
		$modal.find('video').each(function () { try { this.pause(); } catch (_) {} });
		$('body').removeClass('dmc-modal-open');
		releaseOverlay('calc-help');
		const el = $modal.data('return-focus'); if (el && el.focus) el.focus();
	}
	$(doc).on('click.dmcCalc', '.dmc-help-trigger', function (e) {
		e.preventDefault(); e.stopPropagation();
		let data = {}; try { data = JSON.parse($(this).attr('data-help') || '{}'); } catch (_) {}
		let $modal = $(this).closest('form, .product, body').find('.dmc-help-modal').first();
		if (!$modal.length) $modal = $('.dmc-help-modal').first();
		if (!$modal.length) return;
		const $media = $modal.find('.dmc-help-media').empty(); let node = null;
		if (data.video) { node = doc.createElement('video'); node.controls = true; node.playsInline = true; node.preload = 'metadata'; node.src = String(data.video); }
		else if (data.image) { node = doc.createElement('img'); node.loading = 'eager'; node.decoding = 'async'; node.alt = ''; node.src = String(data.image); }
		if (node) $media.append(node);
		$media.toggle(!!node);
		$modal.find('.dmc-help-title').text(data.title || 'Aide');
		$modal.find('.dmc-help-copy').text(data.text || '').toggle(!!data.text);
		$modal.addClass('is-open').attr('aria-hidden', 'false').data('return-focus', this);
		$('body').addClass('dmc-modal-open');
		registerOverlay('calc-help');
		win.setTimeout(() => { $modal.find('.dmc-help-close').trigger('focus'); }, 20);
	});
	$(doc).on('click.dmcCalc', '.dmc-help-close,.dmc-help-backdrop', function () { closeHelp($(this).closest('.dmc-help-modal')); });
	on(doc, 'keydown', (e) => { if (e.key === 'Escape') closeHelp($('.dmc-help-modal.is-open').last()); }, { signal });

	/* Input transforms and custom validity messages */
	on(doc, 'input', (e) => {
		const el = e.target;
		if (!el || !el.classList || !el.classList.contains('dmc-input')) return;
		const mode = el.getAttribute('data-transform');
		if (!mode || mode === 'none') return;
		let value = el.value || '';
		if (mode === 'digits') value = value.replace(/\D+/g, ''); else if (mode === 'uppercase') value = value.toUpperCase(); else if (mode === 'lowercase') value = value.toLowerCase();
		if (el.value !== value) el.value = value;
	}, { signal });
	on(doc, 'invalid', (e) => { const el = e.target; if (!el || !el.getAttribute) return; const msg = el.getAttribute('data-error'); if (msg) el.setCustomValidity(msg); }, { capture: true, signal });
	on(doc, 'input', (e) => { if (e.target && e.target.setCustomValidity) e.target.setCustomValidity(''); }, { capture: true, signal });

	signal.addEventListener('abort', () => {
		try { $(doc).off('.dmcCalc'); $(doc.body).off('.dmcCalc'); } catch (_) {}
		closeHelp($('.dmc-help-modal.is-open'));
	}, { once: true });
}
