(()=>{
 'use strict';
 const cfg=window.DSA2_ADMIN||{};
 document.addEventListener('click',event=>{const revoke=event.target.closest('[data-dap-confirm-revoke]');if(revoke&&!window.confirm('Déconnecter immédiatement tous les appareils Android administrateurs ?'))event.preventDefault()});
 document.addEventListener('submit',event=>{if(event.target.matches('[data-dap-confirm-delete]')&&!window.confirm('Supprimer définitivement cette conversation, ses tickets et ses pièces jointes ?'))event.preventDefault()});
 const root=document.querySelector('[data-dap-admin-inbox]');
 if(!root||!cfg.ajax)return;
 const q=(s,r=document)=>r.querySelector(s);
 const qa=(s,r=document)=>Array.from(r.querySelectorAll(s));
 const thread=q('.dap-thread',root);
 const replyForm=q('[data-dap-reply]',root);
 const routeForm=q('[data-dap-routing]',root);
 const typingLine=q('.dap-customer-typing',root);
 let lastId=thread?Number(thread.dataset.lastId||0):0;
 let customerReadMarker=thread?Number(thread.dataset.customerReadId||0):0;
 let pollTimer=0,stateTimer=0,typingTimer=0,lastTypingPing=0,busy=false,inboxBusy=false,failedPolls=0;

 const safeUrl=value=>{
  const raw=String(value||'').trim();if(!raw)return '';
  try{const url=new URL(raw,window.location.origin);return ['http:','https:'].includes(url.protocol)?url.href:''}catch(e){return ''}
 };
 const request=async data=>{
  const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),12000);
  const body=new URLSearchParams();
  Object.keys(data).forEach(key=>body.append(key,String(data[key]??'')));
  try{
   const response=await fetch(cfg.ajax,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8','X-Requested-With':'XMLHttpRequest'},body,signal:controller.signal});
   const json=await response.json().catch(()=>null);
   if(!response.ok||!json||!json.success)throw new Error(json?.data?.message||cfg.strings?.failed||'Request failed');
   failedPolls=0;root.classList.remove('dap-admin-offline');
   return json.data||{};
  }finally{clearTimeout(timeout)}
 };
 const nearBottom=()=>!thread||thread.scrollHeight-thread.scrollTop-thread.clientHeight<150;
 const scrollBottom=()=>{if(thread)requestAnimationFrame(()=>{thread.scrollTop=thread.scrollHeight})};
 const showNotice=(message,type='error',retry=null)=>{
  q('.dap-inline-notice',root)?.remove();
  const notice=document.createElement('div');notice.className='dap-inline-notice is-'+type;notice.textContent=message;
  if(typeof retry==='function'){const button=document.createElement('button');button.type='button';button.textContent='Réessayer';button.addEventListener('click',()=>{notice.remove();retry()});notice.appendChild(button)}
  (replyForm||root).prepend(notice);setTimeout(()=>notice.remove(),retry?12000:5000);
 };

 function updateAgentDelivery(marker){
  customerReadMarker=Math.max(customerReadMarker,Number(marker||0));
  if(thread)thread.dataset.customerReadId=String(customerReadMarker);
  qa('.dap-admin-msg.agent',thread||root).forEach(article=>{
   const messageId=Number(article.dataset.messageId||0);if(!messageId)return;
   let receipt=q('.dap-admin-delivery',article);if(!receipt){receipt=document.createElement('span');receipt.className='dap-admin-delivery';article.appendChild(receipt)}
   const read=messageId<=customerReadMarker;receipt.dataset.deliveryState=read?'read':'delivered';receipt.classList.toggle('is-read',read);receipt.classList.toggle('is-delivered',!read);receipt.textContent=read?'✓✓ Lu par le client':'✓ Envoyé';
  });
 }

 function addMessage(message){
  if(!thread||!message||Number(message.id||0)<=lastId)return;
  const stick=nearBottom(),article=document.createElement('article');
  const sender=String(message.sender||'assistant').replace(/[^a-z_-]/g,'');
  article.className='dap-admin-msg '+sender;article.dataset.messageId=String(message.id||0);
  const header=document.createElement('header'),strong=document.createElement('strong'),time=document.createElement('time');
  strong.textContent=sender==='customer'?'Client':(sender==='agent'?(message.sender_name||'Agent Delicat'):'Système Delicat');
  time.textContent=message.time||'';header.append(strong,time);article.appendChild(header);
  const meta=message.meta&&typeof message.meta==='object'?message.meta:{};
  const url=safeUrl(meta.url||''),mime=String(meta.mime||''),type=String(message.type||'text');
  if(url&&(type==='image'||mime.startsWith('image/'))){
   const link=document.createElement('a'),img=document.createElement('img');link.className='dap-admin-attachment';link.href=url;link.target='_blank';link.rel='noopener';img.src=url;img.alt='Image envoyée par le client';img.loading='lazy';link.appendChild(img);article.appendChild(link);
  }else if(url){
   const link=document.createElement('a');link.className='dap-admin-file';link.href=url;link.target='_blank';link.rel='noopener';link.textContent='↗ '+(meta.name||'Ouvrir la pièce jointe');article.appendChild(link);
  }else{
   const p=document.createElement('p');if(type!=='text')p.className='dap-system-copy';p.textContent=type==='text'?(message.body||''):(meta.title||message.body||type);article.appendChild(p);
  }
  const order=meta.order&&typeof meta.order==='object'?meta.order:null;
  if(order){const box=document.createElement('div');box.className='dap-admin-context dap-admin-order-context';const title=document.createElement('strong');title.textContent='🧾 '+(order.title||'Commande');box.appendChild(title);const selected=[order.status,order.total,order.date].filter(Boolean).join(' · ');if(selected){const line=document.createElement('span');line.textContent=selected;box.appendChild(line)}const orderUrl=safeUrl(order.url||'');if(orderUrl){const link=document.createElement('a');link.href=orderUrl;link.target='_blank';link.rel='noopener';link.textContent='Voir la commande ↗';box.appendChild(link)}article.appendChild(box)}
  const context=meta.context&&typeof meta.context==='object'?meta.context:null;
  if(context){const box=document.createElement('div');box.className='dap-admin-context';const title=document.createElement('strong');title.textContent='🛍️ '+(context.title||'Contexte client');box.appendChild(title);const selected=[context.plan,context.variation,context.price].filter(Boolean).join(' · ');if(selected){const line=document.createElement('span');line.textContent=selected;box.appendChild(line)}const privacy=document.createElement('small');privacy.textContent=context.player_id_field?(context.player_id_filled?'Player ID : champ rempli (valeur masquée)':'Player ID : champ vide'):'Aucune donnée sensible transmise';box.appendChild(privacy);const contextUrl=safeUrl(context.url||'');if(contextUrl){const link=document.createElement('a');link.href=contextUrl;link.target='_blank';link.rel='noopener';link.textContent='Voir la page ↗';box.appendChild(link)}article.appendChild(box)}
  thread.appendChild(article);lastId=Math.max(lastId,Number(message.id||0));thread.dataset.lastId=String(lastId);
  if(sender==='agent')updateAgentDelivery(customerReadMarker);
  root.dataset.latest=String(Math.max(Number(root.dataset.latest||0),Number(message.id||0)));
  if(stick||sender==='agent')scrollBottom();
 }

 async function pollThread(extra={}){
  if(!thread||document.hidden||busy)return;
  try{
   const data=await request({action:'dsa2_admin_thread',nonce:cfg.nonce,conversation_id:thread.dataset.conversationId,after_id:lastId,...extra});
   (data.messages||[]).forEach(addMessage);
   if(typingLine){typingLine.hidden=!data.customer_typing;}
   updateAgentDelivery(data.customer_read_id);
  }catch(e){if(++failedPolls>=3)root.classList.add('dap-admin-offline')}
 }
 const startThreadPoll=()=>{if(!thread)return;clearInterval(pollTimer);pollTimer=setInterval(()=>pollThread(),7000)};

 if(thread){scrollBottom();startThreadPoll()}

 if(replyForm){
  const textarea=q('textarea[name="body"]',replyForm),button=q('button[type="submit"]',replyForm),buttonText=q('button[type="submit"] span',replyForm);
  const draftKey='dap_admin_draft_'+String(thread?.dataset.conversationId||'');
  try{const draft=sessionStorage.getItem(draftKey);if(draft&&!textarea.value)textarea.value=draft}catch(e){}
  const resizeComposer=()=>{if(!textarea)return;textarea.style.height='auto';textarea.style.height=Math.min(160,Math.max(54,textarea.scrollHeight))+'px'};
  resizeComposer();
  // v6.4.0 quick messages. The old <select> could not be searched, showed no grouping
  // and shipped empty; this panel filters live, groups by moment in the conversation
  // and inserts text whose placeholders the server already resolved for this thread.
  const quick=q('[data-dap-quick]');
  if(quick){
    const toggle=q('[data-dap-quick-toggle]',quick),body=q('.dap-quick-body',quick),
          search=q('[data-dap-quick-search]',quick),
          items=[...quick.querySelectorAll('.dap-quick-item')],
          tabs=[...quick.querySelectorAll('.dap-quick-tabs button')];
    let group='';
    const open=v=>{body.hidden=!v;toggle.setAttribute('aria-expanded',v?'true':'false');
      if(v)setTimeout(()=>search.focus(),30)};
    const norm=t=>t.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'');
    function filter(){
      const term=norm(search.value.trim());let shown=0;
      items.forEach(el=>{
        const okG=!group||el.dataset.group===group;
        const okT=!term||norm(el.textContent+' '+(el.dataset.text||'')).includes(term);
        const vis=okG&&okT;el.hidden=!vis;if(vis)shown++;
        el.dataset.idx=vis?String(shown):'';
      });
      quick.classList.toggle('is-empty',shown===0);
    }
    function insert(text){
      if(!text)return;
      const cur=textarea.value.trim();
      textarea.value=cur?cur+'\n\n'+text:text;
      textarea.focus();
      textarea.setSelectionRange(textarea.value.length,textarea.value.length);
      textarea.dispatchEvent(new Event('input'));
      open(false);search.value='';group='';filter();
      tabs.forEach(b=>b.classList.toggle('is-on',b.dataset.group===''));
    }
    toggle.addEventListener('click',()=>open(body.hidden));
    search.addEventListener('input',filter);
    search.addEventListener('keydown',e=>{
      if(e.key==='Escape'){open(false);textarea.focus()}
      if(e.key==='Enter'){e.preventDefault();const first=items.find(el=>!el.hidden);if(first)insert(first.dataset.text)}
    });
    tabs.forEach(b=>b.addEventListener('click',()=>{
      group=b.dataset.group||'';tabs.forEach(x=>x.classList.toggle('is-on',x===b));filter()}));
    items.forEach(el=>el.addEventListener('click',()=>insert(el.dataset.text)));
    // "/" opens the panel from the composer; Alt+1..9 inserts without opening it.
    textarea.addEventListener('keydown',e=>{
      if(e.key==='/'&&!textarea.value){e.preventDefault();open(true);return}
      if(e.altKey&&e.key>='1'&&e.key<='9'){
        const n=+e.key,vis=items.filter(el=>!el.hidden);
        if(vis[n-1]){e.preventDefault();insert(vis[n-1].dataset.text)}
      }
    });
    document.addEventListener('click',e=>{if(!body.hidden&&!quick.contains(e.target))open(false)});
    filter();
  }
  textarea?.addEventListener('keydown',event=>{if(event.key==='Enter'&&(event.ctrlKey||event.metaKey)){event.preventDefault();replyForm.requestSubmit()}});
  textarea?.addEventListener('input',()=>{
   resizeComposer();try{sessionStorage.setItem(draftKey,textarea.value.slice(0,3000))}catch(e){}
   const now=Date.now();clearTimeout(typingTimer);
   if(now-lastTypingPing>7000){lastTypingPing=now;pollThread({typing:1})}
   typingTimer=setTimeout(()=>pollThread({typing:0}),2500);
  });
  replyForm.addEventListener('submit',async event=>{
   event.preventDefault();const body=(textarea?.value||'').trim();if(!body||busy)return;
   busy=true;if(button)button.disabled=true;if(buttonText)buttonText.textContent=cfg.strings?.sending||'Envoi…';
   try{
    const data=await request({action:'dsa2_admin_reply',nonce:cfg.nonce,conversation_id:thread.dataset.conversationId,body});
    addMessage(data.message);textarea.value='';textarea.style.height='';try{sessionStorage.removeItem(draftKey)}catch(e){}
    const status=q('select[name="status"]',routeForm);if(status)status.value='open';
   }catch(error){showNotice(error.message,'error',()=>replyForm.requestSubmit())}finally{busy=false;if(button)button.disabled=false;if(buttonText)buttonText.textContent=cfg.strings?.send||'Envoyer';pollThread({typing:0})}
  });
 }

 if(routeForm){
  routeForm.addEventListener('submit',async event=>{
   event.preventDefault();const button=q('button[type="submit"]',routeForm),saved=q('.dap-route-saved',routeForm),form=new FormData(routeForm);
   if(button)button.disabled=true;if(saved)saved.textContent='Enregistrement…';
   try{
    await request({action:'dsa2_admin_update',nonce:cfg.nonce,conversation_id:form.get('conversation_id'),status:form.get('status'),priority:form.get('priority'),assigned_to:form.get('assigned_to')});
    if(saved)saved.textContent='Enregistré ✓';setTimeout(()=>{if(saved)saved.textContent=''},2200);
   }catch(error){if(saved)saved.textContent='';showNotice(error.message)}finally{if(button)button.disabled=false}
  });
 }

 function conversationCard(item,selectedId){
  const link=document.createElement('a');link.className='dap-conversation-card'+(Number(item.id)===selectedId?' is-active':'')+(Number(item.wait_seconds||0)>=3600?' is-overdue-critical':Number(item.wait_seconds||0)>=900?' is-overdue':'');link.dataset.conversationId=String(item.id||0);link.href=safeUrl(item.url)||'#';
  const avatar=document.createElement('span');avatar.className='dap-avatar';avatar.textContent=item.initial||'?';
  const copy=document.createElement('span');copy.className='dap-conversation-copy';
  const first=document.createElement('span'),name=document.createElement('strong'),time=document.createElement('time');name.textContent=item.name||'';time.textContent=item.time||'';first.append(name,time);
  const contact=document.createElement('em');contact.textContent=item.contact||'';const preview=document.createElement('small');preview.textContent=item.preview||'';copy.append(first,contact,preview);
  const meta=document.createElement('span');meta.className='dap-card-meta';const state=document.createElement('i');state.className='dap-state is-'+String(item.status||'open').replace(/[^a-z_-]/g,'');state.textContent=item.status||'open';meta.appendChild(state);
  if(['high','urgent'].includes(String(item.priority||''))){const priority=document.createElement('i');priority.className='dap-priority is-'+String(item.priority);priority.textContent=item.priority==='urgent'?'Urgent':'Priorité';meta.appendChild(priority)}
  if(item.wait_label){const wait=document.createElement('small');wait.className='dap-wait-age';wait.textContent='⏱ '+String(item.wait_label);meta.appendChild(wait)}
  if(Number(item.unread||0)>0){const badge=document.createElement('b');badge.textContent=String(Math.min(99,Number(item.unread)));meta.appendChild(badge)}
  link.append(avatar,copy,meta);return link;
 }
 async function refreshInbox(){
  if(inboxBusy||document.hidden)return;inboxBusy=true;
  try{
   const params=new URLSearchParams(window.location.search);
   const result=await request({action:'dsa2_admin_inbox_items',nonce:cfg.nonce,status:params.get('status')||'',queue:params.get('queue')||'',search:params.get('s')||''});
   const scroll=q('.dap-conversation-scroll',root),selected=Number(thread?.dataset.conversationId||0);
   if(scroll){const position=scroll.scrollTop,fragment=document.createDocumentFragment();(result.items||[]).forEach(item=>fragment.appendChild(conversationCard(item,selected)));if(!(result.items||[]).length){const empty=document.createElement('div');empty.className='dap-list-empty';empty.textContent='Aucune conversation ne correspond à ce filtre.';fragment.appendChild(empty)}scroll.replaceChildren(fragment);scroll.scrollTop=position}
   const count=q('.dap-panel-heading strong',root);if(count)count.textContent=String(Number(result.count||0))+' conversation(s)';
   if(result.latest)root.dataset.latest=String(result.latest);const banner=q('.dap-inbox-refresh',root);if(banner)banner.hidden=true;
  }catch(error){const banner=q('.dap-inbox-refresh',root);if(banner)banner.hidden=false}finally{inboxBusy=false}
 }
 q('[data-dap-inbox-refresh]',root)?.addEventListener('click',refreshInbox);
 async function pollInboxState(){
  if(document.hidden)return;
  try{
   const state=await request({action:'dsa2_admin_inbox_state',nonce:cfg.nonce});
   ['waiting','open','unread','urgent','overdue'].forEach(key=>{const el=q(`[data-dap-count="${key}"]`,root);if(el)el.textContent=String(Number(state[key]||0))});
   const previous=root.dataset.latest||'';
   if(state.latest&&previous!==String(state.latest)){await pollThread();await refreshInbox()}
  }catch(e){if(++failedPolls>=3)root.classList.add('dap-admin-offline')}
 }
 stateTimer=setInterval(pollInboxState,10000);

 const handoff=q('[data-dap-handoff]',root);
 handoff?.addEventListener('toggle',async()=>{
  if(!handoff.open||handoff.dataset.loaded==='1'||!thread)return;
  const target=q('.dap-handoff-items',handoff);if(!target)return;
  target.replaceChildren();const loading=document.createElement('p');loading.textContent='Chargement du contexte…';target.appendChild(loading);
  try{
   const data=await request({action:'dsa2_admin_automation_context',nonce:cfg.nonce,conversation_id:thread.dataset.conversationId});
   target.replaceChildren();
   if(!(data.messages||[]).length){const empty=document.createElement('p');empty.textContent='Aucun échange automatique avant cette demande.';target.appendChild(empty)}
   (data.messages||[]).forEach(message=>{const item=document.createElement('article'),label=document.createElement('strong'),body=document.createElement('p');label.textContent=(message.sender==='customer'?'Client':'Assistant')+(message.time?' · '+message.time:'');body.textContent=message.body||'';item.append(label,body);target.appendChild(item)});
   handoff.dataset.loaded='1';
  }catch(error){target.replaceChildren();const failure=document.createElement('p');failure.textContent=error.message;target.appendChild(failure)}
 });

 const noteForm=q('[data-dap-note]',root);
 noteForm?.addEventListener('submit',async event=>{
  event.preventDefault();if(!thread)return;const textarea=q('textarea',noteForm),button=q('button',noteForm),body=String(textarea?.value||'').trim();if(!body)return;
  if(button)button.disabled=true;
  try{
   const data=await request({action:'dsa2_admin_note',nonce:cfg.nonce,conversation_id:thread.dataset.conversationId,body});
   const list=q('.dap-note-list',root),entry=document.createElement('article'),author=document.createElement('strong'),time=document.createElement('time'),copy=document.createElement('p');author.textContent=data.note?.author||'Agent';time.textContent=data.note?.time||'';copy.textContent=data.note?.body||'';entry.append(author,time,copy);list?.appendChild(entry);if(textarea)textarea.value='';
  }catch(error){showNotice(error.message)}finally{if(button)button.disabled=false}
 });

 // v6.2.0 — outbound messaging. The inbox could only answer people who had already written
 // in; there was no way to start a conversation with a customer who had never opened the
 // chat. This searches the whole customer base and writes into their support thread,
 // creating one on the first message if they have never had one.
 const compose=q('[data-dap-compose]',root);
 if(compose){
  const openBtn=q('[data-dap-compose-open]',root),closeBtn=q('[data-dap-compose-close]',compose);
  const searchInput=q('[data-dap-compose-search]',compose),results=q('[data-dap-compose-results]',compose);
  const selectedBox=q('[data-dap-compose-selected]',compose),bodyInput=q('[data-dap-compose-body]',compose);
  const sendBtn=q('[data-dap-compose-send]',compose),status=q('.dap-compose-status',compose);
  let target=null,searchTimer=0,searchSeq=0,sending=false;

  const setStatus=(text,kind='')=>{if(!status)return;status.textContent=text||'';status.className='dap-compose-status'+(kind?' is-'+kind:'')};
  const syncSend=()=>{if(sendBtn)sendBtn.disabled=sending||!target||!String(bodyInput?.value||'').trim()};
  const hint=text=>{results.replaceChildren();const p=document.createElement('p');p.className='dap-compose-hint';p.textContent=text;results.appendChild(p)};

  const selectTarget=item=>{
   target=item;
   if(selectedBox){
    selectedBox.hidden=false;selectedBox.replaceChildren();
    const name=document.createElement('strong');name.textContent=item.name||'';
    const contact=document.createElement('span');contact.textContent=item.contact||'';
    const meta=document.createElement('small');meta.textContent=item.meta||'';
    const clear=document.createElement('button');clear.type='button';clear.textContent='Changer';
    clear.addEventListener('click',()=>{target=null;selectedBox.hidden=true;syncSend();searchInput?.focus()});
    selectedBox.append(name,contact,meta,clear);
   }
   results.replaceChildren();setStatus('');syncSend();bodyInput?.focus();
  };

  async function runSearch(term){
   const seq=++searchSeq;
   hint(cfg.strings?.searching||'Recherche…');
   try{
    const data=await request({action:'dsa2_admin_find_customer',nonce:cfg.nonce,search:term});
    if(seq!==searchSeq)return; // a newer keystroke already won
    const items=data.items||[];
    if(!items.length){hint(cfg.strings?.noCustomer||'Aucun client trouvé.');return}
    results.replaceChildren();
    items.forEach(item=>{
     const row=document.createElement('button');row.type='button';row.className='dap-compose-result'+(item.known?'':' is-new');row.setAttribute('role','option');
     const name=document.createElement('strong');name.textContent=item.name||'';
     const contact=document.createElement('span');contact.textContent=item.contact||'';
     const meta=document.createElement('small');meta.textContent=item.meta||'';
     row.append(name,contact,meta);
     row.addEventListener('click',()=>selectTarget(item));
     results.appendChild(row);
    });
   }catch(error){if(seq===searchSeq)hint(error.message)}
  }

  searchInput?.addEventListener('input',()=>{
   clearTimeout(searchTimer);
   const term=String(searchInput.value||'').trim();
   if(term.length<2){searchSeq++;hint('Tapez au moins deux caractères.');return}
   searchTimer=setTimeout(()=>runSearch(term),320);
  });
  bodyInput?.addEventListener('input',syncSend);
  bodyInput?.addEventListener('keydown',event=>{if(event.key==='Enter'&&(event.ctrlKey||event.metaKey)){event.preventDefault();sendBtn?.click()}});

  const toggleCompose=show=>{
   compose.hidden=!show;
   if(show){searchInput?.focus();if(!target&&String(searchInput?.value||'').trim().length<2)hint('Tapez au moins deux caractères.')}
   openBtn?.setAttribute('aria-expanded',show?'true':'false');
  };
  openBtn?.addEventListener('click',()=>toggleCompose(compose.hidden));
  closeBtn?.addEventListener('click',()=>toggleCompose(false));
  compose.addEventListener('keydown',event=>{if(event.key==='Escape')toggleCompose(false)});

  sendBtn?.addEventListener('click',async()=>{
   const body=String(bodyInput?.value||'').trim();
   if(!target||!body||sending)return;
   sending=true;syncSend();setStatus(cfg.strings?.sending||'Envoi…');
   try{
    const data=await request({action:'dsa2_admin_direct_message',nonce:cfg.nonce,body,user_id:target.user_id||0,conversation_id:target.conversation_id||0});
    bodyInput.value='';setStatus(cfg.strings?.composeSent||'Message envoyé ✓','ok');
    await refreshInbox();
    // Land the agent in the thread they just started so the reply arrives in front of them.
    const url=safeUrl(data.url);
    if(url)setTimeout(()=>{window.location.href=url},700);
   }catch(error){setStatus(error.message,'error')}finally{sending=false;syncSend()}
  });
 }

 document.addEventListener('visibilitychange',()=>{if(!document.hidden){pollThread();pollInboxState()}});
 window.addEventListener('online',()=>{root.classList.remove('dap-admin-offline');failedPolls=0;pollThread();pollInboxState()});
 window.addEventListener('offline',()=>root.classList.add('dap-admin-offline'));
 window.addEventListener('beforeunload',()=>{clearInterval(pollTimer);clearInterval(stateTimer);clearTimeout(typingTimer)});
})();
