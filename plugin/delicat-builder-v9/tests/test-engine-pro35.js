/* Node-only DOM contract tests; run: node tests/test-engine-pro35.js */
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = process.env.DBV9_TEST_ROOT || path.resolve(__dirname, '..');
function checkout() {
  const source = fs.readFileSync(path.join(root, 'assets/js/purchase-native.js'), 'utf8');
  let changed = 0, callback, queued = null;
  function el(initial = {}) {
    const state = {className: '', disabled: false, innerHTML: '', hidden: false, ...initial};
    const e = {getAttribute: () => '', setAttribute: () => {}, addEventListener: () => {}};
    for (const key of Object.keys(state)) Object.defineProperty(e, key, {get: () => state[key], set(v) {state[key] = v; changed++; if(callback && key !== 'hidden') callback();}});
    return e;
  }
  const body = el({className: 'dpn-checkout'}), button = el(), total = el(), actual = el(), coupon = el();
  const dock = el(); dock.querySelector = s => s.includes('submit') ? button : total;
  const document = {body, querySelector(s) {if(s === '[data-dpn-checkout-dock]') return dock; if(s === '[data-dpn-coupon-open]') return coupon; if(s.startsWith('#place_order')) return actual; if(s.startsWith('.delicat-native-page-main')) return body; return null;}, querySelectorAll: () => [{innerHTML: 'HTG 500'}]};
  function Observer(cb) {callback = cb; this.observe = () => {};}
  const window = {MutationObserver: Observer, addEventListener: () => {}, setTimeout: cb => {queued = cb; return 1;}, clearTimeout: () => {queued = null;}};
  const helpers = source.slice(source.indexOf('  function addClass'), source.indexOf('  /*', source.indexOf('  function addClass')));
  const fn = source.slice(source.indexOf('\tfunction bindCheckoutPresentation()'), source.indexOf('  function emit('));
  vm.runInNewContext(helpers + fn + '\nbindCheckoutPresentation();', {document, window, MutationObserver: Observer, body, config: {}});
  let iterations = 0;
  while(queued && iterations++ < 15) {const next = queued; queued = null; next();}
  assert.ok(!queued, 'Checkout observer must settle, rather than rewriting identical DOM every 45ms');
  assert.equal(total.innerHTML, 'HTG 500');
  actual.disabled = true;
  while(queued && iterations++ < 30) {const next = queued; queued = null; next();}
  assert.equal(button.disabled, true, 'Dock must mirror WooCommerce button disabled state');
}
async function hearts(loggedIn) {
  const listeners = {}, buttons = [], events = [], timers = [];
  class Element {closest() {return this;} getAttribute(n) {return this.attrs[n] || '';} setAttribute(n,v) {this.attrs[n] = v;} querySelector() {return null;}}
  for(let id = 1; id <= 61; id++) {const b = new Element(); b.dataset = {productId: String(id)}; b.attrs = {'aria-pressed':'false'}; b.classList = {remove() {}, add() {}}; buttons.push(b);}
  const document = {cookie: '', readyState: 'complete', documentElement: {classList:{contains:()=>false}}, addEventListener(n, cb) {listeners[n] = cb;}, querySelectorAll(s) {if(s.includes('.is-pressed')) return []; const m = s.match(/data-product-id="(\d+)"/); return m ? buttons.filter(b=>b.dataset.productId===m[1]) : buttons;}, dispatchEvent(e) {events.push(e.detail); return true;}, querySelector:()=>null};
  const window = {DelicaBuilderV9: {heart: {enabled:true, guest:true, loggedIn, ajaxUrl:'/ajax', nonce:'test'}}, addEventListener(){}, setTimeout(cb) {timers.push(cb); return timers.length;}, clearTimeout(){}, location:{}};
  vm.runInNewContext(fs.readFileSync(path.join(root,'assets/js/heart.js'),'utf8'), {window, document, Element, location:{protocol:'https:'}, navigator:{}, URLSearchParams, fetch:async()=>({ok:false,status:500,json:async()=>({success:false})}), CustomEvent:class {constructor(n,o){this.detail=o.detail;}}});
  const click = b => listeners.click({target:b,preventDefault(){}});
  if(!loggedIn) {buttons.forEach(click); assert.equal(buttons[0].attrs['aria-pressed'],'false','Evicted guest favorite must also unselect its visible heart'); assert.equal(buttons[60].attrs['aria-pressed'],'true');}
  else {click(buttons[0]); timers.shift()(); await new Promise(resolve=>setImmediate(resolve)); assert.equal(buttons[0].attrs['aria-pressed'],'false'); assert.equal(events.at(-1).favorite,false,'Failed save must notify other UI of rollback');}
}
(async()=>{checkout(); await hearts(false); await hearts(true); console.log('PASS: checkout observer settles and follows Woo button; guest limit stays consistent; failed favorite emits rollback.');})().catch(e=>{console.error(e);process.exit(1);});
