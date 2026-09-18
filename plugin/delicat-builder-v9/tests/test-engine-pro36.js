/* Node DOM-contract regressions, no live credentials/payments. */
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = process.env.DBV9_TEST_ROOT || path.resolve(__dirname, '..');
const read = p => fs.readFileSync(path.join(root,p),'utf8');
const tick = () => new Promise(resolve=>setImmediate(resolve));
function harness() {
  const requests = [], timers = new Map(); let id = 0;
  const h = {Promise, Date, Error, console, requests, timers,
    setTimeout(cb){timers.set(++id,cb);return id;}, clearTimeout(i){timers.delete(i);},
    fetch(){return new Promise(resolve=>requests.push(resolve));}};
  h.window = {fetch:h.fetch, setTimeout:h.setTimeout,clearTimeout:h.clearTimeout};
  return h;
}
async function legacyRace() {
  const s=read('assets/js/session.js'), h=harness();
  Object.assign(h,{store:{pending:null,data:null,ts:0},queuedRefresh:null,MIN_GAP:2500,cfg:{url:'/session'},apply(){}});
  vm.createContext(h);vm.runInContext(s.slice(s.indexOf('  function refresh('),s.indexOf('  function queueRefresh(')),h);
  const first=h.refresh('load',false), second=h.refresh('woo',true), third=h.refresh('event',true);
  assert.equal(h.requests.length,1,'Mutations must coalesce while request is active');
  h.requests[0]({ok:true,json:async()=>({loggedIn:false,cart:{count:0}})});
  await tick();
  assert.equal(h.requests.length,2,'Mutation during a read must trigger a follow-up read');
  h.requests[1]({ok:true,json:async()=>({loggedIn:false,cart:{count:2}})});
  const values=await Promise.all([first,second,third]);
  assert.equal(values[1].cart.count,2);assert.equal(values[2].cart.count,2);
  assert.equal(h.requests.length,2,'One follow-up for a burst');
}
function proHarness() {
  const h=harness(), wallet={textContent:'HTG 500'};
  h.document={addEventListener(){},dispatchEvent(){},documentElement:{setAttribute(){}},querySelectorAll(s){return s==='[data-dbp-wallet]'?[wallet]:[];}};
  h.window.DBPStateConfig={url:'/pro-state',initial:false};h.window.addEventListener=()=>{};
  h.CustomEvent=class {};
  vm.runInNewContext(read('pro/assets/dbp-state.js'),h);h.wallet=wallet;return h;
}
async function proRace() {
  const h=proHarness(),state=h.window.DBPState;
  const a=state.refresh('load'), b=state.refresh('cart'), c=state.refresh('manual');
  h.requests[0]({ok:true,json:async()=>({ok:true,cart:{count:1},wallet:{enabled:true,balance:'HTG 500'}})});
  await tick();assert.equal(h.requests.length,2,'Pro store must reread after an in-flight mutation');
  h.requests[1]({ok:true,json:async()=>({ok:true,cart:{count:0},wallet:{enabled:false}})});
  await Promise.all([a,b,c]);assert.equal(h.wallet.textContent,'','Disabled/logged-out wallet must clear old balance');
  assert.equal(state.get().cart.count,0);
}
async function timeoutWithoutAbort() {
  const h=proHarness();let settled=false;
  h.window.DBPState.refresh().then(()=>{settled=true;});
  [...h.timers.values()].forEach(cb=>cb());await tick();
  assert.ok(settled,'Timeout must release request even without AbortController');
  h.window.DBPState.refresh();assert.equal(h.requests.length,2,'Store must recover after a hung fetch');
}
async function navigation() {
  const s=read('pro/assets/dbp-nav.js');let scripts=0, anchors=0, hard=0;
  const h={Promise,navToken:2,indexExistingAssets(){},loadedInline:{},scriptIds:{},loadedScripts:{},normalise:x=>x,
    injectScript(){scripts++;return Promise.resolve();},runInline(){scripts++;return Promise.resolve();},
    doc:{getElementById:name=>name==='section 1'?{scrollIntoView(){anchors++;}}:null,getElementsByName:()=>[]}};
  vm.createContext(h);
  vm.runInContext(s.slice(s.indexOf('  function loadScripts('),s.indexOf('  function runInline(')),h);
  await h.loadScripts([{id:'next',src:'/test.js'}],[],null,1);
  assert.equal(scripts,0,'Superseded navigation must not run later scripts');
  await h.loadScripts([{id:'next',src:'/test.js'}],[],null,2);assert.equal(scripts,1);
  vm.runInContext(s.slice(s.indexOf('  function scrollToHash('),s.indexOf('  function announce(')),h);
  h.scrollToHash({hash:'#section%201'});h.scrollToHash({hash:'#%ZZ'});assert.equal(anchors,1);
  Object.assign(h,{commitInProgress:true,inflight:{abort(){}},hardNavigate(){hard++;},nativeProduct:()=>false});
  vm.runInContext(s.slice(s.indexOf('  function navigate('),s.indexOf('  /* ----------------------------------------------------------------- events */')),h);
  h.navigate({href:'/new'},{});assert.equal(hard,1,'Mid-commit tap must use a complete document');assert.equal(h.navToken,3);
}
function guestClearsIdentity() {
  const s=read('assets/js/session.js'), painted={}, code={textContent:'OLD-CODE',closest:()=>({removeAttribute(n){painted[n]='removed';}})};
  const h={store:{data:{loggedIn:false,wallet:{},urls:{}},listeners:[]},window:{__dbv9Reloaded:true},
    doc:{querySelectorAll:sel=>sel==='[data-dlx-code]'?[code]:[],dispatchEvent(){}},
    html:{classList:{toggle(){}}},renderedLoggedIn:()=>false,text:(sel,v)=>painted[sel]=v,CustomEvent:class {}};
  vm.createContext(h);vm.runInContext(s.slice(s.indexOf('  function apply()'),s.indexOf('  function refresh(')),h);h.apply();
  assert.equal(painted['[data-dlx-email]'],'');assert.equal(painted['[data-dlx-wallet-balance]'],'—');
  assert.equal(code.textContent,'');assert.equal(painted['data-dlx-copy'],'removed');
}
(async()=>{guestClearsIdentity();await legacyRace();await proRace();await timeoutWithoutAbort();await navigation();console.log('PASS: both session race/coalescing paths, wallet clearing, old-device timeout recovery, cancelled script queue, anchors, mid-commit navigation.');})().catch(e=>{console.error(e);process.exit(1);});
