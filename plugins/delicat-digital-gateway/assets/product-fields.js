/* Delicat Digital Gateway: top-up account fields (recent Player IDs, account verification). */
(function(){
  // Loaded once per tab; pages the Builder swaps in reuse it (see the listener at the end).
  if(window.dfrProductFieldsReady)return;window.dfrProductFieldsReady=true;
  function safeRows(value){return Array.isArray(value)?value.filter(function(r){return r&&/^[A-Za-z0-9._:-]{1,64}$/.test(String(r.value||''));}).slice(0,5):[];}
  function localRows(wrap){try{return safeRows(JSON.parse(localStorage.getItem(wrap.dataset.recentKey)||'[]'));}catch(e){return [];}}
  function mergedRows(wrap){var recent=wrap.querySelector('.dfr-recent'),server=[];try{server=safeRows(JSON.parse(recent.dataset.serverRecent||'[]'));}catch(e){}var out=[];server.concat(localRows(wrap)).forEach(function(r){if(!out.some(function(x){return x.value===r.value;}))out.push(r);});return out.slice(0,5);}
  function renderRecent(wrap){var recent=wrap.querySelector('.dfr-recent'),menu=recent&&recent.querySelector('.dfr-recent-menu'),rows=mergedRows(wrap);if(!menu)return;menu.textContent='';rows.forEach(function(r){var b=document.createElement('button');b.type='button';b.className='dfr-recent-id';b.dataset.value=r.value;var id=document.createElement('span');id.textContent=r.value;b.appendChild(id);if(r.label){var sm=document.createElement('small');sm.textContent=r.label;b.appendChild(sm);}menu.appendChild(b);});recent.hidden=rows.length===0;}
  function saveRecent(wrap,value,label){value=String(value||'').trim();if(!/^[A-Za-z0-9._:-]{1,64}$/.test(value))return;var rows=localRows(wrap).filter(function(r){return r.value!==value;});rows.unshift({value:value,label:String(label||'').slice(0,80),used_at:Math.floor(Date.now()/1000)});try{localStorage.setItem(wrap.dataset.recentKey,JSON.stringify(rows.slice(0,5)));}catch(e){}renderRecent(wrap);}
  document.querySelectorAll('.dfr-native-fields').forEach(renderRecent);
  function setStatus(box, ok, text){
    var s=box.querySelector('.dfr-uid-status'); if(!s)return;
    s.style.display='block'; s.style.background=ok?'#eaf8ef':'#fff1f1'; s.style.color=ok?'#146b35':'#9b2727';
    s.style.border='1px solid '+(ok?'#bfe8cc':'#f1caca'); s.textContent=text;
  }
  function clearVerification(wrap){
    wrap._dfrVerifyRevision=(wrap._dfrVerifyRevision||0)+1;
    var token=wrap.querySelector('.dfr-uid-token'); if(token)token.value='';
    var box=wrap.querySelector('.dfr-uid-verify'); if(box){var s=box.querySelector('.dfr-uid-status');if(s)s.style.display='none';}
  }
  document.addEventListener('input',function(e){var wrap=e.target.closest&&e.target.closest('.dfr-native-fields');if(wrap&&e.target.matches('[data-dfr-field-key]'))clearVerification(wrap);});
  document.addEventListener('change',function(e){var wrap=e.target.closest&&e.target.closest('.dfr-native-fields');if(wrap&&e.target.matches('[data-dfr-field-key]'))clearVerification(wrap);});
  document.addEventListener('click',function(e){
    var toggle=e.target.closest&&e.target.closest('.dfr-recent-toggle');if(toggle){var picker=toggle.closest('.dfr-recent-picker'),open=!picker.classList.contains('is-open');picker.classList.toggle('is-open',open);toggle.setAttribute('aria-expanded',open?'true':'false');return;}
    var recentId=e.target.closest&&e.target.closest('.dfr-recent-id');if(recentId){var recentWrap=recentId.closest('.dfr-native-fields'),player=recentWrap&&recentWrap.querySelector('[data-dfr-role="player"]');if(player){player.value=recentId.dataset.value;player.dispatchEvent(new Event('input',{bubbles:true}));player.focus();}var rp=recentId.closest('.dfr-recent-picker');if(rp){rp.classList.remove('is-open');var rt=rp.querySelector('.dfr-recent-toggle');if(rt)rt.setAttribute('aria-expanded','false');}return;}
    var clear=e.target.closest&&e.target.closest('.dfr-recent-clear');if(clear){var cw=clear.closest('.dfr-native-fields'),cr=clear.closest('.dfr-recent');try{localStorage.removeItem(cw.dataset.recentKey);}catch(err){}cr.dataset.serverRecent='[]';renderRecent(cw);if(cr.dataset.clearNonce){var cb=new URLSearchParams();cb.set('action','dfr_clear_recent_player_ids');cb.set('nonce',cr.dataset.clearNonce);fetch(cr.dataset.clearAjax,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:cb.toString()}).catch(function(){});}return;}
    var btn=e.target.closest&&e.target.closest('.dfr-uid-verify-btn'); if(!btn)return;
    var wrap=btn.closest('.dfr-native-fields'), box=btn.closest('.dfr-uid-verify'); if(!wrap||!box)return;
    var fields={}, missing=false;
    wrap.querySelectorAll('[data-dfr-field-key]').forEach(function(el){var v=(el.value||'').trim();if(el.required&&!v)missing=true;if(v)fields[el.getAttribute('data-dfr-field-key')]=v;});
    if(missing||Object.keys(fields).length===0){setStatus(box,false,'Entrez l’UID / Player ID et les champs requis avant la vérification.');return;}
    clearVerification(wrap);var revision=wrap._dfrVerifyRevision,product=wrap.dataset.dfrProduct;
    function isCurrent(){return wrap.isConnected&&revision===wrap._dfrVerifyRevision&&product===wrap.dataset.dfrProduct;}
    btn.disabled=true; var label=btn.querySelector('span'),old=label?label.textContent:''; if(label)label.textContent='Vérification…';
    var body=new URLSearchParams(); body.set('action','dfr_verify_player_uid');body.set('nonce',box.dataset.nonce);body.set('product_id',wrap.dataset.dfrProduct);
    Object.keys(fields).forEach(function(k){body.append('fields['+k+']',fields[k]);});
    var controller=window.AbortController?new AbortController():null;
    var abortMs=parseInt(box.dataset.abortMs||'22000',10)||22000; var timeout=controller?setTimeout(function(){controller.abort();},abortMs):null;
    function post(b){return fetch(box.dataset.ajax,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:b.toString(),signal:controller?controller.signal:undefined}).then(function(r){return r.json().catch(function(){return null;}).then(function(j){return {r:r,j:j};});});}
    var renewed=false;
    function renew(x){var d=x&&x.j&&!x.j.success&&x.j.data;if(renewed||!d||d.code!=='nonce')return x;renewed=true;var nb=new URLSearchParams();nb.set('action','dfr_uid_nonce');return post(nb).then(function(n){var nd=n&&n.j&&n.j.success&&n.j.data;if(!nd||!nd.nonce)return x;box.dataset.nonce=nd.nonce;body.set('nonce',nd.nonce);return post(body);});}
    post(body).then(renew)
      .then(function(x){
        if(!isCurrent())return;
        var d=x.j&&x.j.data?x.j.data:{};
        if(x.j&&x.j.success&&d.valid&&d.token){
          var t=wrap.querySelector('.dfr-uid-token');if(t)t.value=d.token;
          var msg='✅ Compte vérifié'; if(d.player_name)msg+=' — '+d.player_name; if(d.region)msg+=' ('+d.region+')';
          setStatus(box,true,msg);
          var player=wrap.querySelector('[data-dfr-role="player"]');if(player)saveRecent(wrap,player.value,d.player_name||d.region||'');
        }else{clearVerification(wrap);setStatus(box,false,(d&&d.message)?d.message:'Impossible de vérifier cet ID pour le moment.');}
      })
      .catch(function(err){if(!isCurrent())return;clearVerification(wrap);setStatus(box,false,(err&&err.name==='AbortError')?'La vérification prend trop de temps. Réessayez dans quelques instants.':'Connexion interrompue. Réessayez.');})
      .finally(function(){if(timeout)clearTimeout(timeout);btn.disabled=false;if(label)label.textContent=old;});
  });
  document.addEventListener('click',function(e){document.querySelectorAll('.dfr-recent-picker.is-open').forEach(function(p){if(!p.contains(e.target)){p.classList.remove('is-open');var t=p.querySelector('.dfr-recent-toggle');if(t)t.setAttribute('aria-expanded','false');}});});
  ['delicat:navigated','dsb:content-updated'].forEach(function(t){document.addEventListener(t,function(){document.querySelectorAll('.dfr-native-fields').forEach(renderRecent);});});
})();
