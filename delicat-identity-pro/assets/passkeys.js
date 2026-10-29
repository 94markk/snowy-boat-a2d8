/* Delicat Identity — Passkeys / WebAuthn runtime (website, wp-login, Woo forms and the identity modal frame). */
(function(){
  'use strict';
  if(window.__DIP_PASSKEYS__)return;
  window.__DIP_PASSKEYS__=true;

  // The config is read lazily: optimizers (Rocket Loader, LiteSpeed) may run
  // this file before the inline DIPPasskeys config, and the modal frame
  // receives it from auth-shell.js.
  function conf(){return window.DIPPasskeys||{};}
  function ready(){return !!conf().rest;}
  function str(key,fallback){var s=conf().strings||{};return s[key]||fallback;}
  function noop(){}
  function qs(s,c){return (c||document).querySelector(s);}
  function qsa(s,c){return Array.prototype.slice.call((c||document).querySelectorAll(s));}
  function b64uToBuf(v){
    v=String(v||'').replace(/-/g,'+').replace(/_/g,'/');
    while(v.length%4)v+='=';
    var raw=atob(v),out=new Uint8Array(raw.length);
    for(var i=0;i<raw.length;i++)out[i]=raw.charCodeAt(i);
    return out.buffer;
  }
  function bufToB64u(buf){
    if(!buf)return '';
    var bytes=new Uint8Array(buf),binary='';
    for(var i=0;i<bytes.length;i+=0x8000){binary+=String.fromCharCode.apply(null,bytes.subarray(i,Math.min(i+0x8000,bytes.length)));}
    return btoa(binary).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'');
  }
  function supported(){return !!(window.PublicKeyCredential&&navigator.credentials&&navigator.credentials.create&&navigator.credentials.get);}
  function usable(){return supported()&&Number(conf().available||0)===1;}
  function own(message){var e=new Error(message);e.dipOwn=true;return e;}
  function timeoutMs(p){var t=Number(p&&p.timeout)||Number(conf().timeout)||120000;return Math.min(Math.max(t,30000),600000);}

  /** French, actionable text for every failure (browser DOMException names, REST errors, network). */
  function errorMessage(err,kind){
    if(!err)return str('network','Impossible de joindre le service Passkey. Réessayez.');
    if(err.dipOwn)return err.message;
    if(err.code==='rest_cookie_invalid_nonce')return str('stale','Cette page a expiré. Actualisez-la puis réessayez.');
    if(err.status&&err.code&&err.message)return err.message; // WP_Error messages are French.
    switch(err.name){
      case 'NotAllowedError':return kind==='login'?str('cancelledLogin','Vérification annulée, ou aucune Passkey de ce compte sur cet appareil.'):str('cancelled','Vérification Passkey annulée ou indisponible.');
      case 'InvalidStateError':return str('exists','Cet appareil possède déjà une Passkey pour ce compte.');
      case 'SecurityError':return str('origin','Les Passkeys fonctionnent uniquement sur l’adresse officielle du site.');
      case 'NotSupportedError':return str('unsupported','Ce navigateur ne prend pas en charge les Passkeys.');
      case 'AbortError':case 'TimeoutError':return str('expired','Vérification Passkey expirée. Réessayez.');
      case 'ConstraintError':return str('constraint','Activez le verrouillage de l’écran de cet appareil, puis réessayez.');
      case 'UnknownError':case 'NotReadableError':case 'OperationError':return str('device','Votre appareil n’a pas pu terminer la vérification. Réessayez, ou utilisez une autre méthode.');
    }
    if(err.status===429)return str('rateLimited','Trop de tentatives. Réessayez plus tard.');
    if(err.status===401||err.status===403)return str('blocked','Le service Passkey est momentanément inaccessible. Actualisez la page puis réessayez.');
    if(err.status)return str('server','Le service Passkey a rencontré une erreur. Réessayez dans un instant.');
    return str('network','Impossible de joindre le service Passkey. Réessayez.');
  }

  function statusEl(context){
    if(context&&context.querySelector){var el=context.querySelector('[data-dip-passkey-status]');if(el)return el;}
    return context===document?qs('[data-dip-passkey-status]'):null;
  }
  function setStatus(text,type,context){
    var el=statusEl(context);
    if(el){el.textContent=text||'';el.hidden=!text;el.className='dip-passkey-status'+(type?' is-'+type:'');}
    var modal=qs('#dip-identity-modal .dipx-message');
    if(modal&&context&&context.closest&&context.closest('#dip-identity-modal')){
      modal.textContent=text||'';modal.hidden=!text;modal.className='dipx-message '+(type==='success'?'is-success':'is-error');
    }
  }
  function setBusy(button,busy){if(!button)return;button.disabled=!!busy;button.classList.toggle('is-busy',!!busy);button.setAttribute('aria-busy',busy?'true':'false');}

  function request(path,method,body,customer){
    var c=conf(),headers={'Content-Type':'application/json','Accept':'application/json'};
    if(customer&&c.nonce)headers['X-WP-Nonce']=c.nonce;
    var controller=typeof AbortController!=='undefined'?new AbortController():null,timer;
    var deadline=new Promise(function(_,reject){timer=setTimeout(function(){if(controller)controller.abort();reject(own(str('slow','Le serveur met trop de temps à répondre. Réessayez.')));},15000);});
    var network=Promise.resolve().then(function(){return fetch(String(c.rest).replace(/\/$/,'')+path,{signal:controller?controller.signal:undefined,method:method||'POST',credentials:'same-origin',cache:'no-store',headers:headers,body:body===undefined?undefined:JSON.stringify(body)});}).then(function(r){
      return r.text().then(function(raw){var data={};try{data=raw?JSON.parse(raw):{};}catch(e){}if(!r.ok){var er=new Error((data&&data.message)||('HTTP '+r.status));er.status=r.status;er.code=data&&data.code;er.data=(data&&data.data)||{};throw er;}return data;});
    });
    return Promise.race([network,deadline]).finally(function(){clearTimeout(timer);});
  }
  // Browsers may leave a WebAuthn prompt pending beyond publicKey.timeout.
  // Bound it ourselves (timeout + 15 s) and ignore any credential arriving after the deadline.
  function credential(method,options){
    var controller=typeof AbortController!=='undefined'?new AbortController():null,timer;
    var args={publicKey:options};if(controller)args.signal=controller.signal;
    var deadline=new Promise(function(_,reject){timer=setTimeout(function(){if(controller)controller.abort();reject(own(str('expired','Vérification Passkey expirée. Réessayez.')));},timeoutMs(options)+15000);});
    var ceremony=Promise.resolve().then(function(){return navigator.credentials[method](args);});
    return Promise.race([ceremony,deadline]).finally(function(){clearTimeout(timer);});
  }
  function descriptors(list){return Array.isArray(list)?list.map(function(c){var x=Object.assign({},c);x.id=b64uToBuf(x.id);return x;}):list;}
  // Never mutates the server answer: a prefetched (autofill) answer can be reused by the button.
  function creationOptions(data){
    var p=Object.assign({},data.publicKey||{});
    p.challenge=b64uToBuf(p.challenge);
    if(p.user&&p.user.id)p.user=Object.assign({},p.user,{id:b64uToBuf(p.user.id)});
    if(p.excludeCredentials)p.excludeCredentials=descriptors(p.excludeCredentials);
    return p;
  }
  function requestOptions(data){
    var p=Object.assign({},data.publicKey||{});
    p.challenge=b64uToBuf(p.challenge);
    if(p.allowCredentials)p.allowCredentials=descriptors(p.allowCredentials);
    return p;
  }
  function serializeCreate(cred,ceremony){
    var transports=[],discoverable=null;
    try{if(cred.response&&typeof cred.response.getTransports==='function')transports=cred.response.getTransports()||[];}catch(e){}
    try{var ext=typeof cred.getClientExtensionResults==='function'?cred.getClientExtensionResults():{};if(ext&&ext.credProps&&typeof ext.credProps.rk==='boolean')discoverable=ext.credProps.rk;}catch(e){}
    var out={ceremony:ceremony,id:cred.id,rawId:bufToB64u(cred.rawId),clientDataJSON:bufToB64u(cred.response.clientDataJSON),attestationObject:bufToB64u(cred.response.attestationObject),transports:transports};
    if(discoverable!==null)out.discoverable=discoverable;
    return out;
  }
  function serializeGet(cred,ceremony){
    return {ceremony:ceremony,id:cred.id,rawId:bufToB64u(cred.rawId),clientDataJSON:bufToB64u(cred.response.clientDataJSON),authenticatorData:bufToB64u(cred.response.authenticatorData),signature:bufToB64u(cred.response.signature),userHandle:cred.response.userHandle?bufToB64u(cred.response.userHandle):''};
  }
  function ensureUsable(context){
    if(!ready()){setStatus(str('loading','Les Passkeys se chargent. Réessayez dans un instant.'),'error',context);return false;}
    if(usable())return true;
    setStatus(supported()?str('unsupported','Passkeys indisponibles.'):str('inApp','Pour utiliser une Passkey, ouvrez le site dans Safari ou Chrome.'),'error',context);return false;
  }

  // ------------------------------------------------------------------ Signal API (feature-detected)
  function signalAccepted(s){
    if(!s||!s.userId||!s.rpId||!window.PublicKeyCredential)return;
    try{if(typeof PublicKeyCredential.signalAllAcceptedCredentials==='function')PublicKeyCredential.signalAllAcceptedCredentials({rpId:s.rpId,userId:s.userId,allAcceptedCredentialIds:s.ids||[]}).catch(noop);}catch(e){}
    try{if(s.name&&typeof PublicKeyCredential.signalCurrentUserDetails==='function')PublicKeyCredential.signalCurrentUserDetails({rpId:s.rpId,userId:s.userId,name:s.name,displayName:s.displayName||s.name}).catch(noop);}catch(e){}
  }
  function signalUnknown(id){
    var rpId=conf().rpId;if(!id||!rpId||!window.PublicKeyCredential)return;
    try{if(typeof PublicKeyCredential.signalUnknownCredential==='function')PublicKeyCredential.signalUnknownCredential({rpId:rpId,credentialId:id}).catch(noop);}catch(e){}
  }

  // ------------------------------------------------------------------ Security card
  function deviceLabel(){
    var ua=navigator.userAgent||'',d='',b='';
    if(/iPhone/.test(ua))d='iPhone';else if(/iPad/.test(ua)||(/Macintosh/.test(ua)&&navigator.maxTouchPoints>1))d='iPad';
    else if(/Android/.test(ua))d=/Mobile/.test(ua)?'Android':'Tablette Android';else if(/Windows/.test(ua))d='Windows';
    else if(/CrOS/.test(ua))d='Chromebook';else if(/Macintosh|Mac OS X/.test(ua))d='Mac';else if(/Linux/.test(ua))d='Linux';
    if(/EdgA?\/|EdgiOS\//.test(ua))b='Edge';else if(/SamsungBrowser\//.test(ua))b='Samsung Internet';else if(/OPR\/|OPiOS\//.test(ua))b='Opera';
    else if(/Firefox\/|FxiOS\//.test(ua))b='Firefox';else if(/CriOS\/|Chrome\//.test(ua))b='Chrome';else if(/Safari\//.test(ua))b='Safari';
    return [d,b].filter(Boolean).join(' · ');
  }
  /** Where a card action reports: the passkeys card, or the passkey row of the identity (reauth) card. */
  function cardOf(node){
    if(!node||!node.closest)return document;
    return node.closest('[data-dip-passkeys-card]')||node.closest('.dip-reauth-passkey,[data-dip-reauth-card]')||node.parentNode||document;
  }
  function initCard(card){
    if(!card||card.__dipPasskeyCard)return;card.__dipPasskeyCard=true;
    var input=qs('[data-dip-passkey-label]',card);
    if(input&&input.value===input.getAttribute('data-dip-default-label')){var label=deviceLabel();if(label)input.value=label;}
    if(!supported()){
      qsa('[data-dip-passkey-register],[data-dip-passkey-reauth]',card).forEach(function(b){b.disabled=true;b.setAttribute('aria-disabled','true');});
      setStatus(str('inApp','Pour utiliser une Passkey, ouvrez le site dans Safari ou Chrome.'),'info',card);
      return;
    }
    var raw=card.getAttribute('data-dip-passkeys-signal');
    if(raw){try{signalAccepted(JSON.parse(raw));}catch(e){}}
  }
  /** Card actions answer with the refreshed card: swap it in place (no full page reload). */
  function refreshCard(card,data,message){
    if(card&&card!==document&&data&&typeof data.card==='string'&&data.card.indexOf('data-dip-passkeys-card')!==-1&&card.parentNode){
      var holder=document.createElement('div');holder.innerHTML=data.card;
      var next=holder.querySelector('[data-dip-passkeys-card]');
      if(next){
        card.parentNode.replaceChild(next,card);initCard(next);
        setStatus(message,'success',next);
        var focus=qs('[data-dip-passkey-register]:not([disabled])',next)||next;
        if(focus===next&&!next.hasAttribute('tabindex'))next.setAttribute('tabindex','-1');
        try{focus.focus({preventScroll:true});}catch(e){}
        return next;
      }
    }
    setStatus(message,'success',card);window.setTimeout(function(){window.location.reload();},650);
    return card;
  }
  function reauthCard(){return qs('[data-dip-reauth-card],#dip-reauth,#dip-reauth-card,.dip-reauth-card');}
  function actionButton(label,primary,onClick){
    var b=document.createElement('button');b.type='button';b.className='dip-btn'+(primary?' primary':'');b.textContent=label;
    b.addEventListener('click',function(e){e.preventDefault();e.stopPropagation();onClick(b);});return b;
  }
  /**
   * 428 (recent confirmation needed): an inline, actionable confirmation —
   * the account's passkey when it has one, otherwise the identity card of
   * the page (password, code, e-mail…) or the Security tab.
   */
  function showReauth(card,resume){
    var el=statusEl(card);if(!el){setStatus(str('reauthNeeded','Confirmez d’abord votre identité.'),'error',card);return;}
    el.textContent='';el.hidden=false;el.className='dip-passkey-status is-warning';
    var p=document.createElement('p');p.textContent=str('reauthNeeded','Pour votre sécurité, confirmez d’abord votre identité.');el.appendChild(p);
    var row=document.createElement('div');row.className='dip-passkey-status-actions';el.appendChild(row);
    var hasPasskey=Number(card.getAttribute&&card.getAttribute('data-dip-passkeys-count')||0)>0&&usable();
    if(hasPasskey)row.appendChild(actionButton(str('reauthPasskey','Confirmer avec une Passkey'),true,function(b){
      setBusy(b,true);
      confirmWithPasskey().then(function(){resume(el);}).catch(function(err){setBusy(b,false);p.textContent=errorMessage(err,'reauth');});
    }));
    var target=reauthCard(),label=hasPasskey?str('reauthOtherShort','Autre méthode'):str('reauthOther','Confirmer mon identité');
    if(target){
      row.appendChild(actionButton(label,!hasPasskey,function(){
        try{target.scrollIntoView({behavior:'smooth',block:'center'});}catch(e){target.scrollIntoView();}
        var f=qs('input:not([type="hidden"]),button,select,textarea,a[href]',target);
        window.setTimeout(function(){try{(f||target).focus({preventScroll:true});}catch(e){}},350);
      }));
    } else if(conf().securityUrl){
      var a=document.createElement('a');a.className='dip-btn'+(hasPasskey?'':' primary');a.textContent=label;a.href=conf().securityUrl;row.appendChild(a);
    }
    var first=qs('button,a',row);if(first){try{first.focus({preventScroll:true});}catch(e){}}
  }
  function confirmWithPasskey(){
    return request('/passkeys/reauth/options','POST',{},true).then(function(data){
      return credential('get',requestOptions(data)).then(function(cred){if(!cred)throw own(str('cancelled','Vérification Passkey annulée.'));return request('/passkeys/reauth/verify','POST',serializeGet(cred,data.ceremony),true);});
    });
  }

  function register(button){
    var card=cardOf(button);
    if(!ensureUsable(card))return;
    var label=qs('[data-dip-passkey-label]',card);setBusy(button,true);setStatus('','',card);
    request('/passkeys/register/options','POST',{label:label?label.value:''},true).then(function(data){
      return credential('create',creationOptions(data)).then(function(cred){if(!cred)throw own(str('cancelled','Passkey non créée.'));return request('/passkeys/register/verify','POST',serializeCreate(cred,data.ceremony),true);});
    }).then(function(data){
      // A server older than this script still asks for the second (proof) step.
      if(!data||!data.requiresProof)return data;
      return credential('get',requestOptions(data)).then(function(cred){if(!cred)throw own(str('cancelled','Vérification annulée.'));return request('/passkeys/register/proof','POST',serializeGet(cred,data.ceremony),true);});
    }).then(function(data){refreshCard(card,data,(data&&data.message)||'Passkey ajoutée.');}).catch(function(err){
      if(err&&err.status===428){showReauth(card,function(el){
        el.textContent='';el.className='dip-passkey-status is-success';
        var p=document.createElement('p');p.textContent=str('reauthDone','Identité confirmée.');el.appendChild(p);
        // A new tap keeps the WebAuthn prompt inside a user gesture (Safari).
        var row=document.createElement('div');row.className='dip-passkey-status-actions';el.appendChild(row);
        var go=actionButton(str('continueRegister','Ajouter la Passkey maintenant'),true,function(){register(button);});row.appendChild(go);
        try{go.focus({preventScroll:true});}catch(e){}
      });return;}
      setStatus(errorMessage(err,'register'),'error',card);
    }).finally(function(){setBusy(button,false);});
  }

  function reauth(button){
    var card=cardOf(button);if(!ensureUsable(card))return;setBusy(button,true);setStatus('','',card);
    confirmWithPasskey().then(function(data){setStatus((data&&data.message)||'Identité confirmée.','success',card);window.setTimeout(function(){window.location.reload();},600);}).catch(function(err){setStatus(errorMessage(err,'reauth'),'error',card);}).finally(function(){setBusy(button,false);});
  }

  function remove(button,confirmed){
    var card=cardOf(button),id=button.getAttribute('data-dip-passkey-remove')||'';
    if(!id||(!confirmed&&!window.confirm(str('removeConfirm','Supprimer cette Passkey ?'))))return;
    if(!ready()){setStatus(str('loading','Les Passkeys se chargent. Réessayez dans un instant.'),'error',card);return;}
    setBusy(button,true);setStatus('','',card);
    request('/passkeys/'+encodeURIComponent(id),'DELETE',{},true).then(function(data){
      refreshCard(card,data,(data&&data.message)||'Passkey supprimée.'); // the new card signals the remaining passkeys
    }).catch(function(err){
      if(err&&err.status===428){showReauth(card,function(){remove(button,true);});return;}
      setStatus(errorMessage(err,'remove'),'error',card);
    }).finally(function(){setBusy(button,false);});
  }

  // ------------------------------------------------------------------ Sign-in
  function loginRoot(button){return button.closest('#dip-identity-modal')||button.closest('form')||document;}
  function identifierInput(root){
    if(!root||root===document)return null;
    return qs('#dipx-login-email',root)||qs('input[name="log"]',root)||qs('#user_login',root)||qs('input[name="username"]',root)||qs('input[name="email"]',root);
  }
  function loginContext(root){
    var remember=root!==document?qs('input[name="remember"],input[name="rememberme"]',root):null;
    var target=root!==document?qs('input[name="redirect_to"],input[name="redirect"]',root):null;
    var page=window.location.origin+window.location.pathname+window.location.search;
    return {remember:!!(remember&&remember.checked),redirect:(target&&target.value)||page};
  }
  function busyRoot(root){return root.getAttribute&&(root.getAttribute('data-password-busy')==='1'||root.getAttribute('data-passkey-busy')==='1'||root.getAttribute('data-social-busy')==='1');}
  /** The storefront page hosting the identity modal frame (auth-shell.js), when this is that frame. */
  function modalHost(){
    if(typeof window.DIPAuthNavigate!=='function'||!qs('#dip-identity-modal'))return null;
    try{if(window.parent&&window.parent!==window&&window.parent.location.origin===window.location.origin)return window.parent;}catch(e){}
    return null;
  }
  /**
   * Leaves for the server destination. Inside the identity modal frame the
   * top page is navigated directly and the session change is written through
   * ITS storage: that page then receives no "storage" event (session-router.js
   * would reload it and cancel this navigation); other tabs still do.
   */
  function navigate(url,root){
    var win=modalHost()||window,dest;
    try{dest=new URL(url||conf().redirect||'/',win.location.href);}catch(e){dest=null;}
    if(!dest||dest.origin!==win.location.origin||!/^https?:$/.test(dest.protocol))dest=new URL('/',win.location.href);
    try{var sw=win.navigator.serviceWorker;if(sw&&sw.controller)sw.controller.postMessage({type:'dbv9-clear-docs'});}catch(e){}
    try{win.__DIP_AUTH_NAVIGATING__=true;}catch(e){}
    try{win.localStorage.setItem('dip-session-change',String(Date.now()));}catch(e){}
    win.location.assign(dest.href);
    window.setTimeout(function(){if(root&&root.removeAttribute)root.removeAttribute('data-passkey-busy');setStatus(str('loginSlow','Connexion réussie. Actualisez la page pour continuer.'),'success',root);},10000);
  }
  function finishLogin(root,cred,ceremony){
    var body=serializeGet(cred,ceremony);body.remember=loginContext(root).remember;
    return request('/passkeys/login/verify','POST',body,false).then(function(data){
      setStatus(str('loginSuccess','Connexion Passkey réussie.'),'success',root);
      navigate((data&&data.redirect)||conf().redirect||'/my-account/',root);
      return true;
    },function(err){
      // Only a discoverable assertion the site cannot place at all (no account, or not one of its passkeys).
      if(err&&err.data&&err.data.unknown_credential)signalUnknown(cred&&cred.id);
      throw err;
    });
  }

  function login(button){
    var root=loginRoot(button);if(!ensureUsable(root))return;
    if(busyRoot(root))return;
    var input=identifierInput(root),value=input?String(input.value||'').trim():'';
    if(value&&input.type==='email'&&input.validity&&!input.validity.valid){input.reportValidity();return;}
    var ctx=loginContext(root),auto=root.__dipAutofill||null,flow;
    if(root.setAttribute)root.setAttribute('data-passkey-busy','1');
    setBusy(button,true);setStatus('','',root);
    var prefetched=(!value&&auto&&auto.data&&Date.now()-auto.at<AUTOFILL_REFRESH_MS)?auto.data:null;
    if(auto)stopAutofill(auto);
    if(prefetched){
      // No e-mail typed: reuse the discoverable ceremony prepared for autofill,
      // so the prompt opens inside this tap (WebKit) without a round trip.
      flow=credential('get',requestOptions(prefetched)).then(function(cred){if(!cred)throw own(str('cancelled','Passkey non vérifiée.'));return finishLogin(root,cred,prefetched.ceremony);});
    } else {
      // With an e-mail: this account's passkeys; without: any passkey of this site (discoverable).
      flow=request('/passkeys/login/options','POST',{identifier:value,remember:ctx.remember,redirect:ctx.redirect},false).then(function(data){
        return credential('get',requestOptions(data)).then(function(cred){if(!cred)throw own(str('cancelled','Passkey non vérifiée.'));return finishLogin(root,cred,data.ceremony);});
      });
    }
    flow.then(function(){/* navigating: stay busy */}).catch(function(err){
      setStatus(errorMessage(err,'login'),'error',root);
      if(root.removeAttribute)root.removeAttribute('data-passkey-busy');
      if(auto)wakeAutofill(auto);
    }).finally(function(){setBusy(button,false);});
  }

  // ------------------------------------------------------------------ Passkey autofill (conditional mediation)
  // The ceremony lives CEREMONY_TTL (300 s) on the server: refresh before it expires.
  var AUTOFILL_REFRESH_MS=240000,AUTOFILL_MAX_REFRESH=5;
  function visible(el){return !!(el&&el.isConnected&&el.getClientRects().length);}
  function stopAutofill(state){
    clearTimeout(state.timer);state.timer=0;state.data=null;
    if(state.controller){var c=state.controller;state.controller=null;try{c.abort();}catch(e){}}
  }
  function wakeAutofill(state){
    if(state.submitted||document.hidden||!visible(state.input)||busyRoot(state.root))return;
    if(state.controller&&state.data&&Date.now()-state.at<AUTOFILL_REFRESH_MS)return;
    if(state.controller&&!state.data)return; // options request in flight
    stopAutofill(state);startAutofill(state);
  }
  function startAutofill(state){
    if(typeof AbortController==='undefined')return;
    var controller=new AbortController();state.controller=controller;state.data=null;
    var ctx=loginContext(state.root);
    request('/passkeys/login/options','POST',{identifier:'',remember:ctx.remember,redirect:ctx.redirect},false).then(function(data){
      if(state.controller!==controller)return;
      state.data=data;state.at=Date.now();
      // Fresh ceremony before the server one expires; an untouched page stops after a few
      // refreshes (each stores a ceremony) and starts again on focus.
      state.timer=setTimeout(function(){state.timer=0;if(state.controller!==controller)return;stopAutofill(state);if(++state.refreshes<=AUTOFILL_MAX_REFRESH)wakeAutofill(state);},AUTOFILL_REFRESH_MS);
      return navigator.credentials.get({mediation:'conditional',signal:controller.signal,publicKey:requestOptions(data)}).then(function(cred){
        if(state.controller!==controller||!cred)return;
        // The customer picked a passkey in the autofill list.
        var used=state.data;stopAutofill(state);
        if(busyRoot(state.root))return;
        state.root.setAttribute('data-passkey-busy','1');setBusy(state.button,true);setStatus('','',state.root);
        return finishLogin(state.root,cred,used.ceremony).catch(function(err){
          setStatus(errorMessage(err,'login'),'error',state.root);state.root.removeAttribute('data-passkey-busy');wakeAutofill(state);
        }).finally(function(){setBusy(state.button,false);});
      });
    }).catch(function(){
      // Aborted (button, password, refresh), unsupported or offline: autofill simply stays off.
      if(state.controller===controller){clearTimeout(state.timer);state.timer=0;state.controller=null;state.data=null;}
    });
  }
  function addWebauthnToken(input){
    var tokens=String(input.getAttribute('autocomplete')||'').trim().split(/\s+/).filter(Boolean);
    if(tokens.indexOf('webauthn')!==-1)return;
    if(!tokens.length||tokens[0]==='on'||tokens[0]==='off')tokens=['username'];
    tokens.push('webauthn');input.setAttribute('autocomplete',tokens.join(' '));
  }
  function initAutofill(){
    var c=conf();
    if(!ready()||!usable()||Number(c.loggedIn||0)===1||Number(c.autofill===undefined?1:c.autofill)!==1)return;
    if(typeof PublicKeyCredential.isConditionalMediationAvailable!=='function'||typeof AbortController==='undefined')return;
    Promise.resolve().then(function(){return PublicKeyCredential.isConditionalMediationAvailable();}).then(function(ok){
      if(!ok)return;
      qsa('[data-dip-passkey-login]').forEach(function(button){
        var root=loginRoot(button),input=identifierInput(root);
        if(!input||root.__dipAutofill||button.hidden)return;
        var state={root:root,input:input,button:button,controller:null,data:null,at:0,timer:0,refreshes:0,submitted:false};root.__dipAutofill=state;
        addWebauthnToken(input);
        var form=input.form||(root.querySelector&&root.querySelector('form'));
        // A password sign-in cancels the pending autofill request; it stays off while that
        // sign-in runs and comes back when the customer edits the form again (failed attempt).
        if(form){
          form.addEventListener('submit',function(){state.submitted=true;stopAutofill(state);},true);
          form.addEventListener('input',function(){if(state.submitted){state.submitted=false;wakeAutofill(state);}},true);
        }
        input.addEventListener('focus',function(){state.refreshes=0;wakeAutofill(state);});
        if(typeof IntersectionObserver!=='undefined'){new IntersectionObserver(function(entries){if(entries.some(function(e){return e.isIntersecting;}))wakeAutofill(state);}).observe(input);}
        else wakeAutofill(state);
      });
      function wakeAll(){qsa('[data-dip-passkey-login]').forEach(function(b){var s=loginRoot(b).__dipAutofill;if(s)wakeAutofill(s);});}
      document.addEventListener('visibilitychange',function(){if(!document.hidden)wakeAll();});
      // Back/forward cache: the page comes back with the old (consumed or expired) ceremony.
      window.addEventListener('pageshow',function(e){if(!e.persisted)return;qsa('[data-dip-passkey-login]').forEach(function(b){var s=loginRoot(b).__dipAutofill;if(s){s.submitted=false;stopAutofill(s);}});wakeAll();});
    }).catch(noop);
  }

  // ------------------------------------------------------------------ Wiring
  document.addEventListener('click',function(e){
    var b=e.target.closest&&e.target.closest('[data-dip-passkey-register],[data-dip-passkey-reauth],[data-dip-passkey-remove],[data-dip-passkey-login]');
    if(!b)return;e.preventDefault();if(b.disabled)return;
    if(!initialized)init();
    if(b.hasAttribute('data-dip-passkey-register'))register(b);
    else if(b.hasAttribute('data-dip-passkey-reauth'))reauth(b);
    else if(b.hasAttribute('data-dip-passkey-remove'))remove(b,false);
    else login(b);
  });

  var initialized=false,initTries=0,initTimer=0;
  function init(){
    if(initialized)return;
    // Config still missing (deferred by an optimizer): leave the buttons alone and retry
    // a few times (a click also retries).
    if(!ready()){if(!initTimer&&initTries<8){initTries++;initTimer=setTimeout(function(){initTimer=0;init();},400*initTries);}return;}
    initialized=true;clearTimeout(initTimer);initTimer=0;
    qsa('[data-dip-passkeys-card]').forEach(initCard);
    if(!usable()){
      qsa('[data-dip-passkey-register],[data-dip-passkey-reauth],[data-dip-passkey-login]').forEach(function(b){b.disabled=true;b.setAttribute('aria-disabled','true');});
      if(!supported())qsa('[data-dip-passkey-login]').forEach(function(b){
        b.hidden=true;var wrap=b.closest('.dip-passkey-standard');var st=wrap&&qs('[data-dip-passkey-status]',wrap);
        if(st){st.textContent=str('inApp','Pour utiliser une Passkey, ouvrez le site dans Safari ou Chrome.');st.hidden=false;st.className='dip-passkey-status is-info';}
      });
      return;
    }
    initAutofill();
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
  window.addEventListener('load',init,{once:true});
})();
