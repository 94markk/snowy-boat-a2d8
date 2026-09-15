(function(){
  'use strict';
  if(window.__DIP_IDENTITY_LAZY_LOADER_V3__)return;
  window.__DIP_IDENTITY_LAZY_LOADER_V3__=true;
  var cfg=window.DIPIdentityLoader||{};
  var state={promise:null,ready:false};
  var selector='[data-dip-auth-open],[data-dl-open],.dsb-account-login,a[href="#delicat-login"],a[href="#dip-identity-modal"],[aria-controls="dip-identity-modal"]';

  function requestForm(data,timeout){
    var controller=typeof AbortController!=='undefined'?new AbortController():null,timer=null;
    if(controller)timer=window.setTimeout(function(){try{controller.abort()}catch(e){}},timeout||12000);
    return fetch(cfg.ajaxUrl||'/wp-admin/admin-ajax.php',{method:'POST',body:data,credentials:'same-origin',cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest'},signal:controller?controller.signal:undefined})
      .then(function(r){return r.text()})
      .then(function(raw){var j=null;try{j=JSON.parse(raw)}catch(e){}if(!j)throw new Error('invalid_response');return j})
      .finally(function(){if(timer)window.clearTimeout(timer)});
  }
  function freshModalNonce(){
    var data=new FormData();data.append('action',cfg.nonceAction||'dip_native_fresh_nonces_v2');
    return requestForm(data,10000).then(function(r){if(!r.success||!r.data||!r.data.modal)throw new Error('secure_session');return r.data.modal});
  }
  function fragment(nonce){
    var data=new FormData();data.append('action',cfg.action||'dip_native_modal_fragment_v1');data.append('nonce',nonce);data.append('redirect',cfg.fragmentRedirect||window.location.href);
    return requestForm(data,14000).then(function(r){if(!r.success||!r.data||!r.data.html)throw new Error((r.data&&r.data.code)||'fragment_failed');return r.data.html});
  }
  function css(url,id){
    if(!url)return Promise.resolve();if(document.getElementById(id))return Promise.resolve();
    return new Promise(function(resolve){var done=false,timer=null,l=document.createElement('link');function finish(){if(done)return;done=true;if(timer)window.clearTimeout(timer);resolve()}l.id=id;l.rel='stylesheet';l.href=url;l.onload=finish;l.onerror=finish;timer=window.setTimeout(finish,5000);document.head.appendChild(l)});
  }
  function script(url,id,required){
    if(!url)return Promise.resolve(false);if(document.getElementById(id))return Promise.resolve(true);
    return new Promise(function(resolve,reject){var s=document.createElement('script');s.id=id;s.src=url;s.async=false;s.setAttribute('data-cfasync','false');s.setAttribute('data-no-optimize','1');s.setAttribute('data-no-defer','1');s.setAttribute('data-no-delay','1');s.onload=function(){resolve(true)};s.onerror=function(){required===false?resolve(false):reject(new Error('asset_failed'))};(document.body||document.documentElement).appendChild(s)});
  }
  function vars(){if(!cfg.cssVars||document.getElementById('dip-identity-lazy-vars'))return;var s=document.createElement('style');s.id='dip-identity-lazy-vars';s.textContent=String(cfg.cssVars);document.head.appendChild(s)}
  function install(html){
    if(document.getElementById(cfg.modalId||'dip-identity-modal'))return;
    var wrap=document.createElement('div');wrap.innerHTML=String(html);var node=wrap.querySelector('#'+(cfg.modalId||'dip-identity-modal'));if(!node)throw new Error('modal_missing');document.body.appendChild(node);
  }
  function closeLegacyDrawer(){
    try{document.dispatchEvent(new CustomEvent('dsb:close-menu'))}catch(e){try{document.dispatchEvent(new Event('dsb:close-menu'))}catch(ignore){}}
  }
  function recovery(){
    var key='dip_identity_recovery_once';
    try{
      if(sessionStorage.getItem(key)==='1')return;
      sessionStorage.setItem(key,'1');
      var base=cfg.recoveryUrl||cfg.fragmentRedirect||window.location.href;
      var u=new URL(base,window.location.origin);
      if(u.origin!==window.location.origin)u=new URL('/',window.location.origin);
      u.searchParams.set('dip_identity_eager','1');
      u.searchParams.set('dip_identity_open','1');
      u.hash='dip-identity-modal';
      window.location.replace(u.toString());
    }catch(e){
      try{var current=new URL(window.location.href);current.searchParams.set('dip_identity_eager','1');current.hash='dip-identity-modal';window.location.replace(current.toString())}catch(ignore){}
    }
  }
  function apiOpen(tab,trigger){
    var api=window.DIPIdentityModalAPI;
    if(api&&typeof api.open==='function'){try{sessionStorage.removeItem('dip_identity_recovery_once')}catch(e){}api.open(tab==='register'?'register':'login',trigger||null);return true}
    return false;
  }
  function waitForApi(timeout){
    timeout=timeout||1400;
    return new Promise(function(resolve){var start=Date.now();(function tick(){if(window.DIPIdentityModalAPI&&typeof window.DIPIdentityModalAPI.open==='function'){resolve(true);return}if(Date.now()-start>=timeout){resolve(false);return}window.setTimeout(tick,40)})()});
  }
  function load(){
    if(state.ready)return Promise.resolve(true);if(state.promise)return state.promise;
    state.promise=freshModalNonce().then(fragment).then(function(html){
      vars();install(html);window.DIPIdentityModal=cfg.modalConfig||{};window.DIPPasskeys=cfg.passkeys||{};
      var a=cfg.assets||{},passkey=Number((cfg.passkeys||{}).available||0)===1;
      var styles=[css(a.loginCss,'dip-login-lazy'),css(a.modalCss,'dip-identity-modal-v3-lazy')];if(passkey)styles.push(css(a.passkeysCss,'dip-passkeys-lazy'));
      return Promise.all(styles).then(function(){return script(a.modalJs,'dip-identity-modal-v5-lazy',true)}).then(function(){
        // Passkeys are optional UI enhancement. A blocked/failed passkey asset
        // must never break password/Google login or send the customer elsewhere.
        if(passkey)script(a.passkeysJs,'dip-passkeys-lazy-js',false);
        return waitForApi(1600);
      }).then(function(ok){if(!ok)throw new Error('modal_api_missing');return true});
    }).then(function(){state.ready=true;return true}).catch(function(err){state.promise=null;throw err});
    return state.promise;
  }
  function preferred(target){return target&&(target.hasAttribute('data-dip-auth-register')||target.hasAttribute('data-dl-open-register'))?'register':'login'}
  function openFrom(target){
    closeLegacyDrawer();var tab=preferred(target);
    if(state.ready){if(!apiOpen(tab,target))recovery();return}
    load().then(function(){if(!apiOpen(tab,target))recovery()}).catch(recovery);
  }
  document.addEventListener('click',function(event){
    var target=event.target&&event.target.closest?event.target.closest(selector):null;if(!target)return;
    event.preventDefault();event.stopPropagation();if(event.stopImmediatePropagation)event.stopImmediatePropagation();openFrom(target);
  },true);
  document.addEventListener('dip:identity-open-request',function(event){
    closeLegacyDrawer();var d=(event&&event.detail)||{},tab=d.tab==='register'?'register':'login',trigger=d.trigger||null;
    if(state.ready){if(!apiOpen(tab,trigger))recovery();return}
    load().then(function(){if(!apiOpen(tab,trigger))recovery()}).catch(recovery);
  });
  function auto(){
    var search=window.location.search||'',hash=window.location.hash||'';
    if(hash==='#delicat-login'||hash==='#dip-identity-modal'||/(?:\?|&)dip_verified=1(?:&|$)/.test(search)||/(?:\?|&)dip_auth_error=/.test(search)||/(?:\?|&)dip_identity_open=1(?:&|$)/.test(search)){
      load().then(function(){if(!apiOpen('login',null))recovery()}).catch(recovery);
    }
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',auto,{once:true});else auto();
})();
