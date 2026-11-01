(()=>{
 const q=(s,r=document)=>r.querySelector(s),qa=(s,r=document)=>[...r.querySelectorAll(s)];
 const esc=s=>String(s??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));
 const safeUrl=value=>{const raw=String(value||'').trim();if(!raw)return '#';try{const u=new URL(raw,window.location.origin);return ['http:','https:'].includes(u.protocol)?u.href:'#'}catch(e){return '#'}};
 const store={get:k=>{try{return localStorage.getItem(k)}catch(e){return null}},set:(k,v)=>{try{localStorage.setItem(k,v)}catch(e){}}};
 const sstore={get:k=>{try{return sessionStorage.getItem(k)}catch(e){return null}},set:(k,v)=>{try{sessionStorage.setItem(k,v)}catch(e){}},del:k=>{try{sessionStorage.removeItem(k)}catch(e){}}};

 const ajaxUrl=(()=>{try{const raw=String(window.DSA2_DATA?.ajax||'');const u=new URL(raw,window.location.href);if(u.origin!==window.location.origin){const path=window.DSA2_DATA?.ajaxPath||u.pathname;return window.location.origin+path}return u.href}catch(e){return String(window.DSA2_DATA?.ajax||'/wp-admin/admin-ajax.php')}})();
 const WA_SVG='<span class="dap-wa-inline" aria-hidden="true">📱</span>';
 const copy={
  fr:{eyebrow:'CENTRE D’AIDE DELICAT',hello:'Comment pouvons-nous vous aider ?',sub:'Choisissez une réponse instantanée ou une vraie conversation avec notre équipe.',smartTitle:'Aide rapide',smartSub:'Commandes, crédit boutique, PIN et produits en quelques secondes.',input:'Posez votre question...',agentInput:'Écrivez à l’équipe support...',secure:'Conversation sécurisée par Delicat Store',online:'Équipe disponible maintenant',away:'Réponse de l’équipe bientôt',offline:'Laissez un message à l’équipe',error:'Connexion momentanément indisponible. Touchez Réessayer.',home:'Accueil',bot:'Assistant',products:'Produits',order:'Commande',agent:'Équipe',balance:'Mon solde',lastOrder:'Dernière commande',orderHelp:'Aide commande',productQuestion:'Question sur ce produit',rewards:'Récompenses',gamesProducts:'Jeux & produits',startChat:'Parler à l’équipe',startBot:'Réponse instantanée',botTitle:'Assistant Delicat',botSubtitle:'Automatique · disponible 24 h/24',agentTitle:'Équipe support',agentSubtitle:'Un agent vous répond dans cette conversation',contactWhatsapp:'Continuer sur WhatsApp',guides:'Guides',allGuides:'Tous les guides',forYou:'Pour vous',howRecharge:'Comment recharger',searchBtn:'Chercher',searchPh:'Rechercher un produit...',faq:'Questions fréquentes',quickTools:'Raccourcis utiles',chooseChannel:'Comment souhaitez-vous être aidé ?',endChat:'Clore la demande',conversationTitle:'Conversation avec Delicat',conversationSubtitle:'Historique client et agents uniquement',emptyConversation:'Commencez la conversation',emptyConversationSub:'Décrivez votre besoin ci-dessous. L’équipe verra votre message et vous répondra ici.',clientLabel:'Vous',agentLabel:'Équipe Delicat',tooBig:'Image trop lourde (5 Mo maximum).',shareCopied:'Guide copié ✓',offlineNotice:'Connexion internet indisponible. Vous pouvez continuer à lire; l’envoi reprendra dès que le réseau revient.'},
  en:{eyebrow:'DELICAT HELP CENTER',hello:'How can we help you?',sub:'Choose an instant answer or a real conversation with our team.',smartTitle:'Quick help',smartSub:'Orders, store credit, PINs and products in seconds.',input:'Ask your question...',agentInput:'Write to the support team...',secure:'Conversation secured by Delicat Store',online:'Team available now',away:'The team will reply soon',offline:'Leave the team a message',error:'Connection temporarily unavailable. Tap Retry.',home:'Home',bot:'Assistant',products:'Products',order:'Order',agent:'Team',balance:'My balance',lastOrder:'Last order',orderHelp:'Order help',productQuestion:'Question about this product',rewards:'Rewards',gamesProducts:'Games & products',startChat:'Talk to the team',startBot:'Get an instant answer',botTitle:'Delicat Assistant',botSubtitle:'Automated · available 24/7',agentTitle:'Support team',agentSubtitle:'An agent will reply in this conversation',contactWhatsapp:'Continue on WhatsApp',guides:'Guides',allGuides:'All guides',forYou:'For you',howRecharge:'How to top up',searchBtn:'Search',searchPh:'Search for a product...',faq:'FAQ',quickTools:'Useful shortcuts',chooseChannel:'How would you like help?',endChat:'Close request',conversationTitle:'Conversation with Delicat',conversationSubtitle:'Customer and agent history only',emptyConversation:'Start the conversation',emptyConversationSub:'Describe what you need below. The team will see your message and reply here.',clientLabel:'You',agentLabel:'Delicat Team',tooBig:'Image too large (5 MB maximum).',shareCopied:'Guide copied ✓',offlineNotice:'Internet connection unavailable. You can keep reading; sending will resume when the network returns.'},
  ht:{eyebrow:'SANT ÈD DELICAT',hello:'Kijan nou ka ede w?',sub:'Chwazi yon repons touswit oswa yon konvèsasyon reyèl ak ekip nou an.',smartTitle:'Èd rapid',smartSub:'Kòmand, kredi boutik, PIN ak pwodwi nan kèk segonn.',input:'Poze kesyon ou...',agentInput:'Ekri ekip sipò a...',secure:'Delicat Store sekirize konvèsasyon an',online:'Ekip la disponib kounye a',away:'Ekip la ap reponn byento',offline:'Kite yon mesaj pou ekip la',error:'Koneksyon an pa disponib pou kounye a. Peze Eseye ankò.',home:'Akèy',bot:'Asistan',products:'Pwodwi',order:'Kòmand',agent:'Ekip',balance:'Balans mwen',lastOrder:'Dènye kòmand',orderHelp:'Èd kòmand',productQuestion:'Kesyon sou pwodwi sa a',rewards:'Rekonpans',gamesProducts:'Jwèt ak pwodwi',startChat:'Pale ak ekip la',startBot:'Jwenn repons touswit',botTitle:'Asistan Delicat',botSubtitle:'Otomatik · disponib 24 sou 24',agentTitle:'Ekip sipò',agentSubtitle:'Yon ajan ap reponn nan konvèsasyon sa a',contactWhatsapp:'Kontinye sou WhatsApp',guides:'Gid',allGuides:'Tout gid yo',forYou:'Pou ou',howRecharge:'Kijan pou rechaje',searchBtn:'Chèche',searchPh:'Chèche yon pwodwi...',faq:'Kesyon souvan',quickTools:'Rakousi itil',chooseChannel:'Ki jan ou vle nou ede w?',endChat:'Fèmen demann lan',conversationTitle:'Konvèsasyon ak Delicat',conversationSubtitle:'Sèlman istwa kliyan ak ajan',emptyConversation:'Kòmanse konvèsasyon an',emptyConversationSub:'Eksplike bezwen ou anba a. Ekip la ap wè mesaj ou epi reponn ou isit la.',clientLabel:'Ou',agentLabel:'Ekip Delicat',tooBig:'Imaj la twò lou (5 Mo maksimòm).',shareCopied:'Gid la kopye ✓',offlineNotice:'Koneksyon entènèt la pa disponib. Ou ka kontinye li; voye mesaj ap rekòmanse lè rezo a retounen.'}
 };
 Object.assign(copy.fr,{sending:'Envoi…',delivered:'Envoyé',read:'Lu',failed:'Échec de l’envoi',retry:'Réessayer',agentTyping:'L’équipe écrit…',draftRestored:'Brouillon restauré'});
 Object.assign(copy.en,{sending:'Sending…',delivered:'Sent',read:'Read',failed:'Message failed',retry:'Retry',agentTyping:'The support team is typing…',draftRestored:'Draft restored'});
 Object.assign(copy.ht,{sending:'Ap voye…',delivered:'Voye',read:'Li',failed:'Mesaj la pa voye',retry:'Eseye ankò',agentTyping:'Ekip la ap ekri…',draftRestored:'Bouyon an retounen'});
 function init(root){
  if(root.dataset.dapReady==='1')return; root.dataset.dapReady='1';
  const panel=q('.dap-panel',root),home=q('.dap-home',root),chat=q('.dap-chat',root),msgs=q('.dap-messages',root),form=q('.dap-composer',root),wrap=q('.dap-composer-wrap',root),input=q('textarea',form),sendBtn=q('.dap-send',form),attachBtn=q('.dap-attach',form),fileInput=q('.dap-file',form);
  let lang=store.get('dap_language')||'fr',lastId=0,loaded=false,poll=null,touchY=0,dragging=false,unread=0,lockedY=0;
  if(!copy[lang])lang='fr';
  const isFloating=root.classList.contains('dap-floating');
  const mobileMq=matchMedia('(max-width:520px)');
  let hiddenOverlays=[],overlayTimer=0,baseViewportHeight=0;
  // v6.5.1 — this ran on every scroll event and wrote two custom properties on <html>
  // each time, which invalidates style for the whole document. It now runs at most once
  // per animation frame and writes only when a value actually changed.
  let lastVvTop=-1,lastVvHeight=-1,vvFrame=0;
  const viewportMetricsNow=()=>{
   vvFrame=0;
   if(!isFloating||!mobileMq.matches)return;
   const vv=window.visualViewport,h=Math.max(320,Math.round(vv?.height||window.innerHeight)),top=Math.max(0,Math.round(vv?.offsetTop||0));
   if(!baseViewportHeight||!root.classList.contains('is-open')||h>baseViewportHeight)baseViewportHeight=h;
   if(top!==lastVvTop){lastVvTop=top;root.style.setProperty('--dap-vv-top',top+'px')}
   if(h!==lastVvHeight){lastVvHeight=h;root.style.setProperty('--dap-vv-height',h+'px')}
   const keyboard=Math.max(baseViewportHeight-h,window.innerHeight-h-top);root.classList.toggle('dap-keyboard-open',root.classList.contains('is-open')&&keyboard>110);
  };
  const viewportMetrics=()=>{if(!vvFrame)vvFrame=requestAnimationFrame(viewportMetricsNow)};
  const restoreExternalOverlays=()=>{hiddenOverlays.forEach(el=>el?.classList?.remove('dap-external-hidden-by-dap'));hiddenOverlays=[];clearTimeout(overlayTimer)};
  const fixedAncestor=el=>{for(let n=el;n&&n!==document.body&&n!==document.documentElement;n=n.parentElement){if(root.contains(n))return null;const p=getComputedStyle(n).position;if(p==='fixed'||p==='sticky')return n}return null};
  const suppressExternalOverlays=()=>{
   if(!isFloating||!mobileMq.matches||!root.classList.contains('is-open'))return;
   const r=panel.getBoundingClientRect(),xs=[.68,.8,.9,.97],ys=[.55,.64,.72,.78,.84],points=[];xs.forEach(x=>ys.forEach(y=>points.push([r.left+r.width*x,r.top+r.height*y])));points.push([r.right-24,r.bottom-120],[r.right-72,r.bottom-180]);const seen=new Set();
   points.forEach(([x,y])=>{(document.elementsFromPoint?.(Math.max(0,x),Math.max(0,y))||[]).forEach(el=>{const n=fixedAncestor(el);if(!n||seen.has(n))return;seen.add(n);const cr=n.getBoundingClientRect(),cs=getComputedStyle(n),z=parseInt(cs.zIndex,10)||0;const overlaps=cr.right>r.left&&cr.left<r.right&&cr.bottom>r.top&&cr.top<r.bottom;if(overlaps&&z>=1000&&cr.width>=36&&cr.height>=28&&cr.width<window.innerWidth*.95){n.classList.add('dap-external-hidden-by-dap');hiddenOverlays.push(n)}})});
  };
  const scheduleOverlayGuard=()=>{clearTimeout(overlayTimer);suppressExternalOverlays();overlayTimer=setTimeout(suppressExternalOverlays,350)};
  const lockPage=()=>{if(!isFloating||!mobileMq.matches)return;lockedY=window.scrollY||0;document.documentElement.classList.add('dap-no-scroll','dap-assistant-open');document.body.classList.add('dap-body-locked','dap-assistant-open');document.body.style.top=`-${lockedY}px`;viewportMetrics();scheduleOverlayGuard();};
  const unlockPage=()=>{restoreExternalOverlays();baseViewportHeight=0;root.classList.remove('dap-keyboard-open');document.documentElement.classList.remove('dap-assistant-open');document.body.classList.remove('dap-assistant-open');if(!document.body.classList.contains('dap-body-locked'))return;document.documentElement.classList.remove('dap-no-scroll');document.body.classList.remove('dap-body-locked');document.body.style.top='';window.scrollTo(0,lockedY);};
  if(window.visualViewport){window.visualViewport.addEventListener('resize',()=>{viewportMetrics();scheduleOverlayGuard()},{passive:true});window.visualViewport.addEventListener('scroll',viewportMetrics,{passive:true})}
  window.addEventListener('orientationchange',()=>{baseViewportHeight=0;setTimeout(()=>{viewportMetrics();scheduleOverlayGuard()},120)},{passive:true});
  // Polling cadence: fast while the panel is open, slow while it only feeds the unread
  // badge, stopped when the tab is hidden. Keeps admin-ajax load low on shared hosting.
  const connection=navigator.connection||{};
  const POLL_FAST=connection.saveData?14000:7000,POLL_SLOW=connection.saveData?60000:30000;let pollMs=0;
  function stopPoll(){if(poll)clearInterval(poll);poll=null;pollMs=0}
  function syncPoll(){
   if(!loaded||document.hidden)return stopPoll();
   const ms=(root.classList.contains('is-open')||!isFloating)?POLL_FAST:POLL_SLOW;
   if(poll&&pollMs===ms)return;
   stopPoll();pollMs=ms;poll=setInterval(pollMessages,ms);
  }
  // Keyboard and screen-reader users had no way back: focus stayed behind the dialog on
  // open and was dropped on the page body on close, and the launcher never announced state.
  const launcher=q('.dap-launcher',root),launcherOrb=q('.dap-launcher-orb',root),launcherCopy=q('.dap-launcher-copy',root);let lastFocus=null;
  let launcherRaf=0,nudgeHide=0,nudgeStart=0,nudgeLoop=0,lastPageInteraction=Date.now();
  const nudgeSessionKey='dap_launcher_nudge_'+String(DSA2_DATA.cssVersion||'v5');
  let nudgeCount=Number(sstore.get(nudgeSessionKey+'_count')||0),nudgeSilenced=sstore.get(nudgeSessionKey+'_silent')==='1';
  const launcherCfg=DSA2_DATA.settings||{},preferredLauncherSide=launcherCfg.launcher_side==='left'?'left':'right';
  const launcherBaseBottom=Math.max(0,Math.min(400,Number(launcherCfg.launcher_bottom)||16));
  /* v6.2.1 — the orb steps aside while the page scrolls. On a phone it sits exactly
     where "Voir tout" links and card buttons pass under the thumb, so it slides off the
     edge as soon as the page moves and comes back once it has settled. Desktop keeps
     the orb still: nothing scrolls under a 64px orb on a 1200px page. */
  let launcherAway=false,launcherSettle=0,lastScrollY=window.scrollY||0;
  const setLauncherAway=away=>{
   if(!launcher||away===launcherAway)return;
   launcherAway=away;root.classList.toggle('dap-launcher-away',away);
   launcher.setAttribute('aria-hidden',away?'true':'false');launcher.tabIndex=away?-1:0;
   if(away){clearTimeout(nudgeHide);launcher.classList.remove('is-nudge')}else scheduleLauncherPosition();
  };
  let scrollFrame=0;
  const onPageScrollNow=()=>{
   scrollFrame=0;
   if(!isFloating||!launcher||root.classList.contains('is-open'))return;
   if(!mobileMq.matches){if(launcherAway)setLauncherAway(false);return}
   const y=window.scrollY||0,delta=Math.abs(y-lastScrollY);lastScrollY=y;
   if(delta<2)return;
   setLauncherAway(true);
   clearTimeout(launcherSettle);launcherSettle=setTimeout(()=>setLauncherAway(false),650);
  };
  const onPageScroll=()=>{if(!scrollFrame)scrollFrame=requestAnimationFrame(onPageScrollNow)};
  const cssPx=(el,name)=>{const n=parseFloat(getComputedStyle(el).getPropertyValue(name));return Number.isFinite(n)?n:0};
  const measureBottomDock=()=>{
   if(!mobileMq.matches)return 0;
   const h=Math.max(320,Math.round(window.visualViewport?.height||window.innerHeight)),w=Math.max(280,window.innerWidth);
   const y=Math.max(1,h-8),xs=[.08,.25,.5,.75,.92].map(v=>Math.round(w*v)),seen=new Set();let best=0;
   xs.forEach(x=>{
    const stack=document.elementsFromPoint?.(Math.max(1,Math.min(w-1,x)),y)||[];
    stack.forEach(el=>{
     const n=fixedAncestor(el);if(!n||seen.has(n))return;seen.add(n);
     const cs=getComputedStyle(n),r=n.getBoundingClientRect();
     if(cs.display==='none'||cs.visibility==='hidden'||Number(cs.opacity||1)<.08||cs.pointerEvents==='none')return;
     const touchesBottom=r.bottom>=h-18,wide=r.width>=Math.min(220,w*.55),reasonable=r.height>=42&&r.height<=180,lowerHalf=r.top>=h*.45;
     if(touchesBottom&&wide&&reasonable&&lowerHalf)best=Math.max(best,Math.ceil(h-r.top+10));
    });
   });
   root.style.setProperty('--dap-auto-dock-height',best?best+'px':'0px');
   return best;
  };
  const hideLauncherNudge=()=>{clearTimeout(nudgeHide);launcher?.classList.remove('is-nudge');scheduleLauncherPosition()};
  const applyLauncherPosition=(side,bottom)=>{
   if(!launcher)return;
   const left=side==='left';root.classList.toggle('dap-launcher-left',left);
   launcher.style.setProperty(left?'left':'right','16px','important');
   launcher.style.setProperty(left?'right':'left','auto','important');
   launcher.style.setProperty('bottom',`calc(${Math.max(0,Math.round(bottom))}px + var(--dap-safe-bottom))`,'important');
  };
  const launcherRect=()=>{
   const a=launcherOrb?.getBoundingClientRect();if(!a)return null;
   if(!launcher?.classList.contains('is-nudge')||!launcherCopy)return a;
   const b=launcherCopy.getBoundingClientRect();
   return {left:Math.min(a.left,b.left),top:Math.min(a.top,b.top),right:Math.max(a.right,b.right),bottom:Math.max(a.bottom,b.bottom),width:Math.max(a.right,b.right)-Math.min(a.left,b.left),height:Math.max(a.bottom,b.bottom)-Math.min(a.top,b.top)};
  };
  const fixedObstacleAt=(x,y)=>{
   const stack=document.elementsFromPoint?.(Math.max(1,Math.min(window.innerWidth-1,x)),Math.max(1,Math.min(window.innerHeight-1,y)))||[];
   for(const el of stack){
    const n=fixedAncestor(el);if(!n)continue;
    const cs=getComputedStyle(n),r=n.getBoundingClientRect();
    if(cs.display==='none'||cs.visibility==='hidden'||Number(cs.opacity||1)<.08||cs.pointerEvents==='none'||r.width<28||r.height<24)continue;
    return n;
   }
   return null;
  };
  const solveLauncherSide=side=>{
   const configuredDock=Math.max(cssPx(root,'--delicat-dock-height'),cssPx(document.documentElement,'--delicat-dock-height'),cssPx(document.body,'--delicat-dock-height'));
   const measuredDock=measureBottomDock();
   const dock=Math.max(configuredDock,measuredDock,mobileMq.matches?82:0);
   /* Phones: hug the dock (8px) instead of the desktop 16px — every pixel the orb
      climbs is a pixel of content it covers. */
   let bottom=(mobileMq.matches?Math.min(launcherBaseBottom,8):launcherBaseBottom)+dock;
   /* Mobile cap: at most one orb height above the dock. v6.2.0 allowed 132px, which
      parked the orb on section titles and "Voir tout" links; past this the solver
      changes side instead (placeLauncher). */
   const mobileCap=bottom+56;
   let maxBottom=mobileMq.matches?Math.max(bottom,Math.min(window.innerHeight-112,mobileCap)):Math.max(launcherBaseBottom,Math.min(520,window.innerHeight-92));
   for(let pass=0;pass<9;pass++){
    applyLauncherPosition(side,bottom);
    const r=launcherRect();if(!r)break;
    const xs=[r.left+4,(r.left+r.right)/2,r.right-4],ys=[r.top+4,(r.top+r.bottom)/2,r.bottom-4],seen=new Set(),obstacles=[];
    xs.forEach(x=>ys.forEach(y=>{const n=fixedObstacleAt(x,y);if(n&&!seen.has(n)){seen.add(n);const nr=n.getBoundingClientRect();if(nr.right>r.left&&nr.left<r.right&&nr.bottom>r.top&&nr.top<r.bottom)obstacles.push(n)}}));
    if(!obstacles.length)return {side,bottom};
    const top=Math.min(...obstacles.map(n=>n.getBoundingClientRect().top));
    const lift=Math.max(54,Math.ceil(r.bottom-top+12));
    bottom=Math.min(maxBottom,bottom+lift);
    if(bottom>=maxBottom)break;
   }
   return {side,bottom};
  };
  function placeLauncher(){
   if(!isFloating||!launcher||root.classList.contains('is-open')||document.hidden||launcherAway)return;
   const preferred=solveLauncherSide(preferredLauncherSide),other=solveLauncherSide(preferredLauncherSide==='left'?'right':'left');
   // Keep the configured side unless the opposite side saves enough vertical space to
   // avoid an obvious stack of WhatsApp, AI or sticky purchase controls.
   const chosen=(other.bottom+(mobileMq.matches?24:96)<preferred.bottom)?other:preferred;
   applyLauncherPosition(chosen.side,chosen.bottom);
   /* Tell the theme how much of the bottom edge the orb owns, so a page can reserve it. */
   const orbHeight=Math.round(launcherOrb?.getBoundingClientRect().height||0);
   root.style.setProperty('--dap-launcher-clearance',orbHeight?Math.round(chosen.bottom+orbHeight+8)+'px':'0px');
   document.documentElement.style.setProperty('--dap-launcher-clearance',orbHeight?Math.round(chosen.bottom+orbHeight+8)+'px':'0px');
  }
  function scheduleLauncherPosition(){
   if(!isFloating||!launcher)return;
   cancelAnimationFrame(launcherRaf);launcherRaf=requestAnimationFrame(placeLauncher);
  }
  const pageIsCheckout=String(DSA2_DATA.page?.page_type||root.dataset.pageType||'')==='checkout';
  const popupMax=Math.max(0,Number(launcherCfg.launcher_popup_max??3));
  const popupSuppressed=pageIsCheckout&&Number(launcherCfg.launcher_suppress_checkout||0)===1;
  const userIsBusy=()=>{const a=document.activeElement,tag=String(a?.tagName||'').toLowerCase();return root.classList.contains('is-open')||document.hidden||tag==='input'||tag==='textarea'||tag==='select'||Date.now()-lastPageInteraction<2200};
  const silenceLauncherNudges=()=>{nudgeSilenced=true;sstore.set(nudgeSessionKey+'_silent','1');clearTimeout(nudgeStart);clearTimeout(nudgeLoop);hideLauncherNudge()};
  const scheduleNextNudge=delay=>{clearTimeout(nudgeLoop);if(nudgeSilenced||popupSuppressed||!popupMax||nudgeCount>=popupMax)return;nudgeLoop=setTimeout(()=>{const shown=showLauncherNudge();scheduleNextNudge(shown?Math.max(30,Number(launcherCfg.launcher_popup_seconds)||75)*1000:5000)},delay)};
  const showLauncherNudge=()=>{
   if(!launcherCopy||!launcher||nudgeSilenced||popupSuppressed||!popupMax||nudgeCount>=popupMax)return false;
   if(userIsBusy()||launcherAway||mobileMq.matches)return false
   nudgeCount++;sstore.set(nudgeSessionKey+'_count',String(nudgeCount));
   launcher.classList.add('is-nudge');scheduleLauncherPosition();
   clearTimeout(nudgeHide);nudgeHide=setTimeout(hideLauncherNudge,Math.max(3,Number(launcherCfg.launcher_popup_visible_seconds)||6)*1000);return true;
  };
  if(isFloating&&launcher){
   scheduleLauncherPosition();
   setTimeout(scheduleLauncherPosition,260);setTimeout(scheduleLauncherPosition,1100);
   window.addEventListener('resize',scheduleLauncherPosition,{passive:true});
   window.DSA2_LAUNCHER_SCROLL=true;root.classList.remove('dap-launcher-away');
   window.addEventListener('scroll',onPageScroll,{passive:true});
   window.visualViewport?.addEventListener('resize',scheduleLauncherPosition,{passive:true});
   document.addEventListener('delicat:live-sale',scheduleLauncherPosition,{passive:true});
   if(launcherCopy&&!nudgeSilenced&&!popupSuppressed&&popupMax>nudgeCount){
    const first=Math.max(3,Number(launcherCfg.launcher_popup_delay_seconds)||10)*1000;
    nudgeStart=setTimeout(()=>{const shown=showLauncherNudge();scheduleNextNudge(shown?Math.max(30,Number(launcherCfg.launcher_popup_seconds)||75)*1000:5000)},first);
   }
   ['scroll','touchstart','pointerdown','keydown'].forEach(type=>window.addEventListener(type,()=>{lastPageInteraction=Date.now();if(type==='scroll')hideLauncherNudge()},{passive:true}));
  }
  const setOpenState=open=>{root.classList.toggle('is-open',open);panel.setAttribute('aria-hidden',open?'false':'true');launcher?.setAttribute('aria-expanded',open?'true':'false');if(open){hideLauncherNudge();lockPage()}else{unlockPage();setTimeout(scheduleLauncherPosition,80)}syncPoll()};
  const open=e=>{e?.preventDefault();e?.stopPropagation();silenceLauncherNudges();if(isFloating)lastFocus=document.activeElement;viewportMetrics();setOpenState(true);scheduleOverlayGuard();if(!loaded)load();if(conversationMode==='agent'&&!chat.hidden)markRead();if(isFloating)setTimeout(()=>q('.dap-close',root)?.focus({preventScroll:true}),60)};
  const close=e=>{e?.preventDefault();e?.stopPropagation();silenceLauncherNudges();setOpenState(false);input.blur();if(!isFloating)return;const back=(lastFocus&&document.contains(lastFocus))?lastFocus:launcher;lastFocus=null;setTimeout(()=>back?.focus?.({preventScroll:true}),60)};
  // The boot loader applies the same guard before this file exists. Both are needed: once
  // the runtime is live this handler is what receives the tap, and a flick that starts on
  // the orb must scroll the page instead of opening the panel.
  let tapX=0,tapY=0,tapAt=0,tapMoved=false,tapTracking=false;
  const tapPoint=e=>(e.touches&&e.touches[0])||e;
  launcher?.addEventListener('pointerdown',e=>{const p=tapPoint(e);tapX=p.clientX||0;tapY=p.clientY||0;tapAt=Date.now();tapMoved=false;tapTracking=true},{passive:true});
  launcher?.addEventListener('touchstart',e=>{const p=tapPoint(e);tapX=p.clientX||0;tapY=p.clientY||0;tapAt=Date.now();tapMoved=false;tapTracking=true},{passive:true});
  const trackMove=e=>{if(!tapTracking||tapMoved)return;const p=tapPoint(e);if(Math.abs((p.clientX||0)-tapX)>12||Math.abs((p.clientY||0)-tapY)>12)tapMoved=true};
  document.addEventListener('pointermove',trackMove,{passive:true,capture:true});
  document.addEventListener('touchmove',trackMove,{passive:true,capture:true});
  ['pointerup','pointercancel','touchend','touchcancel'].forEach(t=>document.addEventListener(t,()=>{tapTracking=false},{passive:true,capture:true}));
  launcher?.addEventListener('click',e=>{
   // tapAt===0 means keyboard or assistive activation — no pointer gesture to judge.
   if(tapAt&&(tapMoved||Date.now()-tapAt>700)){tapMoved=false;tapAt=0;e.preventDefault();e.stopPropagation();return}
   tapMoved=false;tapAt=0;open(e);
  });
  q('.dap-close',root)?.addEventListener('click',close);
  document.addEventListener('keydown',e=>{if(e.key==='Escape'&&root.classList.contains('is-open')){close(e);return}if(e.key!=='Tab'||!root.classList.contains('is-open'))return;const focusable=qa('button:not([disabled]),a[href],input:not([disabled]),textarea:not([disabled]),select:not([disabled]),[tabindex]:not([tabindex="-1"])',panel).filter(el=>!el.hidden&&el.offsetParent!==null);if(!focusable.length)return;const first=focusable[0],last=focusable[focusable.length-1];if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus()}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus()}});
  q('.dap-header',root).addEventListener('touchstart',e=>{touchY=e.touches[0].clientY;dragging=true},{passive:true});
  q('.dap-header',root).addEventListener('touchend',e=>{if(dragging&&e.changedTouches[0].clientY-touchY>75)close(e);dragging=false},{passive:true});
  qa('[data-lang]',root).forEach(b=>b.addEventListener('click',e=>{e.preventDefault();e.stopPropagation();qa('[data-lang]',root).forEach(x=>x.classList.remove('is-active'));b.classList.add('is-active');lang=b.dataset.lang;store.set('dap_language',lang);translate();loaded=false;msgs.innerHTML='';lastId=0;showTab('home');load()}));
  function translate(){const t=copy[lang]||copy.fr;qa('[data-i18n]',root).forEach(el=>{const k=el.dataset.i18n;if(t[k])el.textContent=t[k]});input.placeholder=conversationMode==='agent'?t.agentInput:t.input;const si=q('.dap-search input',root);if(si){si.placeholder=t.searchPh;const sb=q('.dap-search button',root);if(sb)sb.textContent=t.searchBtn}const status=q('.dap-agent-status',root),state=status?.dataset.status||'online',statusText=q('.dap-status-text',root);if(statusText)statusText.textContent=t[state]||t.online;qa('[data-nav-i18n]',root).forEach(el=>{el.textContent=t[el.dataset.navI18n]||el.textContent});qa('[data-action-i18n]',root).forEach(el=>{el.textContent=t[el.dataset.actionI18n]||el.textContent});qa('[data-lang]',root).forEach(el=>{const active=el.dataset.lang===lang;el.classList.toggle('is-active',active);el.setAttribute('aria-pressed',active?'true':'false')});root.setAttribute('lang',lang==='ht'?'ht':lang);updateConversationHeader();updateNetworkState();applySoundIcon()}
  const networkBar=q('.dap-network-status',root),networkText=q('[data-network-text]',root);
  function updateNetworkState(){const offline=navigator.onLine===false;if(networkBar){networkBar.hidden=!offline;if(networkText)networkText.textContent=(copy[lang]||copy.fr).offlineNotice}root.classList.toggle('dap-offline',offline);if(offline)sendBtn.disabled=true;else{sendBtn.disabled=!!root.classList.contains('dap-loading')||!input.value.trim();if(root.classList.contains('is-open')&&!loaded)load()}}
  window.addEventListener('online',()=>{updateNetworkState();retryPending(true)},{passive:true});window.addEventListener('offline',updateNetworkState,{passive:true});
  function keepWindowPosition(fn){const y=window.scrollY||0;fn();requestAnimationFrame(()=>{if(!isFloating||!root.classList.contains('is-open'))window.scrollTo(0,y)})}
  const chatScroller=msgs;
  function scrollChatToBottom(){requestAnimationFrame(()=>{chatScroller.scrollTop=chatScroller.scrollHeight;const j=q('.dap-jump-latest',root);if(j)j.hidden=true})}
  function nearBottom(){return chatScroller.scrollHeight-chatScroller.scrollTop-chatScroller.clientHeight<140}
  const jumpLatest=q('.dap-jump-latest',root);let jumpFrame=0;
 function updateJumpNow(){jumpFrame=0;if(!jumpLatest)return;jumpLatest.hidden=nearBottom()||chat.hidden}
 function updateJump(){if(!jumpFrame)jumpFrame=requestAnimationFrame(updateJumpNow)}chatScroller.addEventListener('scroll',updateJump,{passive:true});jumpLatest?.addEventListener('click',e=>{e.preventDefault();scrollChatToBottom();setTimeout(updateJump,80)});
  let conversationMode='bot',agentReadMarker=0,pendingSend=null,typingStopTimer=0,lastCustomerTyping=0;
  const draftKey='dap_customer_draft_'+String(DSA2_DATA.userId||'guest');
  const saveDraft=()=>{const value=String(input.value||'').slice(0,1500);if(value)sstore.set(draftKey,value);else sstore.del(draftKey)};
  const restoreDraft=()=>{const value=sstore.get(draftKey);if(value&&!input.value){input.value=String(value).slice(0,1500);resize();sendBtn.disabled=navigator.onLine===false;input.setAttribute('data-draft-restored','1')}};
  function publishTyping(active){if(conversationMode!=='agent'||!loaded||navigator.onLine===false)return;post({action:'dsa2_typing',typing:active?1:0}).then(j=>{if(conversationMode==='agent')typing(!!j.data.agent_typing)}).catch(()=>{})}
  function updateConversationHeader(){const t=copy[lang]||copy.fr,title=q('.dap-agent-thread-title strong',root),sub=q('.dap-agent-thread-title span span',root),icon=q('[data-thread-icon]',root);if(conversationMode==='agent'){if(title)title.textContent=t.agentTitle;if(sub){const eta=String(DSA2_DATA.settings?.agent_eta||'').trim();sub.textContent=eta?`${t.agentSubtitle} · ${eta}`:t.agentSubtitle}if(icon)icon.textContent='💬'}else{if(title)title.textContent=t.botTitle;if(sub)sub.textContent=t.botSubtitle;if(icon)icon.textContent='✨'}}
  function showTab(tab,e){e?.preventDefault();e?.stopPropagation();keepWindowPosition(()=>{const isConversation=tab==='bot'||tab==='agent';if(isConversation)conversationMode=tab;root.classList.toggle('dap-agent-view',tab==='agent');root.classList.toggle('dap-bot-view',tab==='bot');root.classList.toggle('dap-home-view',!isConversation);home.hidden=isConversation;chat.hidden=!isConversation;wrap.hidden=false;wrap.removeAttribute('hidden');qa('.dap-nav button',root).forEach(b=>b.classList.remove('is-active'));qa('[data-tab]',root).forEach(b=>b.classList.toggle('is-active',b.dataset.tab===tab));updateConversationHeader();input.placeholder=conversationMode==='agent'?(copy[lang]||copy.fr).agentInput:(copy[lang]||copy.fr).input;updateEndChat();if(isConversation){load();applyConversationView();scrollChatToBottom();if(tab==='agent'&&root.classList.contains('is-open'))markRead()}})}
  qa('[data-tab]',root).forEach(b=>b.addEventListener('click',e=>showTab(b.dataset.tab,e)));
  qa('[data-action]',root).forEach(b=>b.addEventListener('click',e=>{e.preventDefault();e.stopPropagation();if(root.classList.contains('dap-loading'))return;const a=b.dataset.action;if(a==='whatsapp'){const u=DSA2_DATA.settings?.whatsapp_url;if(u){openWhatsApp(u);return}return quick('agent')}quick(a)}));
  // Products / Guides / WhatsApp are one-shot actions, not tabs. They used to latch
  // is-active and stay lit next to whichever tab showTab() had just activated.
  qa('.dap-nav button[data-tab]',root).forEach(b=>b.addEventListener('click',()=>{qa('.dap-nav button',root).forEach(x=>x.classList.remove('is-active'));b.classList.add('is-active')}));
  q('.dap-search',root)?.addEventListener('submit',e=>{e.preventDefault();e.stopPropagation();const inp=q('.dap-search input',root),value=(inp?.value||'').trim();if(!value)return;inp.value='';inp.blur();send(value,'product')});
  form.addEventListener('submit',e=>{e.preventDefault();e.stopPropagation();const value=input.value.trim();if(!value)return;if(pendingSend&&pendingSend.value===value){retryPending(false);return}if(pendingSend){pendingSend.element?.remove();pendingSend=null}input.value='';saveDraft();resize();clearTimeout(typingStopTimer);publishTyping(false);if(chat.hidden)showTab(conversationMode==='agent'?'agent':'bot');send(value)});
  input.addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey&&!e.isComposing&&!mobileMq.matches){e.preventDefault();if(input.value.trim())form.requestSubmit()}});
  input.addEventListener('input',()=>{resize();saveDraft();sendBtn.disabled=navigator.onLine===false||!input.value.trim();if(conversationMode==='agent'){const now=Date.now();if(now-lastCustomerTyping>6500){lastCustomerTyping=now;publishTyping(true)}clearTimeout(typingStopTimer);typingStopTimer=setTimeout(()=>publishTyping(false),2200)}});
  function resize(){input.style.height='auto';input.style.height=Math.min(input.scrollHeight,104)+'px'}
  const textOf=el=>{if(!el)return '';const clone=el.cloneNode?.(true)||el;qa('.screen-reader-text,.screen-reader-response,[aria-hidden="true"]',clone).forEach(n=>n.remove());return String(clone.textContent||'').replace(/\s+/g,' ').trim()};
  function currentPageContext(){
   const base=DSA2_DATA.page||{},product=base.product||{},productRoot=q('form.variations_form,form.cart,.summary,.product')||document,ctx={page_type:base.page_type||root.dataset.pageType||'page',product_id:Number(base.product_id||product.id||0),title:product.name||base.title||document.title,url:product.url||base.url||location.href,price:product.price||'',is_pin:!!product.is_pin};
   const variationInput=q('input.variation_id,input[name="variation_id"]');ctx.variation_id=Number(variationInput?.value||0);
   const attrs={};qa('select[name^="attribute_"]:not([disabled])',productRoot).slice(0,8).forEach(sel=>{const val=sel.value||'',label=sel.options?.[sel.selectedIndex]?.text||val;if(val)attrs[String(sel.name).replace(/^attribute_/,'')]=String(label).slice(0,80)});ctx.attributes=attrs;
   const selected=qa('.selected,.is-selected,[aria-checked="true"],[data-selected="true"]',productRoot).filter(el=>!root.contains(el)).slice(0,8).map(textOf).filter(Boolean);
   ctx.variation=selected.join(' · ').slice(0,220)||Object.values(attrs).join(' · ').slice(0,220);
   const plan=q('[data-plan].selected,[data-plan].is-selected,[data-selected="true"][data-plan],input[name*=plan]:checked',productRoot)?.closest?.('[data-plan],label,.swatch-card,.plan-card')||q('.plan-selected,.selected-plan',productRoot);ctx.plan=textOf(plan).slice(0,140);
   const priceEl=q('.woocommerce-variation-price .price,.delicat-total .amount,.floating-total .amount,.summary .price,.product .price',productRoot)||q('.woocommerce-variation-price .price,.delicat-total .amount,.floating-total .amount,.summary .price,.product .price');ctx.price=textOf(priceEl).slice(0,100)||ctx.price;
   const player=q('input[name*="player" i],input[id*="player" i],input[name*="uid" i],input[id*="uid" i]',productRoot);ctx.player_id_field=!!player;ctx.player_id_filled=!!String(player?.value||'').trim();
   return ctx;
  }
  const contextJSON=()=>JSON.stringify(currentPageContext());
  function syncContextBanner(){const ctx=currentPageContext(),name=q('[data-context-product]',root),selection=q('[data-context-variation]',root);if(name&&ctx.title)name.textContent=ctx.title;if(selection)selection.textContent=[ctx.plan,ctx.variation,ctx.price].filter(Boolean).join(' · ').slice(0,240)||ctx.price||''}
  syncContextBanner();let contextSync=0;document.addEventListener('change',e=>{if(root.contains(e.target))return;clearTimeout(contextSync);contextSync=setTimeout(syncContextBanner,80)},true);document.addEventListener('click',e=>{if(root.contains(e.target))return;clearTimeout(contextSync);contextSync=setTimeout(syncContextBanner,140)},true);
  async function post(data,retried,attempt=0){
   if(navigator.onLine===false){const offlineErr=new Error((copy[lang]||copy.fr).offlineNotice);offlineErr.server=true;throw offlineErr}
   const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),15000);
   try{
    const body=new URLSearchParams({nonce:DSA2_DATA.nonce,language:lang,...data,_t:String(Date.now())});
    const r=await fetch(ajaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8','X-Requested-With':'XMLHttpRequest'},credentials:'same-origin',cache:'no-store',redirect:'follow',signal:controller.signal,body});
    let j=null;try{j=await r.json()}catch(e){}
    const nonceBad=(j&&!j.success&&j.data&&j.data.code==='bad_nonce')||(!j&&(r.status===401||r.status===403));
    if(nonceBad&&!retried&&await refreshNonce())return post(data,true);
    if(!r.ok||!j||!j.success){const err=new Error(j?.data?.message||'Request failed');err.server=!!(j&&j.data&&j.data.message);err.status=r.status;throw err}
    return j;
   }catch(err){const safe=['dsa2_ping','dsa2_load_messages','dsa2_unread','dsa2_mark_read'];if(safe.includes(String(data.action||''))&&attempt<2&&(err?.name==='AbortError'||!err?.server||Number(err?.status||0)>=500)){await new Promise(resolve=>setTimeout(resolve,650*Math.pow(2,attempt)));return post(data,retried,attempt+1)}throw err
   }finally{clearTimeout(timer)}
  }
  async function refreshNonce(){
   try{
    const nr=await fetch(ajaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8','X-Requested-With':'XMLHttpRequest'},credentials:'same-origin',cache:'no-store',body:new URLSearchParams({action:'dsa2_get_nonce',_t:String(Date.now())})});
    const nj=await nr.json().catch(()=>null);
    if(nr.ok&&nj&&nj.success&&nj.data&&nj.data.nonce){DSA2_DATA.nonce=nj.data.nonce;return true}
   }catch(e){}
   return false;
  }
  function waMessage(){const h=new Date().getHours();const g=(h>=18||h<5)?{fr:'Bonsoir',en:'Good evening',ht:'Bonswa'}:{fr:'Bonjour',en:'Hello',ht:'Bonjou'};const t={fr:"J'aimerais parler \u00e0 un agent Delicat Store disponible \u00e0 propos de : ",en:'I would like to talk to an available Delicat Store agent about: ',ht:'Mwen ta renmen pale ak yon ajan Delicat Store ki disponib sou: '};const c=currentPageContext(),about=c.product_id?(' '+c.title+(c.plan?' · '+c.plan:'')+(c.variation?' · '+c.variation:'')+' — '):'';return (g[lang]||g.fr)+' \u{1F44B} '+(t[lang]||t.fr)+about+window.location.href}
  function openWhatsApp(base){if(!base)return;const u=safeUrl(base);if(u==='#')return;const sep=u.includes('?')?'&':'?';window.open(u+sep+'text='+encodeURIComponent(waMessage()),'_blank','noopener')}
  // The banner lives inside .dap-messages. On the Home tab that pane is hidden, so the
  // banner was invisible while load() had already reset `loaded` for a retry — the
  // customer just saw nothing happen. Stay silent there; the retry runs on tab open.
  function error(msg){if(chat.hidden)return;q('.dap-error',root)?.remove();const el=document.createElement('div');el.className='dap-error';const text=document.createElement('span');text.textContent=msg||(copy[lang]||copy.fr).error;const retry=document.createElement('button');retry.type='button';retry.textContent=lang==='en'?'Retry':(lang==='ht'?'Eseye ankò':'Réessayer');retry.addEventListener('click',()=>{el.remove();loaded=false;load()});el.append(text,retry);msgs.appendChild(el);scrollChatToBottom()}
  async function load(){if(loaded)return;loaded=true;try{const j=await post({action:'dsa2_load_messages',after_id:lastId,page_product:DSA2_DATA.page?.product_id||0,page_title:DSA2_DATA.page?.title||'',context:contextJSON()});if(Object.prototype.hasOwnProperty.call(j.data,'unread_count'))unread=+j.data.unread_count||0;updateBadge();syncDeliveryReceipts(j.data.agent_read_id);render(j.data.messages,true);if(conversationMode==='agent')typing(!!j.data.agent_typing);syncPoll()}catch(e){loaded=false;if(root.classList.contains('is-open')||!isFloating)error(e&&e.server?e.message:null)}}
  // The heartbeat asks dsa2_ping first: two indexed reads and ~40 bytes back. The full
  // thread is only requested once the server reports a message we do not have, so an
  // idle conversation costs the server almost nothing however long the tab stays open.
  async function pollMessages(){
   try{
    const p=await post({action:'dsa2_ping'});
    const d=(p&&p.data)||{};
    const newest=+d.last_id||0;
    syncDeliveryReceipts(d.agent_read_id);
    if(conversationMode==='agent'&&!root.classList.contains('dap-loading'))typing(!!d.agent_typing);
    if(newest<=lastId){if(!canReadAgent()){unread=+d.unread||0;updateBadge()}else if(+d.unread)markRead();return}
    const j=await post({action:'dsa2_load_messages',after_id:lastId,context:contextJSON()});
    const incoming=j.data.messages||[];
    render(incoming);
    if(!canReadAgent()){unread=+d.unread||0;updateBadge()}
    else markRead();
   }catch(e){}
  }
  function typing(show){q('.dap-typing-msg',msgs)?.remove();if(!show)return;const el=document.createElement('div');el.className='dap-msg assistant dap-typing-msg';el.dataset.channel=conversationMode;el.dataset.sender='assistant';el.innerHTML='<span class="dap-typing"><i></i><i></i><i></i></span>';const stick=nearBottom();msgs.appendChild(el);applyConversationView();if(stick)scrollChatToBottom()}
  async function send(value,intent,retry){if(navigator.onLine===false){input.value=value;saveDraft();updateNetworkState();error((copy[lang]||copy.fr).offlineNotice);return}showTab(conversationMode);const channel=intent==='agent'||(!intent&&conversationMode==='agent')?'agent':'bot';const clientId=retry?.clientId||(window.crypto?.randomUUID?.()||('dap-'+Date.now()+'-'+Math.random().toString(36).slice(2)));const tmp=retry?.element||append({sender:'customer',channel,type:'text',body:value,time:'',delivery:'sending',meta:{client_message_id:clientId}});applyDelivery(tmp,'sending');applyConversationView();scrollChatToBottom();typing(channel==='bot');setLoading(true);pendingSend={value,intent:intent||(conversationMode==='agent'?'agent':'bot'),clientId,element:tmp};try{const j=await post({action:'dsa2_send_message',message:value,after_id:lastId,intent:pendingSend.intent,client_message_id:clientId,context:contextJSON()});tmp&&tmp.remove();if(input.value.trim()===value){input.value='';resize()}saveDraft();pendingSend=null;syncDeliveryReceipts(j.data.agent_read_id);render(j.data.messages,true)}catch(e){typing(false);applyDelivery(tmp,'failed');input.value=value;saveDraft();resize();error(e&&e.server?e.message:null)}finally{setLoading(false)}}
  function retryPending(automatic){if(!pendingSend||navigator.onLine===false||root.classList.contains('dap-loading'))return;if(automatic&&input.value.trim()&&input.value.trim()!==pendingSend.value)return;const item=pendingSend;input.value='';saveDraft();resize();q('.dap-error',root)?.remove();send(item.value,item.intent,{clientId:item.clientId,element:item.element})}
  // "Parler à un agent" produces an agent_card, which the bot view filters out — so the
  // old showTab('bot') escalated the thread server-side and then hid every trace of it
  // from the customer. Agent requests go to the Agent view; ending the chat stays put.
  async function quick(action){if(navigator.onLine===false){updateNetworkState();error((copy[lang]||copy.fr).offlineNotice);return}const target=action==='agent'?'agent':(action==='end_chat'?conversationMode:'bot');showTab(target);typing(true);setLoading(true);try{const j=await post({action:'dsa2_quick_action',quick_action:action,after_id:lastId,context:contextJSON()});render(j.data.messages,true)}catch(e){typing(false);error(e&&e.server?e.message:null)}finally{setLoading(false)}}
  async function upload(file,retried){
    if(!file)return;
    if(file.size>5*1024*1024){error((copy[lang]||copy.fr).tooBig);return}
    if(!retried){showTab(conversationMode);setLoading(true);typing(true)}
    try{
      const fd=new FormData();fd.append('action','dsa2_upload');fd.append('nonce',DSA2_DATA.nonce);fd.append('language',lang);fd.append('after_id',lastId);fd.append('intent',conversationMode);fd.append('context',contextJSON());fd.append('file',file);
      const r=await fetch(ajaxUrl,{method:'POST',credentials:'include',cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd});
      const j=await r.json().catch(()=>null);
      if(!retried&&j&&!j.success&&j.data&&j.data.code==='bad_nonce'&&await refreshNonce())return upload(file,true);
      if(!r.ok||!j||!j.success)throw new Error(j?.data?.message||'');
      render(j.data.messages,true);
    }catch(e){typing(false);error(e&&e.message?e.message:null)}finally{if(!retried){setLoading(false);fileInput.value=''}}
  }
  attachBtn?.addEventListener('click',e=>{e.preventDefault();if(chat.hidden)showTab(conversationMode==='agent'?'agent':'bot');fileInput?.click()});
  fileInput?.addEventListener('change',()=>upload(fileInput.files&&fileInput.files[0]));
  async function findOrder(num,proof,btn){typing(true);setLoading(true);try{const j=await post({action:'dsa2_find_order',order:num,proof,after_id:lastId});render(j.data.messages,true)}catch(e){typing(false);error(e&&e.server?e.message:null)}finally{setLoading(false);if(btn)btn.disabled=false}}
  async function submitRating(v){typing(true);setLoading(true);try{const j=await post({action:'dsa2_rate',rating:v,after_id:lastId});render(j.data.messages,true)}catch(e){typing(false);error(e&&e.server?e.message:null)}finally{setLoading(false)}}
  async function submitContact(name,email,btn){typing(true);setLoading(true);try{const j=await post({action:'dsa2_set_contact',name,email,after_id:lastId});render(j.data.messages,true)}catch(e){typing(false);if(btn)btn.disabled=false;error(e&&e.server?e.message:null)}finally{setLoading(false)}}
  function setLoading(v){root.classList.toggle('dap-loading',v);sendBtn.disabled=navigator.onLine===false||v||!input.value.trim()}
  // Senders written by the server are customer / assistant / agent. This looked for "user" and
  // "admin", which never exist, so the End Chat button stayed hidden unless a human had replied.
  function updateEndChat(){const tool=q('.dap-chat-tools',root);if(!tool)return;const hasConversation=!!msgs.querySelector('[data-channel="agent"][data-sender="customer"],[data-channel="agent"][data-sender="agent"]');tool.hidden=chat.hidden||conversationMode!=='agent'||!hasConversation;}
  // Messages used to be appended one by one, so a thread of 100 forced 100 layout
  // passes on open — visible as a stutter on a low-end phone. They now land in a
  // fragment and touch the document once.
  // Order-status receipts written by releases before 6.2.0 are dropped here too, so a
  // browser still running a cached bundle against a fresh server also sees a clean thread.
  const isOrderReceipt=m=>m&&m.sender==='system'&&m.type==='system'&&m.meta&&m.meta.order;
  function render(items,force){typing(false);const stick=force||nearBottom();const frag=document.createDocumentFragment();let added=0,incoming=0;(items||[]).forEach(m=>{if(m.id<=lastId)return;lastId=m.id;if(isOrderReceipt(m))return;const el=build(m);if(!el)return;frag.appendChild(el);added++;if(m.sender==='agent')incoming++});if(added)msgs.appendChild(frag);if(incoming&&!force)chime();syncDeliveryReceipts(agentReadMarker);applyConversationView();if(canReadAgent())markRead();if(added&&stick)scrollChatToBottom();updateEndChat()}
  // v4.5.0 deleted these two while rewriting render(). Every caller — open(), render(),
  // pollMessages() and the boot handoff — then threw a ReferenceError, which load()/send()
  // caught and reported as "Connexion momentanément indisponible" even though the request
  // had succeeded. The unread badge and read receipts stopped working for the same reason.
  let previousUnread=unread;function updateBadge(){const increased=unread>previousUnread;qa('[data-agent-unread]',root).forEach(b=>{b.hidden=unread<1;b.textContent=unread>9?'9+':String(unread);if(increased){b.classList.remove('dap-new-unread');void b.offsetWidth;b.classList.add('dap-new-unread');setTimeout(()=>b.classList.remove('dap-new-unread'),1100)}});previousUnread=unread}
  let lastReadSent=0;
  function canReadAgent(){return conversationMode==='agent'&&!chat.hidden&&(!isFloating||root.classList.contains('is-open'))}
  function agentReadId(){return qa('[data-channel="agent"][data-sender="agent"]',msgs).reduce((max,el)=>Math.max(max,+el.dataset.messageId||0),0)}
  async function markRead(){const id=agentReadId();if(!canReadAgent()||!id||id===lastReadSent)return;lastReadSent=id;try{const j=await post({action:'dsa2_mark_read',last_id:id});unread=+j.data.unread_count||0;updateBadge()}catch(e){lastReadSent=0}}

  // Incoming-message sound. Synthesised with WebAudio rather than shipped as an .mp3: no
  // extra request, nothing for a cache layer to serve stale or 404, and it works offline.
  // Browsers refuse audio before the visitor has interacted, so the context is created on
  // the first real gesture and simply stays silent until then.
  let audioCtx=null,soundArmed=false;
  const soundOn=()=>store.get('dap_sound')!=='off';
  const armAudio=()=>{
   if(soundArmed)return;soundArmed=true;
   try{const Ctx=window.AudioContext||window.webkitAudioContext;if(Ctx)audioCtx=new Ctx()}catch(e){audioCtx=null}
  };
  ['pointerdown','keydown','touchstart'].forEach(t=>document.addEventListener(t,armAudio,{once:true,passive:true,capture:true}));
  function chime(){
   if(!soundOn()||document.hidden)return;
   armAudio();
   if(!audioCtx)return;
   try{
    if(audioCtx.state==='suspended')audioCtx.resume();
    const now=audioCtx.currentTime;
    // Two short notes, a fifth apart — reads as a notification, not an alarm.
    [[880,0],[1318.5,0.13]].forEach(([hz,at])=>{
     const osc=audioCtx.createOscillator(),gain=audioCtx.createGain();
     osc.type='sine';osc.frequency.setValueAtTime(hz,now+at);
     gain.gain.setValueAtTime(0.0001,now+at);
     gain.gain.exponentialRampToValueAtTime(0.16,now+at+0.02);
     gain.gain.exponentialRampToValueAtTime(0.0001,now+at+0.22);
     osc.connect(gain);gain.connect(audioCtx.destination);
     osc.start(now+at);osc.stop(now+at+0.24);
    });
   }catch(e){}
   if(navigator.vibrate&&mobileMq.matches){try{navigator.vibrate(18)}catch(e){}}
  }
  const soundBtn=q('.dap-sound-toggle',root);
  function applySoundIcon(){if(soundBtn){const on=soundOn();soundBtn.textContent=on?'\u{1F514}':'\u{1F515}';soundBtn.setAttribute('aria-pressed',on?'true':'false');soundBtn.setAttribute('aria-label',on?(lang==='en'?'Mute notification sound':lang==='ht'?'Fèmen son an':'Couper le son des notifications'):(lang==='en'?'Enable notification sound':lang==='ht'?'Ouvè son an':'Activer le son des notifications'))}}
  soundBtn?.addEventListener('click',e=>{e.preventDefault();e.stopPropagation();const next=soundOn()?'off':'on';store.set('dap_sound',next);applySoundIcon();if(next==='on'){armAudio();chime()}});
  applySoundIcon();

  function applyConversationView(){
   const nodes=[...msgs.children].filter(el=>el.matches('.dap-msg,.dap-card'));
   let candidates=[];
   nodes.forEach(el=>{
    if(el.dataset.channel===conversationMode)candidates.push(el);
   });
   // Keep the bot workspace lightweight: old product cards and years of automated history
   // made the panel open halfway through stale content and inflated the badge to 99.
   if(conversationMode==='bot'&&candidates.length>24)candidates=candidates.slice(-24);
   const allowed=new Set(candidates),shown=[];
   nodes.forEach(el=>{const show=allowed.has(el);el.classList.toggle('dap-history-hidden',!show);el.classList.remove('dap-group-first','dap-group-middle','dap-group-last','dap-group-only');if(show)shown.push(el)});
   shown.forEach((el,i)=>{const prev=shown[i-1],next=shown[i+1],samePrev=prev&&prev.dataset.sender===el.dataset.sender,sameNext=next&&next.dataset.sender===el.dataset.sender;el.classList.add(!samePrev&&!sameNext?'dap-group-only':(!samePrev?'dap-group-first':(!sameNext?'dap-group-last':'dap-group-middle')))});
   const visible=shown.length,empty=q('.dap-agent-empty',root),count=q('.dap-thread-count',root);
   if(empty){empty.hidden=visible>0;const strong=q('strong',empty),para=q('p',empty),t=copy[lang]||copy.fr;if(strong)strong.textContent=conversationMode==='agent'?t.emptyConversation:t.botTitle;if(para)para.textContent=conversationMode==='agent'?t.emptyConversationSub:t.botSubtitle}
   if(count){count.hidden=conversationMode!=='agent'||visible<1;count.textContent=visible>99?'99+':String(visible)}
   updateJump();
  }
  function applyDelivery(element,state){if(!element||element.dataset.sender!=='customer')return;const t=copy[lang]||copy.fr;let badge=q('.dap-msg-delivery',element);if(!badge){badge=document.createElement('span');badge.className='dap-msg-delivery';badge.setAttribute('aria-live','polite');element.appendChild(badge)}badge.dataset.deliveryState=state;badge.classList.toggle('is-read',state==='read');badge.classList.toggle('is-failed',state==='failed');badge.replaceChildren();const label=document.createElement('span');label.textContent=(state==='read'?'✓✓ ':state==='delivered'?'✓ ':state==='sending'?'◷ ':'⚠ ')+(t[state]||state);badge.appendChild(label);if(state==='failed'){const button=document.createElement('button');button.type='button';button.dataset.retryMessage='1';button.textContent=t.retry;badge.appendChild(button)}}
  function syncDeliveryReceipts(marker){agentReadMarker=Math.max(agentReadMarker,Number(marker||0));qa('[data-channel="agent"][data-sender="customer"]',msgs).forEach(el=>{const id=Number(el.dataset.messageId||0);if(id>0)applyDelivery(el,id<=agentReadMarker?'read':'delivered')})}
  function build(m){const el=document.createElement('div'),sender=['customer','assistant','agent','system'].includes(m.sender)?m.sender:'assistant';const agentType=['agent_card','contact_card','rate_card','rating'].includes(m.type);const channel=['bot','agent','system'].includes(m.channel)?m.channel:(sender==='agent'||agentType?'agent':'bot');el.dataset.messageId=String(m.id||0);el.dataset.sender=sender;el.dataset.type=String(m.type||'');el.dataset.channel=channel;if(m.meta?.client_message_id)el.dataset.clientMessageId=String(m.meta.client_message_id);const t=copy[lang]||copy.fr,label=sender==='customer'?t.clientLabel:(sender==='agent'?(m.sender_name||t.agentLabel):'');const labelHtml=label?'<span class="dap-sender-label">'+esc(label)+'</span>':'';if(m.type==='image'){el.className='dap-msg '+sender+' dap-msg-img';el.innerHTML=labelHtml+'<a href="'+esc(safeUrl(m.meta?.url))+'" target="_blank" rel="noopener"><img src="'+esc(safeUrl(m.meta?.url))+'" alt="" loading="lazy"></a><time>'+esc(m.time||'')+'</time>';if(sender==='customer')applyDelivery(el,m.delivery||(channel==='agent'&&Number(m.id||0)<=agentReadMarker?'read':'delivered'));return el}if(m.type==='text'||m.type==='rating'){el.className='dap-msg '+sender;el.innerHTML=labelHtml+'<p>'+esc(m.type==='rating'?(m.meta?.title||m.body):m.body)+'</p><time>'+esc(m.time||'')+'</time>';if(sender==='customer')applyDelivery(el,m.delivery||(channel==='agent'&&Number(m.id||0)<=agentReadMarker?'read':'delivered'))}else{el.className='dap-card';el.innerHTML=card(m)}return el}
  function append(m){const el=build(m);if(el)msgs.appendChild(el);return el}
  function chips(list){if(!list||!list.length)return '';return '<div class="dap-chips">'+list.map(b=>`<button type="button" data-faq-action="${esc(String(b.action||''))}" data-faq-value="${esc(String(b.value||''))}">${esc(b.label||'')}</button>`).join('')+'</div>'}
  function actions(list){return (list||[]).map(b=>{const a=String(b.action||''),v=String(b.value||''),label=esc(b.label||'Ouvrir');
   if(a==='url'){const mapped=DSA2_DATA.settings?.urls?.[v]||v;return `<a href="${esc(safeUrl(mapped))}">${label}</a>`}
   if(a==='whatsapp'){const wa=DSA2_DATA.settings?.whatsapp_url||v;return `<button type="button" data-faq-action="whatsapp" data-faq-value="${esc(wa)}">${WA_SVG}${label}</button>`}
   return `<button type="button" data-faq-action="${esc(a)}" data-faq-value="${esc(v)}">${label}</button>`}).join('')}
  // Guides are the thing customers pass around on WhatsApp, so they get a share button:
  // the numbered steps as plain text plus a link that reopens this guide for the recipient.
  function shareBtn(s){if(!s||!s.text)return '';return `<button type="button" class="dap-share-btn" data-share-text="${esc(s.text)}" data-share-url="${esc(safeUrl(s.url))}" data-share-title="${esc(s.title||'')}">\u{1F4E4} ${esc(s.label||'Partager')}</button>`}
  async function shareGuide(btn){
   const title=btn.dataset.shareTitle||'',text=btn.dataset.shareText||'',url=btn.dataset.shareUrl||window.location.href;
   if(navigator.share){
    try{await navigator.share({title,text,url});return}
    catch(err){if(err&&err.name==='AbortError')return}
   }
   // No native sheet (desktop, older browsers): WhatsApp's own contact picker — wa.me with
   // no number — is where these get sent anyway.
   const wa='https://wa.me/?text='+encodeURIComponent(text+'\n'+url);
   const win=window.open(wa,'_blank','noopener');
   if(win)return;
   try{await navigator.clipboard.writeText(text+'\n'+url);toast((copy[lang]||copy.fr).shareCopied)}
   catch(e){error()}
  }
  function toast(msg){q('.dap-error',root)?.remove();const el=document.createElement('div');el.className='dap-error dap-ok';el.textContent=msg;msgs.appendChild(el);scrollChatToBottom();setTimeout(()=>el.remove(),2600)}
  function card(m){const x=m.meta||{};
   if(m.type==='account_card')return `<span class="dap-status">${x.status==='secure'?'\u{1F512} '+(lang==='en'?'Secure':lang==='ht'?'Sekirize':'S\u00e9curis\u00e9'):(lang==='en'?'Account required':lang==='ht'?'Kont neses\u00e8':'Compte requis')}</span><h4>${esc(x.title)}</h4><div class="dap-value">${esc(x.value)}</div><a href="${esc(safeUrl(x.url))}">${esc(x.button)}</a>`;
   // Two payloads share the type "order_card": the guest lookup form (has orderLabel) and the order summary.
   if(m.type==='order_card'&&x.orderLabel)return `<h4>\u{1F9FE} ${esc(x.title)}</h4>${x.intro?`<p>${esc(x.intro)}</p>`:''}<div class="dap-contact-form"><input type="text" maxlength="20" inputmode="numeric" placeholder="${esc(x.orderLabel)}" aria-label="${esc(x.orderLabel)}" data-order-number><input type="text" maxlength="190" placeholder="${esc(x.proofLabel)}" aria-label="${esc(x.proofLabel)}" data-order-proof><button type="button" data-order-submit>${esc(x.button)}</button></div>${x.privacy?`<div class="dap-help-line">\u{1F512} ${esc(x.privacy)}</div>`:''}<div class="dap-card-actions">${actions(x.buttons)}</div>`;
   if(m.type==='order_card')return `<h4>\u{1F9FE} ${esc(x.title)}</h4><p>${x.empty?esc(x.description):`${esc(x.status)} \u00b7 ${esc(x.total)}<br>${esc(x.date)}`}</p><a href="${esc(safeUrl(x.url))}">${esc(x.button||'Ouvrir')}</a>${chips(x.chips)}`;
   if(m.type==='agent_card')return `<h4>\u{1F4AC} ${esc(x.title)}</h4>${x.eta?`<span class="dap-status">⏱ ${esc(x.eta)}</span>`:''}<p>${esc(x.description)}</p>${x.order?`<div class="dap-order-summary"><strong>${esc(x.order.title||'')}</strong><span>${esc([x.order.status,x.order.total,x.order.date].filter(Boolean).join(' · '))}</span>${x.order.url?`<a href="${esc(safeUrl(x.order.url))}">${lang==='en'?'View order':lang==='ht'?'Gade kòmand lan':'Voir la commande'}</a>`:''}</div>`:''}${x.hint?`<div class="dap-tip">\u{1F4A1} ${esc(x.hint)}</div>`:''}${x.whatsapp?`<div class="dap-card-actions"><a target="_blank" rel="noopener" href="${esc(safeUrl(x.whatsapp))}">${WA_SVG}WhatsApp</a></div>`:''}`;
   if(m.type==='guide_card')return `<h4>${esc(x.icon||'\u{1F4D8}')} ${esc(x.title)}</h4>${x.intro?`<p>${esc(x.intro)}</p>`:''}<ol class="dap-steps">${(x.steps||[]).map((st,i)=>{const im=(x.images||[])[i];return `<li>${esc(st)}${im?`<img class="dap-step-img" src="${esc(safeUrl(im))}" alt="" loading="lazy">`:''}</li>`}).join('')}</ol>${x.tip?`<div class="dap-tip">\u{1F4A1} ${esc(x.tip)}</div>`:''}${x.help?`<div class="dap-help-line">${esc(x.help)}</div>`:''}<div class="dap-card-actions">${actions(x.buttons)}${shareBtn(x.share)}</div>${chips(x.chips)}`;
   if(m.type==='guide_menu')return `<h4>\u{1F4DA} ${esc(x.title)}</h4>${x.intro?`<p>${esc(x.intro)}</p>`:''}<div class="dap-guide-list">${(x.items||[]).map(i=>`<button type="button" data-faq-action="guide" data-faq-value="${esc(i.id)}"><span>${esc(i.icon||'\u{1F4D8}')}</span>${esc(i.title)}</button>`).join('')}</div><div class="dap-card-actions">${actions(x.buttons)}</div>`;
   if(m.type==='contact_card')return `<h4>\u{1F464} ${esc(x.title)}</h4>${x.intro?`<p>${esc(x.intro)}</p>`:''}<div class="dap-contact-form"><input type="text" maxlength="100" autocomplete="name" placeholder="${esc(x.nameLabel)}" aria-label="${esc(x.nameLabel)}" data-contact-name><input type="text" maxlength="190" autocomplete="email" placeholder="${esc(x.emailLabel)}" aria-label="${esc(x.emailLabel)}" data-contact-email><button type="button" data-contact-submit>${esc(x.button)}</button></div>${x.privacy?`<div class="dap-help-line">\u{1F512} ${esc(x.privacy)}</div>`:''}`;
   if(m.type==='faq_menu')return `<h4>\u2753 ${esc(x.title)}</h4>${x.intro?`<p>${esc(x.intro)}</p>`:''}<div class="dap-guide-list">${(x.items||[]).map(i=>`<button type="button" data-faq-action="faq" data-faq-value="${esc(i.id)}"><span>\u2753</span>${esc(i.title)}</button>`).join('')}</div><div class="dap-card-actions">${actions(x.buttons)}</div>`;
   if(m.type==='product_list')return `<h4>\u{1F6CD}\uFE0F ${esc(x.title)}</h4>${x.intro?`<p>${esc(x.intro)}</p>`:''}<div class="dap-products" role="list">${(x.items||[]).map(p=>`<a class="dap-product" role="listitem" href="${esc(safeUrl(p.url))}" aria-label="${esc(p.name)}"><span class="dap-product-media">${p.image?`<img src="${esc(safeUrl(p.image))}" alt="" loading="lazy" decoding="async" draggable="false">`:'<span class="dap-product-ph">\u{1F3AE}</span>'}</span><span class="dap-product-info"><b class="dap-product-name">${esc(p.name)}</b><em class="dap-product-price">${esc(p.price)}</em>${p.desc?`<small class="dap-product-desc">${esc(p.desc)}</small>`:''}<i class="dap-badge${p.inStock?'':' is-out'}">${esc(p.stock)}</i></span></a>`).join('')}</div>${x.help?`<div class="dap-help-line">${esc(x.help)}</div>`:''}<div class="dap-card-actions">${actions(x.buttons)}</div>${chips(x.chips)}`;
   if(m.type==='help_card'||m.type==='rate_card')return `<h4>\u2728 ${esc(x.title)}</h4>${x.intro?`<p>${esc(x.intro)}</p>`:''}<div class="dap-card-actions">${actions(x.buttons)}</div>${chips(x.chips)}`;
   if(m.type==='faq_card')return `<h4>\u2728 ${esc(x.title||'')}</h4><p>${esc(m.body||'')}</p><div class="dap-card-actions">${actions(x.buttons)}</div>${chips(x.chips)}`;
   // Unknown type — either a payload added server-side after this file was cached, or a
   // stale bundle. It used to emit a bare title plus an "Ouvrir" link to "#", which is a
   // dead end for the customer. Render whatever the payload actually carries instead.
   const head=`<h4>${esc(x.icon||'\u2728')} ${esc(x.title||'')}</h4>`;
   // The title and the body of a system payload are often the same sentence. Printing both
   // rendered every card twice over — the doubled "Commande #12157 : Completed" lines.
   const lead=x.description||x.intro||((m.body||'').trim()===String(x.title||'').trim()?'':m.body||'');
   const body=lead?`<p>${esc(lead)}</p>`:'';
   const list=(x.items||[]).length?`<div class="dap-guide-list">${x.items.map(i=>`<button type="button" data-faq-action="${esc(String(i.action||'quick'))}" data-faq-value="${esc(String(i.id||i.value||''))}"><span>${esc(i.icon||'\u2728')}</span>${esc(i.title||i.label||'')}</button>`).join('')}</div>`:'';
   const steps=(x.steps||[]).length?`<ol class="dap-steps">${x.steps.map(st=>`<li>${esc(st)}</li>`).join('')}</ol>`:'';
   const btns=(x.buttons||[]).length?actions(x.buttons):(x.url?`<a href="${esc(safeUrl(x.url))}">${esc(x.button||'Ouvrir')}</a>`:'');
   return head+body+steps+list+(btns?`<div class="dap-card-actions">${btns}</div>`:'')+chips(x.chips)}

  msgs.addEventListener('click',e=>{const retry=e.target.closest('[data-retry-message]');if(retry){e.preventDefault();retryPending(false);return}const sh=e.target.closest('[data-share-text]');if(sh){e.preventDefault();shareGuide(sh);return}const waA=e.target.closest('a[href*="wa.me"]');if(waA){e.preventDefault();openWhatsApp(waA.getAttribute('href'));return}const os=e.target.closest('[data-order-submit]');if(os){e.preventDefault();const c=os.closest('.dap-card'),n=(q('[data-order-number]',c)?.value||'').trim(),p=(q('[data-order-proof]',c)?.value||'').trim();if(!n||p.length<5){c.classList.add('dap-shake');setTimeout(()=>c.classList.remove('dap-shake'),450);return}os.disabled=true;findOrder(n,p,os);return}const cs=e.target.closest('[data-contact-submit]');if(cs){e.preventDefault();const cardEl=cs.closest('.dap-card'),n=(q('[data-contact-name]',cardEl)?.value||'').trim(),em=(q('[data-contact-email]',cardEl)?.value||'').trim();const okContact=/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(em)||(em.replace(/\D+/g,'').length>=8&&em.replace(/\D+/g,'').length<=15);if(n.length<2||!okContact){cardEl.classList.add('dap-shake');setTimeout(()=>cardEl.classList.remove('dap-shake'),450);return}cs.disabled=true;submitContact(n,em,cs);return}const b=e.target.closest('[data-faq-action]');if(!b)return;e.preventDefault();const action=b.dataset.faqAction,value=b.dataset.faqValue||'';if(action==='agent')quick('agent');else if(action==='guide')quick('guide_'+value);else if(action==='faq')quick('faq_'+value);else if(action==='rate')submitRating(value);else if(action==='quick')quick(value);else if(action==='whatsapp'){openWhatsApp(value)}else if(action==='copy'&&navigator.clipboard){navigator.clipboard.writeText(value).catch(()=>{})}});
  q('[data-end-chat]',root)?.addEventListener('click',e=>{e.preventDefault();e.stopPropagation();quick('end_chat')});
  root.addEventListener('click',e=>{if(e.target===root&&root.classList.contains('is-open'))close(e)});
  document.addEventListener('visibilitychange',()=>{if(document.hidden){stopPoll();return}if(loaded&&!poll){pollMessages()}syncPoll()});
  const themeMq=matchMedia('(prefers-color-scheme: dark)');
  function applyTheme(){const pref=store.get('dap_theme');const dark=pref?pref==='dark':themeMq.matches;root.classList.toggle('dap-theme-dark',dark);const tb=q('.dap-theme-toggle',root);if(tb)tb.textContent=dark?'\u2600\uFE0F':'\u{1F319}'}
  q('.dap-theme-toggle',root)?.addEventListener('click',e=>{e.preventDefault();e.stopPropagation();store.set('dap_theme',root.classList.contains('dap-theme-dark')?'light':'dark');applyTheme()});
  if(themeMq.addEventListener)themeMq.addEventListener('change',()=>{if(!store.get('dap_theme'))applyTheme()});else if(themeMq.addListener)themeMq.addListener(()=>{if(!store.get('dap_theme'))applyTheme()});
  // The stylesheet ends with a marker custom property. If it is absent the file never
  // applied (dropped by a critical-CSS pass, truncated by a combiner, or a stale cached
  // copy), so fetch it again on a unique URL that no cache layer can answer from a variant.
  function ensureStyles(second){
   try{
    const want=String(DSA2_DATA.cssVersion||'');
    const got=getComputedStyle(root).getPropertyValue('--dap-css-ok').trim().replace(/^["']|["']$/g,'');
    // Present AND the expected version. The old check accepted any value, so a cache layer
    // serving last release's stylesheet passed it and the widget never re-fetched.
    if(got&&(!want||got===want))return;
    if(!second){setTimeout(()=>ensureStyles(true),900);return} // async CSS may still be in flight
    const href=DSA2_DATA.css;
    if(!href||document.querySelector('link[data-dap-css="fix"]'))return;
    const l=document.createElement('link');
    l.rel='stylesheet';l.setAttribute('data-dap-css','fix');l.setAttribute('data-no-optimize','1');l.setAttribute('data-cfasync','false');
    l.href=href+(href.indexOf('?')>-1?'&':'?')+'dapfix='+Date.now();
    document.head.appendChild(l);
   }catch(e){}
  }
  ensureStyles();
  applyTheme();
  restoreDraft();
  updateNetworkState();
  sendBtn.disabled=navigator.onLine===false||!input.value.trim();translate();updateEndChat();showTab('home');
  // The inline boot loader already asked for the unread count before this file existed;
  // reuse its answer instead of spending a second request on the same number.
  const booted=window.DSA2_BOOT||{};
  root.classList.remove('dap-booting');
  if(isFloating){
   if(booted.unread>=0){unread=booted.unread;updateBadge()}
   else if(DSA2_DATA.loggedIn||document.cookie.includes('dap_has_chat=')||document.cookie.includes('dap_session=')){post({action:'dsa2_unread'}).then(j=>{unread=j.data.unread_count||0;updateBadge()}).catch(()=>{})}
   // Tapped while the runtime was still downloading: honour that tap now, and give the
   // boot loader a direct opener so a later tap never waits again.
   window.DSA2_OPEN=open;
   if(booted.open)open();
  }
  if(!isFloating){setOpenState(true)}else if(DSA2_DATA.settings?.auto_open&&!sstore.get('dap_auto_opened')){sstore.set('dap_auto_opened','1');setTimeout(open,900)}
  // Arrived from a shared guide link (?dap_guide=…): open the assistant on that guide.
  const shared=DSA2_DATA.page?.guide||'';
  if(shared&&!sstore.get('dap_shared_'+shared)){
   sstore.set('dap_shared_'+shared,'1');
   const wanted=DSA2_DATA.page?.lang;
   if(wanted&&copy[wanted]&&wanted!==lang){lang=wanted;store.set('dap_language',lang);translate()}
   setTimeout(()=>{if(isFloating)open();quick('guide_'+shared)},isFloating?700:200);
  }
 }
 // LiteSpeed/Cloudflare JS optimisation can move this file above the inline DSA2_DATA
 // block. Waiting for it (instead of throwing a ReferenceError) keeps the widget alive.
 let dapTries=0;
 const dapBoot=()=>{
  if(!window.DSA2_DATA||!DSA2_DATA.ajax){if(++dapTries<40)setTimeout(dapBoot,150);return}
  qa('[data-dap-root]').forEach(init);
 };
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',dapBoot);else dapBoot();
})();
