(function () {
  'use strict';
  var cfg=window.DIPIdentityModal||{},root=document.getElementById('dip-identity-modal');
  if(!root || window.DIPAuthFrame)return;
  var login=root.querySelector('.dipx-form-login'),register=root.querySelector('.dipx-form-register');
  var message=root.querySelector('.dipx-message'),resend=root.querySelector('[data-dipx-resend]');
  var forms=[login,register].filter(Boolean),active=false,busy=false,generation=0,nonces=null,nonceTime=0,pending=null,controllers=[];
  var tabs=Array.prototype.slice.call(root.querySelectorAll('[data-dipx-tab]'));
  var factor=root.querySelector('[data-dipx-2fa]');
  var passkey=root.querySelector('[data-dip-passkey-login]');
  if(passkey){passkey.setAttribute('data-dip-passkey-loading','1');passkey.disabled=true;if(Number((window.DIPPasskeys||{}).available)!==1)passkey.hidden=true;}
  var sizeRequest=0;
  function measure(){
    sizeRequest=0;if(!active)return;
    var height=root.getBoundingClientRect().height;
    var style=window.getComputedStyle(document.body);
    height+=parseFloat(style.paddingTop)||0;height+=parseFloat(style.paddingBottom)||0;
    if(window.parent.DIPIdentityModalAPI.resize)window.parent.DIPIdentityModalAPI.resize(Math.ceil(height)+2);
  }
  function requestSize(){if(active&&!sizeRequest)sizeRequest=window.requestAnimationFrame(measure);}
  if(typeof ResizeObserver!=='undefined')new ResizeObserver(requestSize).observe(root);
  window.addEventListener('resize',requestSize);
  function show(text,ok){message.textContent=text||'';message.hidden=!text;message.className='dipx-message '+(ok?'is-success':'is-error');requestSize();}
  function passkeyBusy(){return root.getAttribute('data-passkey-busy')==='1';}
  function state(on){
    busy=on;root.setAttribute('data-password-busy',on?'1':'0');
    tabs.forEach(function(t){t.disabled=on;});
    forms.forEach(function(f){
      f.setAttribute('aria-busy',on?'true':'false');
      var b=f.querySelector('.dipx-submit');if(b)b.disabled=on||f.hasAttribute('data-verification-pending');
      var spinner=f.querySelector('.dipx-spinner');if(spinner)spinner.hidden=!on;
    });
    var pk=root.querySelector('[data-dip-passkey-login]');if(pk&&!pk.hasAttribute('aria-disabled'))pk.disabled=on||pk.hasAttribute('data-dip-passkey-loading');
    if(resend)resend.disabled=on;
  }
  function post(data){
    var controller=typeof AbortController!=='undefined'?new AbortController():null,timer;
    if(controller)controllers.push(controller);
    var deadline=new Promise(function(_,reject){timer=setTimeout(function(){if(controller)controller.abort();reject(new Error('Le serveur met trop de temps à répondre. Réessayez.'));},15000);});
    var network=Promise.resolve().then(function(){return fetch(cfg.ajaxUrl||'/wp-admin/admin-ajax.php',{method:'POST',body:data,credentials:'same-origin',cache:'no-store',signal:controller?controller.signal:undefined});})
      .then(function(r){return r.text();}).then(function(raw){try{return JSON.parse(raw);}catch(e){throw new Error('Réponse serveur invalide. Réessayez.');}});
    return Promise.race([network,deadline]).finally(function(){clearTimeout(timer);controllers=controllers.filter(function(c){return c!==controller;});});
  }
  function fresh(force){
    if(!force&&nonces&&Date.now()-nonceTime<300000)return Promise.resolve(nonces);
    if(pending)return pending;
    var g=generation,data=new FormData();data.append('action',cfg.nonceAction||'dip_native_fresh_nonces_v2');
    pending=post(data).then(function(r){
      if(g!==generation)throw new Error('Session fermée.');
      if(!r.success||!r.data||!r.data.login||!r.data.register||!r.data.resend)throw new Error('Impossible de préparer la connexion. Réessayez.');
      nonces=r.data;nonceTime=Date.now();return nonces;
    }).finally(function(){if(g===generation)pending=null;});
    return pending;
  }
  function select(name){
    if(busy||passkeyBusy())return;
    var reg=name==='register'&&register;
    login.hidden=!!reg;if(register)register.hidden=!reg;
    tabs.forEach(function(t){var yes=t.getAttribute('data-dipx-tab')===(reg?'register':'login');t.setAttribute('aria-selected',yes?'true':'false');t.tabIndex=yes?0:-1;t.classList.toggle('is-active',yes);});
    var sub=root.querySelector('.dipx-subtitle');if(sub)sub.textContent=reg?'Créez votre compte Delicat Store':'Connectez-vous pour continuer';
    show('');if(resend)resend.hidden=true;requestSize();
  }
  function navigate(value){
    var url=new URL(value||cfg.redirectUrl||'/',window.parent.location.href);
    if(url.origin!==window.parent.location.origin||!/^https?:$/.test(url.protocol))throw new Error('Adresse de retour invalide.');
    try{if(navigator.serviceWorker&&navigator.serviceWorker.controller)navigator.serviceWorker.controller.postMessage({type:'dbv9-clear-docs'});localStorage.setItem('dip-session-change',String(Date.now()));}catch(e){}
    window.parent.location.assign(url.href);
    setTimeout(function(){if(active){state(false);show('Connexion réussie. Actualisez la page pour continuer.',true);}},10000);
  }
  window.DIPAuthNavigate=navigate;
  function resendFor(email){if(!resend||!email)return;resend.dataset.email=email;resend.hidden=false;}
  function send(form,retried,g){
    var kind=form===register?'register':'login';
    return fresh(false).then(function(n){
      if(g!==generation)throw new Error('Session fermée.');
      var data=new FormData(form);data.set('nonce',n[kind]);data.set('action',kind==='register'?(cfg.registerAction||'dip_native_do_register_v2'):(cfg.loginAction||'dip_native_do_login_v2'));
      return post(data);
    }).then(function(r){
      if(g!==generation)return;
      var d=r.data||{};
      if(!r.success&&d.code==='bad_nonce'&&!retried){nonces=null;return fresh(true).then(function(){return send(form,true,g);});}
      if(r.success){
        if(d.verificationRequired){
          form.setAttribute('data-verification-pending','1');
          state(false);show(d.message||'Vérifiez votre adresse e-mail.',true);
          resendFor(form.querySelector('[name="email"]').value);return;
        }
        show(d.message||'Connexion réussie.',true);navigate(d.redirect);return;
      }
      state(false);
      if(factor&&['dip_2fa_required','dip_2fa_invalid','dip_2fa_rate_limited'].indexOf(d.code)!==-1){factor.hidden=false;factor.querySelector('input').required=true;}
      show(d.message||'Impossible de continuer. Réessayez.');
      if(d.code==='dip_email_not_verified')resendFor(form.querySelector('[name="email"]').value);
    });
  }
  forms.forEach(function(form){form.addEventListener('submit',function(e){
    e.preventDefault();if(busy||passkeyBusy()||form.hasAttribute('data-verification-pending'))return;
    if(!form.checkValidity()){form.reportValidity();return;}
    var g=generation;state(true);show('Connexion en cours…',true);
    send(form,false,g).catch(function(err){if(g!==generation)return;state(false);show(err.message||'Erreur réseau. Réessayez.');});
  });});
  tabs.forEach(function(t){t.addEventListener('click',function(){select(t.getAttribute('data-dipx-tab'));});t.addEventListener('keydown',function(e){if(e.key==='ArrowLeft'||e.key==='ArrowRight'){e.preventDefault();if(busy||passkeyBusy())return;var next=t.getAttribute('data-dipx-tab')==='login'?'register':'login';select(next);tabs.forEach(function(n){if(n.getAttribute('data-dipx-tab')===next)n.focus();});}});});
  Array.prototype.forEach.call(root.querySelectorAll('[data-dipx-eye]'),function(b){
    b.innerHTML='<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/><path class="dip-eye-slash" d="m4 4 16 16"/></svg>';
    b.setAttribute('aria-pressed','false');
    b.addEventListener('click',function(){var input=b.parentNode.querySelector('input'),reveal=input.type==='password';input.type=reveal?'text':'password';b.setAttribute('aria-label',reveal?'Masquer le mot de passe':'Afficher le mot de passe');b.setAttribute('aria-pressed',reveal?'true':'false');});
  });
  // A real browser link in its own document: parent menu/SPA listeners never
  // receive these input events. No synthetic tap, click suppression or timers.
  Array.prototype.forEach.call(root.querySelectorAll('a[href]'),function(a){a.target='_top';a.setAttribute('data-delicat-no-app','1');a.addEventListener('click',function(e){if(busy||passkeyBusy()){e.preventDefault();show('Vérification en cours. Veuillez patienter.',true);}});});
  if(resend)resend.addEventListener('click',function(){
    if(busy||passkeyBusy())return;var email=resend.dataset.email;if(!email)return;
    var g=generation;state(true);
    function deliver(retry){return fresh(false).then(function(n){var data=new FormData();data.set('action',cfg.resendAction||'dip_native_resend_verification_v2');data.set('email',email);data.set('nonce',n.resend);return post(data);}).then(function(r){
      if(g!==generation)return;
      if(!r.success&&r.data&&r.data.code==='bad_nonce'&&!retry){nonces=null;return deliver(true);}
      state(false);show((r.data&&r.data.message)||'Demande traitée.',!!r.success);if(r.success)resend.hidden=true;
    });}
    deliver(false).catch(function(err){if(g===generation){state(false);show(err.message);}});
  });
  document.addEventListener('keydown',function(e){if(e.key==='Escape'){e.preventDefault();window.parent.DIPIdentityModalAPI.close();}});
  window.DIPAuthFrame={
    open:function(tab){
      active=true;select(tab);
      fresh(false).catch(function(){/* Submit retries; Google remains a native link. */});
      var params=new URLSearchParams(window.parent.location.search),error=params.get('dip_auth_error');
      if(error)show(errorMessages[error]||'La connexion Google a échoué. Réessayez.');
      else if(params.get('dip_verified')==='1')show('Adresse e-mail vérifiée. Vous pouvez vous connecter.',true);
    },
    close:function(){
      if(busy||passkeyBusy()){show('Vérification en cours. Veuillez patienter.',true);return false;}
      active=false;if(sizeRequest)window.cancelAnimationFrame(sizeRequest);sizeRequest=0;generation++;controllers.forEach(function(c){c.abort();});controllers=[];pending=null;nonces=null;
      forms.forEach(function(f){f.removeAttribute('data-verification-pending');Array.prototype.forEach.call(f.querySelectorAll('input[type="password"],input[name="password"],input[name="dip_2fa_code"]'),function(i){i.value='';if(i.name==='password')i.type='password';});});
      Array.prototype.forEach.call(root.querySelectorAll('[data-dipx-eye]'),function(b){b.setAttribute('aria-pressed','false');b.setAttribute('aria-label','Afficher le mot de passe');});
      if(factor){factor.hidden=true;factor.querySelector('input').required=false;}state(false);show('');return true;
    }
  };
    var errorMessages = {
      invalid_credentials: 'Identifiants incorrects. Vérifiez votre e-mail et votre mot de passe.',
      access_denied: 'Connexion Google annulée ou refusée.',
      rate_limited: 'Trop de tentatives. Réessayez plus tard.',
      temporarily_locked: 'Connexion temporairement bloquée. Réessayez plus tard.',
      dip_email_not_verified: 'Vérifiez votre adresse e-mail avant de vous connecter.',
      account_pending_approval: 'Votre compte attend l’approbation de l’administrateur.',
      privileged_auto_link_blocked: 'Ce compte sensible existe déjà. Delicat refuse une simple liaison par e-mail et doit reprendre son lien Google historique ou une liaison sécurisée.',
      duplicate_nextend_google_identity: 'Cette identité Google apparaît sur plusieurs comptes historiques. Une vérification administrateur est requise.',
      legacy_google_email_mismatch: 'L’adresse Google ne correspond pas exactement au compte Delicat historiquement lié.',
      legacy_google_owner_conflict: 'Le lien Google historique et l’adresse e-mail pointent vers deux comptes différents.',
      legacy_privileged_continuity_failed: 'Le lien Google historique n’a pas pu être validé de manière sécurisée.',
      email_exists: 'Un compte utilise déjà cette adresse. Connectez-vous avec votre mot de passe puis reliez Google depuis votre compte.',
      not_configured: 'Google Login n’est pas disponible actuellement.',
      provider_unavailable: 'Le fournisseur Google est momentanément indisponible. Réessayez.',
      invalid_authorization_url: 'La configuration de connexion Google est invalide. Vérifiez les réglages OAuth.',
      missing_callback_data: 'Google a renvoyé une réponse incomplète. Recommencez la connexion.',
      invalid_state: 'La session Google a expiré ou n’est plus valide. Recommencez depuis Delicat Store.',
      state_already_used: 'Cette connexion Google a déjà été traitée. Si vous n’êtes pas connecté, recommencez.',
      browser_binding_failed: 'La session Google n’est plus liée à ce navigateur. Fermez puis relancez la connexion.',
      token_transport_error: 'Impossible de contacter Google pour terminer la connexion. Réessayez.',
      token_error: 'Google n’a pas validé cette tentative de connexion. Recommencez.',
      token_response_too_large: 'Google a renvoyé une réponse inattendue. Recommencez la connexion.',
      google_keys_unavailable: 'Impossible de vérifier les clés de sécurité Google actuellement. Réessayez dans un instant.',
      google_keys_too_large: 'La réponse de sécurité Google est invalide. Réessayez.',
      google_keys_invalid: 'Les clés de sécurité Google n’ont pas pu être validées. Réessayez.',
      google_key_missing: 'Google a renouvelé sa clé de sécurité. Réessayez la connexion.',
      google_key_type: 'La clé de sécurité Google reçue n’est pas prise en charge.',
      google_key_use: 'La clé de sécurité Google reçue est invalide.',
      google_key_alg: 'L’algorithme de sécurité Google reçu est invalide.',
      google_key_invalid: 'La clé de sécurité Google reçue est invalide.',
      google_signature: 'La signature de sécurité Google n’a pas pu être vérifiée.',
      google_jwt_format: 'Le jeton de connexion Google est invalide. Recommencez.',
      google_jwt_header: 'La signature du jeton Google est invalide.',
      google_openssl_missing: 'Le serveur ne peut pas vérifier cryptographiquement Google. Contactez l’administrateur.',
      google_audience_missing: 'L’identifiant OAuth Google n’est pas configuré correctement.',
      invalid_audience: 'L’identifiant OAuth Google ne correspond pas à ce site. Vérifiez le Client ID.',
      invalid_issuer: 'L’émetteur du jeton Google est invalide.',
      invalid_token_time: 'Le jeton Google a expiré ou sa date est invalide. Recommencez.',
      invalid_nonce: 'La vérification anti-rejeu Google a échoué. Recommencez la connexion.',
      invalid_subject: 'L’identité Google reçue est invalide.',
      email_not_verified: 'Google n’a pas confirmé cette adresse e-mail.',
      userinfo_subject_mismatch: 'Le profil Google ne correspond pas au jeton de connexion. Recommencez.',
      two_factor_expired: 'La vérification en deux étapes a expiré. Recommencez la connexion.',
      two_factor_https_required: 'HTTPS est requis pour terminer la vérification en deux étapes.',
      two_factor_challenge_failed: 'Impossible de démarrer la vérification en deux étapes. Réessayez.',
      dip_2fa_rate_limited: 'Trop de tentatives 2FA. Réessayez dans quelques minutes.',
      admin_google_secure_mode_disabled: 'Google Secure Mode Administrateur est désactivé.',
      admin_google_https_required: 'HTTPS est requis pour Google Secure Mode Administrateur.',
      admin_google_identity_mismatch: 'Cette identité Google ne correspond pas à l’identité Administrateur approuvée.',
      admin_google_not_authorized: 'Cette identité Google doit être approuvée depuis votre Centre de sécurité Administrateur.',
      admin_google_two_factor_required: 'Activez TOTP 2FA avant d’utiliser Google avec votre compte Administrateur.',
      admin_google_link_authorization_failed: 'La liaison Google Administrateur n’a pas pu être autorisée de manière sécurisée.',
      privileged_link_requires_secure_mode: 'Confirmez votre identité et activez TOTP 2FA avant de relier Google à cet Administrateur.',
      provider_already_linked_elsewhere: 'Cette identité Google est déjà liée à un autre compte.'
    };
})();
