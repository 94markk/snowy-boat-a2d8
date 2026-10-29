(function () {
  'use strict';
  if (window.DIPAuthShell) return;
  var cfg = window.DIPIdentityModal || {}, shell, frame, loading, source, ready = false;
  var opened = false, selected = 'login', focusBefore, blocked = [], observer, assetTimer, loadId=0, scrollLock=null, nativeLayer=false, sizeFrame=0, contentHeight=600;
  var selector = '[data-dip-auth-open],[data-dl-open],.dsb-account-login,a[href="#delicat-login"],a[href="#dip-identity-modal"],[aria-controls="dip-identity-modal"]';
  function fit() {
    sizeFrame=0;
    if(!opened||!shell)return;
    var view=window.visualViewport, height=view?view.height:window.innerHeight;
    var available=Math.max(120,height-24), actual=Math.min(contentHeight,available);
    shell.style.setProperty('height',Math.ceil(actual)+'px','important');
    shell.style.setProperty('top',Math.round((view?view.offsetTop:0)+(height-actual)/2)+'px','important');
    shell.style.setProperty('bottom','auto','important');
  }
  function queueFit() {
    if(opened&&!sizeFrame)sizeFrame=window.requestAnimationFrame(fit);
  }
  function sizeTo(height) {
    if(typeof height!=='number'||!isFinite(height)||height<100||height>5000)return;
    var next=Math.ceil(height);
    if(next===contentHeight)return;
    contentHeight=next;queueFit();
  }
  window.addEventListener('resize',queueFit);
  if(window.visualViewport){window.visualViewport.addEventListener('resize',queueFit);window.visualViewport.addEventListener('scroll',queueFit);}
  function restore() {
    if(sizeFrame)window.cancelAnimationFrame(sizeFrame);sizeFrame=0;nativeLayer=false;
    if(scrollLock){document.documentElement.style.setProperty('overflow',scrollLock.value,scrollLock.priority);if(!scrollLock.value)document.documentElement.style.removeProperty('overflow');scrollLock=null;}
    if (observer) observer.disconnect(); observer = null;
    blocked.forEach(function (item) {
      if (!item.inert) item.node.removeAttribute('inert');
      item.node.style.setProperty('pointer-events', item.pointer, item.priority);
      if (!item.pointer) item.node.style.removeProperty('pointer-events');
    }); blocked = [];
  }
  function isolate(node) {
    if (!node || node.nodeType !== 1 || node === shell || /^(SCRIPT|STYLE|LINK)$/.test(node.tagName)) return;
    if (blocked.some(function (v) { return v.node === node; })) return;
    blocked.push({node:node,inert:node.hasAttribute('inert'),pointer:node.style.getPropertyValue('pointer-events'),priority:node.style.getPropertyPriority('pointer-events')});
    node.setAttribute('inert', ''); node.style.setProperty('pointer-events','none','important');
  }
  function error() {
    clearTimeout(assetTimer); assetTimer=null; ready = false;
    loading.textContent = 'Chargement impossible. Fermez puis réessayez.';
    loading.hidden = false; frame.hidden = true;
  }
  function loadFrame() {
    var attempt=++loadId;
    clearTimeout(assetTimer); assetTimer=null;
    ready = false; loading.textContent='Chargement de la connexion…'; loading.hidden = false; frame.hidden = true;
    var doc = frame.contentDocument;
    if (!doc) { error(); return; }
    doc.open(); doc.write('<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body></body></html>'); doc.close();
    var root = doc.createElement('main'); root.id = 'dip-identity-modal'; root.className = 'dipx-root'; root.innerHTML = source;
    doc.body.appendChild(root);
    frame.contentWindow.DIPIdentityModal = cfg;
    frame.contentWindow.DIPPasskeys = cfg.passkeys || window.DIPPasskeys || {};
    var script = doc.createElement('script'); script.src = cfg.frameJsUrl;
    script.onload = function () {
      if(attempt!==loadId)return;
      clearTimeout(assetTimer); assetTimer=null;
      if (!frame.contentWindow.DIPAuthFrame) { error(); return; }
      ready = true; loading.hidden = true; frame.hidden = false;
      if (opened) frame.contentWindow.DIPAuthFrame.open(selected);
      if (Number((frame.contentWindow.DIPPasskeys || {}).available) === 1 && cfg.passkeysJsUrl) {
        var pk = doc.createElement('script'); pk.src = cfg.passkeysJsUrl;
        var pkTimer=setTimeout(function(){var b=doc.querySelector('[data-dip-passkey-login]');if(b)b.hidden=true;},10000);
        pk.onload=function(){clearTimeout(pkTimer);var b=doc.querySelector('[data-dip-passkey-login]');if(b)b.removeAttribute('data-dip-passkey-loading');if(b&&!b.hasAttribute('aria-disabled'))b.disabled=doc.getElementById('dip-identity-modal').getAttribute('data-password-busy')==='1';};
        pk.onerror = function () {clearTimeout(pkTimer);var b = doc.querySelector('[data-dip-passkey-login]'); if (b) b.hidden = true;}; doc.body.appendChild(pk);
      }
    };
    script.onerror = function(){if(attempt===loadId)error();};
    assetTimer = setTimeout(function(){if(attempt===loadId)error();}, 12000); doc.body.appendChild(script);
  }
  // The dialog, its frame and the frame scripts are built on the first sign of
  // intent (pointer/touch/hover/focus on a trigger) or on opening, no longer
  // on every page view: most visitors never open the modal.
  var intents=['pointerdown','touchstart','mouseover','focusin'];
  function prepare(e) { var t=e.target; if (t && t.closest && t.closest(selector)) build(); }
  function stopPreparing() { intents.forEach(function (name) { document.removeEventListener(name, prepare, true); }); }
  function build() {
    if (shell && shell.isConnected) return true;
    if(shell){restore();shell=null;frame=null;opened=false;ready=false;}
    var old = document.getElementById('dip-identity-modal'); if (!old) return false;
    stopPreparing();
    source = old.innerHTML; old.remove();
    shell = document.createElement('dialog'); shell.id = 'dip-auth-shell'; shell.setAttribute('aria-label','Connexion Delicat Store');
    shell.setAttribute('data-dip-engine','6.10.1');
    var style = document.createElement('style');
    style.textContent = "#dip-auth-shell{position:fixed!important;inset:0!important;margin:0 auto!important;padding:0!important;border:1px solid #e6e9f2!important;border-radius:22px!important;width:min(420px,calc(100vw - 24px))!important;height:min(600px,calc(100vh - 24px))!important;max-width:none!important;max-height:none!important;background:white!important;color:#171b32!important;overflow:hidden!important;transform:none!important;filter:none!important;transition:none!important;opacity:1!important;visibility:visible!important;pointer-events:auto!important;z-index:2147483647!important;box-shadow:0 12px 32px #10182c26!important;box-sizing:border-box!important}#dip-auth-shell:not([open]){display:none!important}#dip-auth-shell[open]{display:block!important}#dip-auth-shell::backdrop{background:rgba(12,18,39,.58)!important;backdrop-filter:none!important}#dip-auth-shell iframe{display:block!important;width:100%!important;height:100%!important;min-height:0!important;margin:0!important;padding:0!important;border:0!important;transform:none!important;background:white!important}#dip-auth-shell [hidden]{display:none!important}#dip-auth-shell>.dip-shell-close{position:absolute!important;right:10px!important;top:10px!important;z-index:2!important;width:36px!important;height:36px!important;min-width:36px!important;min-height:36px!important;max-width:36px!important;padding:0!important;border:1px solid #e6e9f2!important;border-radius:50%!important;background:#f7f8fc!important;color:#47506a!important;font-size:0!important;box-shadow:none!important;transform:none!important;cursor:pointer!important;touch-action:manipulation!important}#dip-auth-shell>.dip-shell-close:before,#dip-auth-shell>.dip-shell-close:after{content:\"\"!important;position:absolute!important;left:10px!important;top:16px!important;width:14px!important;height:2px!important;background:currentColor!important;transform:rotate(45deg)!important}#dip-auth-shell>.dip-shell-close:after{transform:rotate(-45deg)!important}#dip-auth-shell>.dip-shell-loading{padding:68px 22px!important;font:14px system-ui!important}";
    document.head.appendChild(style);
    var close = document.createElement('button'); close.type='button';close.className='dip-shell-close';close.textContent='×';close.setAttribute('aria-label','Fermer');
    close.addEventListener('click', closeModal); shell.appendChild(close);
    loading = document.createElement('div');loading.className='dip-shell-loading';loading.setAttribute('role','status');loading.textContent='Chargement de la connexion…';shell.appendChild(loading);
    frame = document.createElement('iframe');frame.title='Connexion et inscription Delicat Store';shell.appendChild(frame);
    document.body.appendChild(shell);
    shell.addEventListener('cancel', function (e) { e.preventDefault(); closeModal(); });
    // Closed by the browser itself (e.g. repeated Escape): finish the same way.
    shell.addEventListener('close', function () { if (!opened) return; if (ready) frame.contentWindow.DIPAuthFrame.close(); finishClose(); });
    loadFrame(); return true;
  }
  function open(tab, trigger) {
    if (!build()) return;
    selected = tab === 'register' ? 'register' : 'login';
    if (opened) return;
    focusBefore = trigger || document.activeElement;
    try { document.dispatchEvent(new CustomEvent('dsb:close-menu')); } catch (e) {}
    opened = true;
    scrollLock={value:document.documentElement.style.getPropertyValue('overflow'),priority:document.documentElement.style.getPropertyPriority('overflow')};
    document.documentElement.style.setProperty('overflow','hidden','important');
    contentHeight=selected==='register'?660:580;
    fit();
    nativeLayer=false;
    if (typeof shell.showModal === 'function') { try { shell.showModal(); nativeLayer=true; } catch(e) { shell.setAttribute('open',''); } }
    else shell.setAttribute('open','');
    // Native dialogs already make the entire background inert. Avoid rewriting
    // every body child and observing live storefront widgets on each opening.
    if(!nativeLayer){
      Array.prototype.forEach.call(document.body.children,isolate);
      if(typeof MutationObserver!=='undefined'){
        observer=new MutationObserver(function(records){records.forEach(function(r){Array.prototype.forEach.call(r.addedNodes,isolate);});});
        observer.observe(document.body,{childList:true});
      }
    }
    if (ready) frame.contentWindow.DIPAuthFrame.open(selected);
    else if (!assetTimer) loadFrame();
  }
  function closeModal() {
    if (!opened) return;
    if (ready && !frame.contentWindow.DIPAuthFrame.close()) return;
    finishClose();
  }
  function finishClose() {
    opened=false; restore();
    if (typeof shell.close==='function') { if (shell.open) shell.close(); } else shell.removeAttribute('open');
    if (focusBefore && focusBefore.focus) { try { focusBefore.focus({preventScroll:true}); } catch(e) {} }
    // The opening hash and one-shot states (error, verified) would reopen the
    // modal with a stale message on reload or when the address is shared.
    try {
      var url=new URL(location.href), changed=false;
      ['dip_auth_error','dip_verified','dip_identity_open','dip_identity_eager'].forEach(function (k) { if (url.searchParams.has(k)) { url.searchParams.delete(k); changed=true; } });
      if (/^#(delicat-login|dip-identity-modal)$/.test(url.hash)) { url.hash=''; changed=true; }
      if (changed) history.replaceState(history.state,'',url.pathname+url.search+url.hash);
    } catch(e) {}
  }
  window.DIPAuthShell = true;
  window.DIPIdentityModalAPI = {open:open,close:closeModal,isOpen:function(){return opened;},resize:sizeTo};
  document.addEventListener('click',function(e){
    var trigger=e.target.closest && e.target.closest(selector); if(!trigger)return;
    e.preventDefault();e.stopImmediatePropagation();
    open(trigger.hasAttribute('data-dip-auth-register')||trigger.hasAttribute('data-dl-open-register')?'register':'login',trigger);
  },true);
  ['dip:identity-open','dip:identity-open-request'].forEach(function(name){document.addEventListener(name,function(e){var d=e.detail||{};open(d.tab,d.trigger);});});
  function boot() {
    if (!document.getElementById('dip-identity-modal')) return;
    var p=new URLSearchParams(location.search);
    if (/^#(delicat-login|dip-identity-modal)$/.test(location.hash)||p.has('dip_auth_error')||p.get('dip_verified')==='1'||p.get('dip_identity_open')==='1') open('login');
    else intents.forEach(function (name) { document.addEventListener(name, prepare, {capture:true, passive:true}); });
    document.dispatchEvent(new CustomEvent('dip:identity-ready',{detail:{modalId:'dip-auth-shell'}}));
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot,{once:true});else boot();
})();
