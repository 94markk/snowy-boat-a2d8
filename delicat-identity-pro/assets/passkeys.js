(function(){
  'use strict';
  if(window.__DIP_PASSKEYS__)return;
  window.__DIP_PASSKEYS__=true;
  var cfg=window.DIPPasskeys||{};
  if(!cfg.rest)return;

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
  function errorMessage(err){
    if(err&&err.name==='NotAllowedError')return (cfg.strings&&cfg.strings.cancelled)||'Vérification Passkey annulée.';
    if(err&&err.message)return err.message;
    return (cfg.strings&&cfg.strings.network)||'Erreur Passkey.';
  }
  function setStatus(text,type,context){
    var el=context&&context.querySelector?context.querySelector('[data-dip-passkey-status]'):null;
    if(!el)el=qs('[data-dip-passkey-status]');
    if(el){el.textContent=text||'';el.hidden=!text;el.className='dip-passkey-status'+(type?' is-'+type:'');}
    var modal=qs('#dip-identity-modal .dipx-message');
    if(modal&&context&&context.closest&&context.closest('#dip-identity-modal')){
      modal.textContent=text||'';modal.hidden=!text;modal.className='dipx-message '+(type==='success'?'is-success':'is-error');
    }
  }
  function setBusy(button,busy){if(!button)return;button.disabled=!!busy;button.classList.toggle('is-busy',!!busy);button.setAttribute('aria-busy',busy?'true':'false');}
  function request(path,method,body,customer){
    var headers={'Content-Type':'application/json','Accept':'application/json'};
    if(customer&&cfg.nonce)headers['X-WP-Nonce']=cfg.nonce;
    var controller=typeof AbortController!=='undefined'?new AbortController():null;
    var timer;
    var deadline=new Promise(function(_,reject){timer=setTimeout(function(){if(controller)controller.abort();reject(new Error('Le serveur met trop de temps à répondre. Réessayez.'));},15000);});
    var network=fetch(String(cfg.rest).replace(/\/$/,'')+path,{signal:controller?controller.signal:undefined,method:method||'POST',credentials:'same-origin',cache:'no-store',headers:headers,body:body===undefined?undefined:JSON.stringify(body)}).then(function(r){
      return r.text().then(function(raw){var data={};try{data=raw?JSON.parse(raw):{};}catch(e){}if(!r.ok){var er=new Error((data&&data.message)||('HTTP '+r.status));er.status=r.status;er.code=data&&data.code;throw er;}return data;});
    });
    return Promise.race([network,deadline]).finally(function(){clearTimeout(timer);});
  }
  function creationOptions(data){
    var p=data.publicKey||{};
    p.challenge=b64uToBuf(p.challenge);
    if(p.user&&p.user.id)p.user.id=b64uToBuf(p.user.id);
    if(Array.isArray(p.excludeCredentials))p.excludeCredentials=p.excludeCredentials.map(function(c){var x=Object.assign({},c);x.id=b64uToBuf(x.id);return x;});
    return p;
  }
  function requestOptions(data){
    var p=data.publicKey||{};
    p.challenge=b64uToBuf(p.challenge);
    if(Array.isArray(p.allowCredentials))p.allowCredentials=p.allowCredentials.map(function(c){var x=Object.assign({},c);x.id=b64uToBuf(x.id);return x;});
    return p;
  }
  function serializeCreate(cred,ceremony){
    var transports=[];
    try{if(cred.response&&typeof cred.response.getTransports==='function')transports=cred.response.getTransports()||[];}catch(e){}
    return {ceremony:ceremony,id:cred.id,rawId:bufToB64u(cred.rawId),clientDataJSON:bufToB64u(cred.response.clientDataJSON),attestationObject:bufToB64u(cred.response.attestationObject),transports:transports};
  }
  function serializeGet(cred,ceremony){
    return {ceremony:ceremony,id:cred.id,rawId:bufToB64u(cred.rawId),clientDataJSON:bufToB64u(cred.response.clientDataJSON),authenticatorData:bufToB64u(cred.response.authenticatorData),signature:bufToB64u(cred.response.signature),userHandle:cred.response.userHandle?bufToB64u(cred.response.userHandle):''};
  }
  function ensureSupported(context){if(supported()&&Number(cfg.available||0))return true;setStatus((cfg.strings&&cfg.strings.unsupported)||'Passkeys indisponible.','error',context);return false;}

  function register(button){
    var card=button.closest('[data-dip-passkeys-card]')||document;
    if(!ensureSupported(card))return;
    var label=qs('[data-dip-passkey-label]',card);setBusy(button,true);setStatus('', '', card);
    request('/passkeys/register/options','POST',{label:label?label.value:''},true).then(function(data){
      return navigator.credentials.create({publicKey:creationOptions(data)}).then(function(cred){if(!cred)throw new Error('Passkey non créée.');return request('/passkeys/register/verify','POST',serializeCreate(cred,data.ceremony),true);});
    }).then(function(data){
      if(!data||!data.requiresProof)return data;
      setStatus('Vérification de la nouvelle Passkey…','',card);
      return navigator.credentials.get({publicKey:requestOptions(data)}).then(function(cred){if(!cred)throw new Error('Preuve de possession non fournie.');return request('/passkeys/register/proof','POST',serializeGet(cred,data.ceremony),true);});
    }).then(function(data){setStatus((data&&data.message)||'Passkey ajoutée.','success',card);window.setTimeout(function(){window.location.reload();},650);}).catch(function(err){setStatus(errorMessage(err),'error',card);}).finally(function(){setBusy(button,false);});
  }

  function reauth(button){
    var card=button.closest('[data-dip-passkeys-card]')||document;if(!ensureSupported(card))return;setBusy(button,true);setStatus('', '', card);
    request('/passkeys/reauth/options','POST',{},true).then(function(data){
      return navigator.credentials.get({publicKey:requestOptions(data)}).then(function(cred){if(!cred)throw new Error('Passkey non vérifiée.');return request('/passkeys/reauth/verify','POST',serializeGet(cred,data.ceremony),true);});
    }).then(function(data){setStatus((data&&data.message)||'Identité confirmée.','success',card);window.setTimeout(function(){window.location.reload();},600);}).catch(function(err){setStatus(errorMessage(err),'error',card);}).finally(function(){setBusy(button,false);});
  }

  function remove(button){
    var card=button.closest('[data-dip-passkeys-card]')||document,id=button.getAttribute('data-dip-passkey-remove')||'';
    if(!id||!window.confirm((cfg.strings&&cfg.strings.removeConfirm)||'Supprimer cette Passkey ?'))return;
    setBusy(button,true);setStatus('', '', card);
    request('/passkeys/'+encodeURIComponent(id),'DELETE',{},true).then(function(data){setStatus((data&&data.message)||'Passkey supprimée.','success',card);window.setTimeout(function(){window.location.reload();},550);}).catch(function(err){setStatus(errorMessage(err),'error',card);}).finally(function(){setBusy(button,false);});
  }

  function login(button){
    var root=button.closest('#dip-identity-modal')||button.closest('form')||document;if(!ensureSupported(root))return;
    var email=qs('#dipx-login-email',root)||qs('input[name="email"]',root)||qs('input[name="username"]',root)||qs('input[name="log"]',root)||qs('#user_login',root);
    var value=email?String(email.value||'').trim():'';
    if(!value){setStatus((cfg.strings&&cfg.strings.emailRequired)||'Entrez votre e-mail.','error',root);if(email)email.focus();return;}
    if(email&&email.validity&&!email.validity.valid){email.reportValidity();return;}
    var remember=qs('input[name="remember"]',root);var cleanUrl=window.location.origin+window.location.pathname+window.location.search;
    if(root.getAttribute&&root.getAttribute('data-password-busy')==='1')return;
    if(root.setAttribute)root.setAttribute('data-passkey-busy','1');
    setBusy(button,true);setStatus('', '', root);
    request('/passkeys/login/options','POST',{identifier:value,remember:!!(remember&&remember.checked),redirect:cleanUrl},false).then(function(data){
      return navigator.credentials.get({publicKey:requestOptions(data)}).then(function(cred){if(!cred)throw new Error('Passkey non vérifiée.');return request('/passkeys/login/verify','POST',serializeGet(cred,data.ceremony),false);});
    }).then(function(data){try{if(navigator.serviceWorker&&navigator.serviceWorker.controller)navigator.serviceWorker.controller.postMessage({type:'dbv9-clear-docs'});localStorage.setItem('dip-session-change',String(Date.now()));}catch(e){}setStatus('Connexion Passkey réussie.','success',root);window.setTimeout(function(){window.location.assign((data&&data.redirect)||cfg.redirect||'/my-account/');},250);}).catch(function(err){setStatus(errorMessage(err),'error',root);}).finally(function(){setBusy(button,false);if(root.removeAttribute)root.removeAttribute('data-passkey-busy');});
  }

  document.addEventListener('click',function(e){
    var b=e.target.closest&&e.target.closest('[data-dip-passkey-register],[data-dip-passkey-reauth],[data-dip-passkey-remove],[data-dip-passkey-login]');
    if(!b)return;e.preventDefault();if(b.disabled)return;
    if(b.hasAttribute('data-dip-passkey-register'))register(b);
    else if(b.hasAttribute('data-dip-passkey-reauth'))reauth(b);
    else if(b.hasAttribute('data-dip-passkey-remove'))remove(b);
    else login(b);
  });

  function initAvailability(){
    if(supported()&&Number(cfg.available||0))return;
    qsa('[data-dip-passkey-register],[data-dip-passkey-reauth],[data-dip-passkey-login]').forEach(function(b){b.disabled=true;b.setAttribute('aria-disabled','true');});
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',initAvailability,{once:true});else initAvailability();
})();
