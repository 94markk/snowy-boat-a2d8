(function($){'use strict';
  var cache=new Map(),navSeq=0;
  var CACHE_LIMIT=4,CACHE_TTL=30000;
  function sameOrigin(url){try{return new URL(url,location.href).origin===location.origin}catch(e){return false}}
  function productNode(root){return root.querySelector('.delicat-native-product')||root.querySelector('div.product.type-product')||root.querySelector('.single-product div.product')}
  function currentNode(){return document.querySelector('.delicat-native-product')||document.querySelector('div.product.type-product')||document.querySelector('.single-product div.product')}
  function parseFragment(html){var t=document.createElement('template');t.innerHTML=String(html||'').trim();return t.content}
  function fetchPage(url){
    url=new URL(url,location.href).href;
    var entry=cache.get(url);
    if(entry&&Date.now()-entry.created<CACHE_TTL)return entry.promise;
    cache.delete(url);
    var requestUrl=new URL(url,location.href);requestUrl.searchParams.set('delicat_product_fragment','1');
    var controller=typeof AbortController==='function'?new AbortController():null;
    var timeout=0;
    var network=fetch(requestUrl.href,{credentials:'same-origin',headers:{'X-Delicat-Fragment':'product'},signal:controller?controller.signal:undefined})
      .then(function(r){if(!r.ok)throw new Error('HTTP '+r.status);return r.text()})
      .then(parseFragment);
    var deadline=new Promise(function(resolve,reject){timeout=setTimeout(function(){if(controller)controller.abort();reject(new Error('Product request timed out'))},8000)});
    var promise=Promise.race([network,deadline]).finally(function(){clearTimeout(timeout)});
    cache.set(url,{promise:promise,created:Date.now()});
    while(cache.size>CACHE_LIMIT)cache.delete(cache.keys().next().value);
    promise.catch(function(){var current=cache.get(url);if(current&&current.promise===promise)cache.delete(url)});
    return promise;
  }

  function syncBodyProductClass(next){var pid=parseInt(next&&next.getAttribute('data-product-id')||0,10);if(!pid)return;[].slice.call(document.body.classList).forEach(function(c){if(/^postid-\d+$/.test(c))document.body.classList.remove(c)});document.body.classList.add('postid-'+pid)}
  function reinitProduct(){
    try{document.querySelectorAll('form.variations_form').forEach(function(form){if($.fn.wc_variation_form&&!form.classList.contains('dpsr-wc-init')){$(form).wc_variation_form();form.classList.add('dpsr-wc-init')}})}catch(e){}
    try{document.dispatchEvent(new CustomEvent('dsb:content-updated',{detail:{source:'product-switcher'}}))}catch(e){}
    try{document.dispatchEvent(new CustomEvent('dpsr:product-changed',{detail:{url:location.href}}))}catch(e){}
    try{$(document.body).trigger('updated_wc_div')}catch(e){}
  }
  function setLoading(root,on){if(!root)return;root.classList.toggle('is-loading',!!on);root.setAttribute('aria-busy',on?'true':'false')}
  function markActive(root,url){if(!root)return;var target=new URL(url,location.href).href;root.querySelectorAll('.dpsr-switcher-option').forEach(function(a){var on=new URL(a.href,location.href).href===target;a.classList.toggle('is-active',on);a.setAttribute('aria-selected',on?'true':'false')})}
  function lowMotion(){return innerWidth<=820||document.documentElement.classList.contains('delicat-low-power')||(window.matchMedia&&matchMedia('(prefers-reduced-motion: reduce)').matches)}
  function swap(fragment,url,push,sourceRoot){
    var old=currentNode(),next=productNode(fragment);if(!old||!next)throw new Error('Product fragment missing');
    var imported=document.importNode(next,true),anim=lowMotion()?'none':(sourceRoot?sourceRoot.getAttribute('data-animation')||'fade':'fade');
    if(anim==='fade')imported.classList.add('dpsr-product-swap-fade');else if(anim==='slide')imported.classList.add('dpsr-product-swap-slide');
    syncBodyProductClass(imported);old.replaceWith(imported);
    var oldDock=document.querySelector('[data-dnp-dock]'),nextDock=fragment.querySelector('[data-dnp-dock]');
    if(nextDock){var importedDock=document.importNode(nextDock,true);if(oldDock)oldDock.replaceWith(importedDock);else imported.insertAdjacentElement('afterend',importedDock)}else if(oldDock){oldDock.remove();document.body.classList.remove('dnp-dock-visible','dnp-floating-ready')}
    var nativeTitle=imported.getAttribute('data-document-title');if(nativeTitle)document.title=nativeTitle;
    if(push&&sourceRoot&&sourceRoot.getAttribute('data-update-url')==='yes')history.pushState({dpsr:true,url:url},'',url);
    var newRoot=imported.querySelector('.dpsr-switcher');if(newRoot)markActive(newRoot,url);reinitProduct();return newRoot;
  }
  function navigate(url,push,root){
    if(!sameOrigin(url)){location.href=url;return}
    var seq=++navSeq;setLoading(root,true);
    return fetchPage(url).then(function(fragment){if(seq!==navSeq)return null;return swap(fragment,url,push,root)}).catch(function(){if(seq===navSeq)location.href=url;return null}).finally(function(){if(seq===navSeq)setLoading(root,false)})
  }
  function backgroundPrefetchAllowed(){
    if(document.hidden||innerWidth<=820)return false;
    var c=navigator.connection||navigator.mozConnection||navigator.webkitConnection;if(c&&(c.saveData||/^(slow-2g|2g|3g)$/.test(c.effectiveType||'')))return false;
    if(typeof navigator.deviceMemory==='number'&&navigator.deviceMemory<=4)return false;
    return true;
  }
  function prefetchRoot(root){
    if(!root||root.getAttribute('data-preload')!=='yes'||!backgroundPrefetchAllowed())return;
    var links=[].slice.call(root.querySelectorAll('.dpsr-switcher-option:not(.is-active)')).map(function(a){return a.href}).filter(sameOrigin).slice(0,4),i=0;
    function next(){if(i>=links.length||!root.isConnected||!backgroundPrefetchAllowed())return;fetchPage(links[i++]).catch(function(){}).finally(function(){window.setTimeout(next,220)})}
    if('requestIdleCallback'in window)requestIdleCallback(next,{timeout:1800});else window.setTimeout(next,1000)
  }
  function bindRoot(root){
    if(!root||root.getAttribute('data-dpsr-bound')==='1')return;root.setAttribute('data-dpsr-bound','1');
    root.addEventListener('click',function(e){var a=e.target.closest('.dpsr-switcher-option');if(!a||!root.contains(a)||a.classList.contains('is-active'))return;if(e.metaKey||e.ctrlKey||e.shiftKey||e.altKey)return;e.preventDefault();e.stopPropagation();navigate(a.href,true,root)});
    root.addEventListener('pointerenter',function(e){var a=e.target.closest&&e.target.closest('.dpsr-switcher-option');if(a&&backgroundPrefetchAllowed()&&sameOrigin(a.href))fetchPage(a.href).catch(function(){})},true);
    root.addEventListener('touchstart',function(e){var a=e.target.closest&&e.target.closest('.dpsr-switcher-option');if(a&&backgroundPrefetchAllowed()&&sameOrigin(a.href))fetchPage(a.href).catch(function(){})},{passive:true,capture:true});
    prefetchRoot(root)
  }
  function boot(){document.querySelectorAll('.dpsr-switcher').forEach(bindRoot)}
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot,{once:true});else boot();
  document.addEventListener('dsb:content-updated',boot);
  window.addEventListener('popstate',function(){var root=document.querySelector('.dpsr-switcher');if(root&&sameOrigin(location.href))navigate(location.href,false,root)})
})(jQuery);
